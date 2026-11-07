import { SK } from './context.js';
import {
  aesDecrypt,
  aesEncrypt,
  bytesEqual,
  bytesToHex,
  concatBytes,
  deriveVaultKey,
  fromBase64,
  hexToBytes,
  importAesKey,
  randomBytes,
  textDecoder as decoder,
  textEncoder as encoder,
  toBase64,
} from './crypto.js';

export class CryptoDataError extends Error {
  constructor(message, code = 'crypto_data_error') {
    super(message);
    this.name = 'CryptoDataError';
    this.code = code;
  }
}

export const HEADER_VAULT = Uint8Array.from([0x12, 0xab, 0x24, 0xcd, 0x36, 0xef]);
export const HEADER_CONTENT = Uint8Array.from([0x12, 0xab, 0x25, 0xef, 0x37, 0xcd]);
export const HEADER_MESSAGING = Uint8Array.from([0x12, 0xab, 0x26, 0xef, 0x38, 0xcd]);

const VAULT_PREFIX = Uint8Array.from([0x53, 0x4b, 0x56, 0x31]); // SKV1
const DATA_PREFIX = Uint8Array.from([0x53, 0x4b, 0x44, 0x31]); // SKD1

function hasPrefix(bytes, prefix) {
  return bytes.length >= prefix.length && bytesEqual(bytes.subarray(0, prefix.length), prefix);
}

function decodeAfterHeader(bytes, headerLength) {
  try {
    return decoder.decode(bytes.subarray(headerLength));
  } catch {
    throw new CryptoDataError('数据不是有效的 UTF-8 文本。');
  }
}

function extractJson(text, open, close) {
  const start = text.indexOf(open);
  const end = text.lastIndexOf(close);
  if (start < 0 || end <= start) {
    throw new CryptoDataError('数据格式无效：找不到 JSON 内容。');
  }
  try {
    return JSON.parse(text.slice(start, end + 1));
  } catch {
    throw new CryptoDataError('数据格式无效：JSON 解析失败。');
  }
}

function normalizeVaultEntries(raw) {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
    throw new CryptoDataError('钥匙库格式无效。');
  }
  const entries = {};
  for (const [key, value] of Object.entries(raw)) {
    const id = String(key);
    if (!/^\d+$/.test(id) || !isContentKey(value)) {
      throw new CryptoDataError('钥匙库包含无效条目。');
    }
    entries[id] = String(value).toLowerCase();
  }
  return entries;
}

function normalizeSections(raw) {
  if (!Array.isArray(raw)) {
    throw new CryptoDataError('Key 内容格式无效：应为段落数组。');
  }
  return raw.map((section) => ({
    Title: typeof section?.Title === 'string' ? section.Title : '',
    Content: typeof section?.Content === 'string' ? section.Content : '',
  }));
}

function normalizeMessages(raw) {
  if (!Array.isArray(raw)) {
    throw new CryptoDataError('消息记录格式无效：应为消息数组。');
  }
  return raw.map((message) => {
    const from = Number(message?.From);
    return {
      From: Number.isFinite(from) ? Math.trunc(from) : 0,
      Content: typeof message?.Content === 'string' ? message.Content : '',
    };
  });
}

export function isContentKey(value) {
  return typeof value === 'string' && /^[0-9a-fA-F]{64}$/.test(value.trim());
}

export function normalizeContentKey(value) {
  const clean = String(value ?? '').replace(/\s+/g, '').toLowerCase();
  if (!/^[0-9a-f]{64}$/.test(clean)) {
    throw new CryptoDataError('内容密钥必须是 64 位十六进制字符（AES-256）。', 'invalid_key');
  }
  return clean;
}

export function createContentKey() {
  return bytesToHex(randomBytes(32));
}

export function buildVaultPlaintext(entries) {
  const normalized = normalizeVaultEntries(entries);
  const sorted = {};
  for (const key of Object.keys(normalized).sort((a, b) => Number(a) - Number(b))) {
    sorted[key] = normalized[key];
  }
  const text = 'SOLVINGKEYS\nKEYS MANAGER\n\n'
    + JSON.stringify(sorted, null, 4)
    + '\n\nSOVLINGKEYS\nKEYS MANAGER\n';
  return concatBytes(HEADER_VAULT, encoder.encode('\n' + text));
}

