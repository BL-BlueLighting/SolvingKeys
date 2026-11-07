import { api } from './api.js';
import { SK } from './context.js';
import {
  createNewVault,
  CryptoDataError,
  normalizeContentKey,
  parseVaultEnvelope,
  reencryptVault,
  unlockVault,
} from './format.js';
import {
  deriveVaultKey,
  exportAesKey,
  fromBase64,
  importAesKey,
  randomBytes,
  toBase64,
} from './crypto.js';
import { promptDialog } from './ui.js';

const sessionVaultKey = (userId) => `sk:vault:${userId}`;
const sessionKeysKey = (userId) => `sk:sessionKeys:${userId}`;
const localVaultKey = (userId) => `sk:localVault:${userId}`;

function safeSessionGet(key) {
  try {
    return sessionStorage.getItem(key);
  } catch {
    return null;
  }
}

function safeSessionSet(key, value) {
  try {
    sessionStorage.setItem(key, value);
  } catch {
    // sessionStorage may be unavailable in hardened browsers
  }
}

function safeSessionRemove(key) {
  try {
    sessionStorage.removeItem(key);
  } catch {
    // ignore
  }
}

function readJson(key) {
  const raw = safeSessionGet(key);
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

export function readSessionKeys(userId) {
  if (!userId) return {};
  const value = readJson(sessionKeysKey(userId));
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

export function readSessionCache(userId) {
  if (!userId) return null;
  return readJson(sessionVaultKey(userId));
}

export async function storeSessionVault(userId, { key, salt, iterations, envelope, entries }) {
  const rawKey = await exportAesKey(key);
  safeSessionSet(sessionVaultKey(userId), JSON.stringify({
    envelope,
    key: toBase64(rawKey),
    salt: toBase64(salt),
    iterations,
    entries,
  }));
}

export function clearVaultSession(userId) {
  if (!userId) return;
  safeSessionRemove(sessionVaultKey(userId));
  safeSessionRemove(sessionKeysKey(userId));
}

export function readLocalEnvelope(userId) {
  if (!userId) return '';
  try {
    return localStorage.getItem(localVaultKey(userId)) || '';
  } catch {
    return '';
  }
}

export function writeLocalEnvelope(userId, envelope) {
  if (!userId) return;
  try {
    localStorage.setItem(localVaultKey(userId), envelope);
  } catch {
    throw new Error('浏览器本地存储不可用，无法保存本地钥匙库。');
  }
}

export function removeLocalEnvelope(userId) {
  if (!userId) return;
  try {
    localStorage.removeItem(localVaultKey(userId));
  } catch {
    // ignore
  }
}

export class VaultManager {
  constructor() {
    this.userId = SK.user?.id || 0;
    this.cloudVault = !!SK.user?.cloudVault;
    this.iterations = SK.vaultIterations;
    this.envelope = '';
    this.key = null;
    this.salt = null;
    this.entries = {};
    this.unlocked = false;
    this.sourceLoaded = false;
    this.sourcePromise = null;
    this.sessionKeys = readSessionKeys(this.userId);
  }

  storageLabel() {
    return this.cloudVault ? '云端钥匙库' : '本地钥匙库';
  }

  saveSessionKeys() {
    if (!this.userId) return;
    safeSessionSet(sessionKeysKey(this.userId), JSON.stringify(this.sessionKeys));
  }

  getSessionKey(keysId) {
    return this.sessionKeys[String(keysId)] || null;
  }

  rememberSessionKey(keysId, hexKey) {
    const normalized = normalizeContentKey(hexKey);
    this.sessionKeys[String(keysId)] = normalized;
    this.saveSessionKeys();
    return normalized;
  }

  forgetSessionKey(keysId) {
    delete this.sessionKeys[String(keysId)];
    this.saveSessionKeys();
  }

  getKey(keysId) {
    const id = String(keysId);
    if (this.unlocked && this.entries[id]) return this.entries[id];
    return this.sessionKeys[id] || null;
  }

  hasKeyStored(keysId) {
    return !!this.entries[String(keysId)];
  }

  async loadSource(force = false) {
    if (!this.userId) throw new Error('请先登录。');
    if (this.sourceLoaded && !force) {
      return this.envelope;
    }
    if (this.sourcePromise && !force) {
      return this.sourcePromise;
    }

    const task = (async () => {
      if (this.cloudVault) {
        const data = await api('vault');
        this.cloudVault = !!data.cloud_vault;
        SK.user.cloudVault = this.cloudVault;
        this.envelope = this.cloudVault
          ? String(data.envelope || '')
          : readLocalEnvelope(this.userId);
      } else {
        this.envelope = readLocalEnvelope(this.userId);
      }
      this.sourceLoaded = true;
      if (!this.unlocked && this.envelope) {
        await this.restoreSession(this.envelope);
      }
      return this.envelope;
    })();

    this.sourcePromise = task;
    try {
      return await task;
    } finally {
      this.sourcePromise = null;
    }
  }

  async restoreSession(envelope) {
    const cache = readSessionCache(this.userId);
    if (!cache || cache.envelope !== envelope || !cache.key) {
      return false;
    }
    try {
      const rawKey = fromBase64(cache.key);
      this.key = await importAesKey(rawKey, true);
      this.salt = fromBase64(cache.salt);
      this.iterations = Number(cache.iterations) || SK.vaultIterations;
      this.entries = cache.entries && typeof cache.entries === 'object' ? cache.entries : {};
      this.unlocked = true;
      return true;
    } catch {
      this.key = null;
      this.unlocked = false;
      return false;
    }
  }

  async cacheSession() {
    if (!this.unlocked || !this.key || !this.salt) return;
    await storeSessionVault(this.userId, {
      key: this.key,
      salt: this.salt,
      iterations: this.iterations,
      envelope: this.envelope,
      entries: this.entries,
    });
  }

  async unlockWithPassword(password) {
    const source = await this.loadSource(true);
    if (!source) {
      throw new CryptoDataError('尚未创建钥匙库。');
    }
    const result = await unlockVault(source, password);
    this.key = result.key;
    this.salt = result.salt;
    this.iterations = result.iterations;
    this.entries = result.entries;
    this.unlocked = true;
    await this.cacheSession();
    return true;
  }

  async setupVault() {
    let error = '';
    while (true) {
      const password = await promptDialog({
        title: '创建钥匙库',
        message: '设置一个 16 位十进制数字密码，用于加密你在本平台的钥匙库。\n'
          + '数字密码只在浏览器内使用，绝不上传；忘记后将无法恢复钥匙库中的内容密钥。\n'
          + `存储方式：${this.storageLabel()}`,
        label: '16 位数字密码',
        type: 'password',
        inputmode: 'numeric',
        maxlength: 16,
        validate: (value) => (/^\d{16}$/.test(value) ? '' : '必须正好是 16 位数字。'),
        error,
      });
      if (password === null) return false;

      const confirm = await promptDialog({
        title: '确认数字密码',
        message: '请再次输入相同的 16 位数字密码。',
        label: '再次输入',
        type: 'password',
        inputmode: 'numeric',
        maxlength: 16,
        validate: (value) => (/^\d{16}$/.test(value) ? '' : '必须正好是 16 位数字。'),
      });
      if (confirm === null) return false;
      if (confirm !== password) {
        error = '两次输入的数字密码不一致。';
        continue;
      }

      const created = await createNewVault(password, SK.vaultIterations);
      this.key = created.key;
      this.salt = created.salt;
      this.iterations = created.iterations;
      this.entries = created.entries;
      this.envelope = created.envelope;
      this.unlocked = true;
      await this.persistEnvelope(created.envelope);
      await this.cacheSession();
      return true;
    }
  }

  async ensureUnlocked() {
    if (!this.userId) throw new Error('请先登录。');
    if (this.unlocked) return true;
    const source = await this.loadSource();
    if (this.unlocked) return true;

    if (!source) {
      return this.setupVault();
    }

    let error = '';
    while (true) {
      const password = await promptDialog({
        title: '解锁钥匙库',
        message: `输入注册时设置的 16 位数字密码，以解密${this.storageLabel()}中的内容密钥。\n`
          + '数字密码不会发送到服务器。',
        label: '16 位数字密码',
        type: 'password',
        inputmode: 'numeric',
        maxlength: 16,
        validate: (value) => (/^\d{16}$/.test(value) ? '' : '必须正好是 16 位数字。'),
        error,
      });
      if (password === null) return false;
      try {
        await this.unlockWithPassword(password);
        return true;
      } catch (err) {
        if (err instanceof CryptoDataError) {
          error = '数字密码错误，或钥匙库数据已损坏。';
          continue;
        }
        throw err;
      }
    }
  }

  async persistEnvelope(envelope) {
    if (this.cloudVault) {
      await api('vault', { method: 'PUT', body: { envelope } });
    } else {
      writeLocalEnvelope(this.userId, envelope);
    }
  }

  async save() {
    if (!this.unlocked || !this.key || !this.salt) {
      throw new Error('钥匙库尚未解锁。');
    }
    const envelope = await reencryptVault(this.entries, this.key, this.salt, this.iterations);
    this.envelope = envelope;
    await this.persistEnvelope(envelope);
    await this.cacheSession();
    return envelope;
  }

  async addKey(keysId, hexKey) {
    if (!this.unlocked) throw new Error('请先解锁钥匙库。');
    const normalized = normalizeContentKey(hexKey);
    const id = String(keysId);
    if (this.entries[id] === normalized) return;
    this.entries[id] = normalized;
    await this.save();
  }

  async removeKey(keysId) {
    const id = String(keysId);
    this.forgetSessionKey(id);
    if (this.unlocked && this.entries[id]) {
      delete this.entries[id];
      await this.save();
    }
  }

  async rotateKey(keysId, hexKey) {
    if (!this.unlocked) throw new Error('请先解锁钥匙库。');
    this.entries[String(keysId)] = normalizeContentKey(hexKey);
    await this.save();
  }

  async changePassword(newPassword) {
    if (!this.unlocked || !this.key) throw new Error('请先解锁钥匙库。');
    if (!/^\d{16}$/.test(newPassword)) {
      throw new Error('数字密码必须正好是 16 位数字。');
    }
    const salt = randomBytes(16);
    const key = await deriveVaultKey(newPassword, salt, this.iterations);
    const previous = { key: this.key, salt: this.salt };
    this.key = key;
    this.salt = salt;
    try {
      await this.save();
    } catch (error) {
      this.key = previous.key;
      this.salt = previous.salt;
      throw error;
    }
  }

  async setCloud(enable) {
    if (!this.unlocked) throw new Error('请先解锁钥匙库。');
    await this.save();

    if (enable) {
      await api('vault/preference', {
        method: 'POST',
        body: { cloud_vault: true, envelope: this.envelope },
      });
      this.cloudVault = true;
      SK.user.cloudVault = true;
      removeLocalEnvelope(this.userId);
    } else {
      writeLocalEnvelope(this.userId, this.envelope);
      await api('vault/preference', {
        method: 'POST',
        body: { cloud_vault: false },
      });
      this.cloudVault = false;
      SK.user.cloudVault = false;
    }
    return this.cloudVault;
  }

  async status() {
    await this.loadSource(true);
    return {
      cloudVault: this.cloudVault,
      envelope: this.envelope,
      hasVault: this.envelope !== '',
      unlocked: this.unlocked,
      keyCount: Object.keys(this.entries).length,
    };
  }

  lock({ clearSessionKeys = true } = {}) {
    this.key = null;
    this.salt = null;
    this.entries = {};
    this.unlocked = false;
    if (this.userId) {
      safeSessionRemove(sessionVaultKey(this.userId));
      if (clearSessionKeys) {
        this.sessionKeys = {};
        safeSessionRemove(sessionKeysKey(this.userId));
      }
    }
  }

  clearSession() {
    if (!this.userId) return;
    this.lock({ clearSessionKeys: true });
  }
}

let singleton = null;

export function getVault() {
  if (!singleton) singleton = new VaultManager();
  return singleton;
}

export function vaultKeyFromEnvelope(envelope) {
  return parseVaultEnvelope(envelope);
}