export function parseVaultPlaintext(bytes) {
  if (!hasPrefix(bytes, HEADER_VAULT)) {
    throw new CryptoDataError('钥匙库头校验失败：数字密码错误或数据损坏。', 'bad_vault_header');
  }
  const text = decodeAfterHeader(bytes, HEADER_VAULT.length).replace(/^\s+/, '');
  if (!text.includes('SOLVINGKEYS') || !text.includes('KEYS MANAGER') || !text.includes('SOVLINGKEYS')) {
    throw new CryptoDataError('钥匙库标记校验失败：数字密码错误或数据损坏。', 'bad_vault_marker');
  }
  return normalizeVaultEntries(extractJson(text, '{', '}'));
}

export function buildKeysContentPlaintext(sections) {
  const normalized = normalizeSections(sections);
  const text = 'SOLVINGKEYS\nKEYS CONTENT\n\n'
    + JSON.stringify(normalized, null, 4)
    + '\n\n';
  return concatBytes(HEADER_CONTENT, encoder.encode('\n' + text));
}

export function parseKeysContentPlaintext(bytes) {
  if (!hasPrefix(bytes, HEADER_CONTENT)) {
    throw new CryptoDataError('Key 内容头校验失败：密钥错误或数据损坏。', 'bad_content_header');
  }
  const text = decodeAfterHeader(bytes, HEADER_CONTENT.length).replace(/^\s+/, '');
  if (!text.includes('KEYS CONTENT')) {
    throw new CryptoDataError('Key 内容标记校验失败：密钥错误或数据损坏。', 'bad_content_marker');
  }
  return normalizeSections(extractJson(text, '[', ']'));
}

export function buildMessagingPlaintext(messages) {
  const normalized = normalizeMessages(messages);
  const text = 'SOLVINGKEYS\nMESSAGING CONTENT\n\n'
    + JSON.stringify(normalized, null, 4)
    + '\n\n';
  return concatBytes(HEADER_MESSAGING, encoder.encode('\n' + text));
}

export function parseMessagingPlaintext(bytes) {
  if (!hasPrefix(bytes, HEADER_MESSAGING)) {
    throw new CryptoDataError('消息记录头校验失败：密钥错误或数据损坏。', 'bad_messaging_header');
  }
  const text = decodeAfterHeader(bytes, HEADER_MESSAGING.length).replace(/^\s+/, '');
  if (!text.includes('MESSAGING CONTENT')) {
    throw new CryptoDataError('消息记录标记校验失败：密钥错误或数据损坏。', 'bad_messaging_marker');
  }
  return normalizeMessages(extractJson(text, '[', ']'));
}

export async function encryptVaultEnvelope(plaintext, key, salt, iterations) {
  const iv = randomBytes(12);
  const ciphertext = await aesEncrypt(key, iv, plaintext);
  const iterBytes = new Uint8Array(4);
  new DataView(iterBytes.buffer).setUint32(0, iterations, false);
  return toBase64(concatBytes(VAULT_PREFIX, salt, iterBytes, iv, ciphertext));
}

export function parseVaultEnvelope(envelope) {
  let bytes;
  try {
    bytes = fromBase64(envelope);
  } catch {
    throw new CryptoDataError('钥匙库数据不是有效的 Base64。');
  }
  if (!hasPrefix(bytes, VAULT_PREFIX) || bytes.length < 4 + 16 + 4 + 12 + 16) {
    throw new CryptoDataError('钥匙库数据格式无效。');
  }
  const salt = bytes.slice(4, 20);
  const iterations = new DataView(bytes.buffer, bytes.byteOffset + 20, 4).getUint32(0, false);
  if (iterations < 10000 || iterations > 2000000) {
    throw new CryptoDataError('钥匙库迭代次数异常。');
  }
  const iv = bytes.slice(24, 36);
  const ciphertext = bytes.slice(36);
  return { salt, iterations, iv, ciphertext };
}

export async function createNewVault(password, iterations = SK.vaultIterations) {
  const salt = randomBytes(16);
  const key = await deriveVaultKey(password, salt, iterations);
  const entries = {};
  const envelope = await encryptVaultEnvelope(buildVaultPlaintext(entries), key, salt, iterations);
  return { key, salt, iterations, envelope, entries };
}

export async function unlockVault(envelope, password) {
  const parsed = parseVaultEnvelope(envelope);
  const key = await deriveVaultKey(password, parsed.salt, parsed.iterations);
  let plaintext;
  try {
    plaintext = await aesDecrypt(key, parsed.iv, parsed.ciphertext);
  } catch {
    throw new CryptoDataError('数字密码错误，或钥匙库数据已损坏。', 'bad_vault_password');
  }
  const entries = parseVaultPlaintext(plaintext);
  return { key, salt: parsed.salt, iterations: parsed.iterations, entries };
}

export async function reencryptVault(entries, key, salt, iterations) {
  return encryptVaultEnvelope(buildVaultPlaintext(entries), key, salt, iterations);
}

export async function encryptData(plaintext, contentKey) {
  const rawKey = hexToBytes(normalizeContentKey(contentKey));
  if (rawKey.length !== 32) {
    throw new CryptoDataError('内容密钥长度无效。', 'invalid_key');
  }
  const key = await importAesKey(rawKey);
  const iv = randomBytes(12);
  const ciphertext = await aesEncrypt(key, iv, plaintext);
  return toBase64(concatBytes(DATA_PREFIX, iv, ciphertext));
}

export async function decryptData(envelope, contentKey) {
  let bytes;
  try {
    bytes = fromBase64(envelope);
  } catch {
    throw new CryptoDataError('加密数据不是有效的 Base64。');
  }
  if (!hasPrefix(bytes, DATA_PREFIX) || bytes.length < 4 + 12 + 16) {
    throw new CryptoDataError('加密数据格式无效。');
  }
  const rawKey = hexToBytes(normalizeContentKey(contentKey));
  const key = await importAesKey(rawKey);
  const iv = bytes.slice(4, 16);
  const ciphertext = bytes.slice(16);
  try {
    return await aesDecrypt(key, iv, ciphertext);
  } catch {
    throw new CryptoDataError('密钥错误或数据已损坏。', 'bad_content_key');
  }
}

export async function encryptKeysContent(sections, contentKey) {
  return encryptData(buildKeysContentPlaintext(sections), contentKey);
}

export async function decryptKeysContent(envelope, contentKey) {
  const plaintext = await decryptData(envelope, contentKey);
  return parseKeysContentPlaintext(plaintext);
}

export async function encryptMessaging(messages, contentKey) {
  return encryptData(buildMessagingPlaintext(messages), contentKey);
}

export async function decryptMessaging(envelope, contentKey) {
  const plaintext = await decryptData(envelope, contentKey);
  return parseMessagingPlaintext(plaintext);
}

export function emptyMessagingRecord(contentKey) {
  return encryptMessaging([], contentKey);
}

/**
 * Find the line in the concatenated Markdown content of all sections.
 * Line numbers are 1-based; sections are separated by one blank line.
 */
export function findReferencedLine(sections, lineNumber) {
  const target = Number(lineNumber);
  if (!Number.isFinite(target) || target < 1) return null;
  let cursor = 0;
  for (let i = 0; i < sections.length; i++) {
    const lines = String(sections[i].Content ?? '').split('\n');
    if (target - 1 >= cursor && target - 1 < cursor + lines.length) {
      return {
        sectionIndex: i,
        sectionTitle: sections[i].Title || `段落 ${i + 1}`,
        text: lines[target - 1 - cursor],
        line: target,
      };
    }
    cursor += lines.length;
    if (i < sections.length - 1) cursor += 1;
  }
  return null;
}
