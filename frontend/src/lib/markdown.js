import MarkdownIt from 'markdown-it';
import katex from 'katex';
import DOMPurify from 'dompurify';
import 'katex/dist/katex.min.css';
import { SK, url } from './context.js';
import { api } from './api.js';

const md = new MarkdownIt({
  html: false,
  linkify: true,
  breaks: false,
  typographer: false,
});

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[character]));
}

function mathRule(state, silent) {
  const start = state.pos;
  const src = state.src;
  if (src.charCodeAt(start) !== 0x24) return false;
  const isDouble = src.charCodeAt(start + 1) === 0x24;

  if (isDouble) {
    const close = src.indexOf('$$', start + 2);
    if (close < 0) return false;
    const tex = src.slice(start + 2, close);
    if (!tex.trim()) return false;
    if (!silent) {
      const token = state.push('sk_math', '', 0);
      token.meta = { tex, display: true };
      token.content = tex;
    }
    state.pos = close + 2;
    return true;
  }

  if (src.charCodeAt(start + 1) === 0x24) return false;
  let closing = -1;
  for (let i = start + 1; i < state.posMax; i++) {
    const code = src.charCodeAt(i);
    if (code === 0x5c) {
      i += 1;
      continue;
    }
    if (code === 0x0a) break;
    if (code === 0x24) {
      closing = i;
      break;
    }
  }
  if (closing < 0) return false;
  const tex = src.slice(start + 1, closing);
  if (!tex.trim() || /^\s/.test(tex) || /\s$/.test(tex)) return false;
  if (/^\d+(?:[.,]\d+)?$/.test(tex)) return false;
  if (/\d/.test(src[closing + 1] || '')) return false;
  if (!silent) {
    const token = state.push('sk_math', '', 0);
    token.meta = { tex, display: false };
    token.content = tex;
  }
  state.pos = closing + 1;
  return true;
}

function mentionRule(state, silent) {
  const start = state.pos;
  if (state.src.charCodeAt(start) !== 0x40) return false;
  if (start > 0) {
    const previous = state.src[start - 1];
    // Do not turn an email address or URL path fragment into a mention.
    if (/[\p{L}\p{N}._%+\/=&?#-]/u.test(previous)) return false;
  }
  const match = state.src.slice(start).match(/^@([\p{L}\p{N}_-]{1,64})/u);
  if (!match) return false;
  if (!silent) {
    const token = state.push('sk_mention', '', 0);
    token.meta = { value: match[1], numeric: /^\d+$/.test(match[1]) };
    token.content = match[0];
  }
  state.pos = start + match[0].length;
  return true;
}

function keyRefRule(state, silent) {
  const start = state.pos;
  if (state.src.charCodeAt(start) !== 0x26) return false;
  if (start > 0) {
    const previous = state.src[start - 1];
    // Avoid matching URL query separators such as ?a=1&b=2.
    if (/[\p{L}\p{N}_%+\/=?#-]/u.test(previous)) return false;
  }
  const rest = state.src.slice(start);
  if (/^&(amp|lt|gt|quot|apos|nbsp|#\d+|#x[0-9a-f]+);/i.test(rest)) return false;

  const keyPattern = '([\\p{L}\\p{N}_-](?:[\\p{L}\\p{N}_.-]{0,118}[\\p{L}\\p{N}_-])?)';
  let match = rest.match(new RegExp('^&\\^(\\d{1,9})\\^' + keyPattern, 'u'));
  let line = null;
  if (match) {
    line = Number(match[1]);
  } else {
    match = rest.match(new RegExp('^&' + keyPattern, 'u'));
  }
  if (!match) return false;

  const key = match[2] ?? match[1];
  const after = rest[match[0].length] || '';
  if (after === '=' || after === '&' || after === '/' || after === '%' || after === '?') {
    return false;
  }
  if (!silent) {
    const token = state.push('sk_keyref', '', 0);
    token.meta = { key, line };
    token.content = match[0];
  }
  state.pos = start + match[0].length;
  return true;
}

md.inline.ruler.before('text', 'sk_math', mathRule);
md.inline.ruler.before('text', 'sk_mention', mentionRule);
md.inline.ruler.before('text', 'sk_keyref', keyRefRule);

md.core.ruler.push('sk_link_context', (state) => {
  for (const token of state.tokens) {
    if (token.type !== 'inline' || !Array.isArray(token.children)) continue;
    let linkDepth = 0;
    for (const child of token.children) {
      if (child.type === 'link_open') {
        linkDepth += 1;
      } else if (child.type === 'link_close') {
        linkDepth = Math.max(0, linkDepth - 1);
      } else if (child.type?.startsWith('sk_')) {
        child.meta = { ...(child.meta || {}), inLink: linkDepth > 0 };
      }
    }
  }
});

md.renderer.rules.sk_math = (tokens, index) => {
  const { tex, display } = tokens[index].meta;
  const delimiters = display ? ['$$', '$$'] : ['$', '$'];
  const sourceText = delimiters[0] + tex + delimiters[1];
  return `<span class="sk-math ${display ? 'sk-math-display' : 'sk-math-inline'}" data-sk-math data-tex="${escapeHtml(tex)}" data-display="${display ? '1' : '0'}">${escapeHtml(sourceText)}</span>`;
};

md.renderer.rules.sk_mention = (tokens, index) => {
  const { value, numeric } = tokens[index].meta;
  const attribute = numeric
    ? `data-user-id="${escapeHtml(value)}"`
    : `data-username="${escapeHtml(value)}"`;
  return `<span class="sk-mention" ${attribute}><i class="bi bi-at"></i><span class="sk-mention-label">@${escapeHtml(value)}</span></span>`;
};

md.renderer.rules.sk_keyref = (tokens, index, options, env) => {
  const { key, line, inLink } = tokens[index].meta;
  const numeric = /^\d+$/.test(key);
  const label = line ? `&^${line}^${key}` : `&${key}`;
  const lineQuery = line ? `?line=${line}` : '';
  const href = numeric ? `${env.baseUrl || ''}/keys/${key}${lineQuery}` : '#';
  const data = numeric
    ? `data-key-id="${escapeHtml(key)}"`
    : `data-key-name="${escapeHtml(key)}"`;
  const lineAttribute = line ? ` data-line="${line}"` : '';
  const icon = line ? 'bi-key-fill' : 'bi-key';
  const inner = `<i class="bi ${icon}"></i><span class="sk-keyref-label">${escapeHtml(label)}</span>`;

  if (inLink) {
    return `<span class="sk-keyref sk-keyref-inline" ${data}${lineAttribute}>${inner}</span>`;
  }
  return `<a class="sk-keyref" href="${escapeHtml(href)}" ${data}${lineAttribute}>${inner}</a>`;
};

const defaultLinkOpen = md.renderer.rules.link_open
  || ((tokens, index, options, env, self) => self.renderToken(tokens, index, options));

md.renderer.rules.link_open = (tokens, index, options, env, self) => {
  const href = tokens[index].attrGet('href') || '';
  if (/^https?:\/\//i.test(href)) {
    tokens[index].attrSet('target', '_blank');
    tokens[index].attrSet('rel', 'noopener noreferrer nofollow');
  }
  return defaultLinkOpen(tokens, index, options, env, self);
};

const defaultImage = md.renderer.rules.image
  || ((tokens, index, options, env, self) => self.renderToken(tokens, index, options));

md.renderer.rules.image = (tokens, index, options, env, self) => {
  tokens[index].attrSet('loading', 'lazy');
  tokens[index].attrSet('referrerpolicy', 'no-referrer');
  return defaultImage(tokens, index, options, env, self);
};

const sanitizeConfig = {
  USE_PROFILES: { html: true, mathMl: true, svg: true },
  ADD_ATTR: [
    'target',
    'rel',
    'loading',
    'referrerpolicy',
    'data-sk-math',
    'data-tex',
    'data-display',
    'data-user-id',
    'data-username',
    'data-key-id',
    'data-key-name',
    'data-line',
  ],
  FORBID_TAGS: ['style', 'form', 'input', 'button', 'textarea', 'select', 'option', 'iframe', 'object', 'embed'],
};

const userByIdCache = new Map();
const userByNameCache = new Map();
const keyByIdCache = new Map();
const keyByNameCache = new Map();

function normalizeName(value) {
  return String(value ?? '').toLocaleLowerCase();
}

function applyText(node, text) {
  if (node) node.textContent = text;
}

export function enhanceMath(root) {
  for (const node of root.querySelectorAll('[data-sk-math]')) {
    const tex = node.getAttribute('data-tex') || '';
    const displayMode = node.getAttribute('data-display') === '1';
    try {
      katex.render(tex, node, {
        displayMode,
        throwOnError: false,
        trust: false,
        strict: 'ignore',
        output: 'htmlAndMathml',
      });
    } catch {
      node.textContent = tex;
    }
  }
}

async function fetchMissingLookups(userIds, usernames, keyIds, keyNames) {
  if (userIds.length || usernames.length) {
    const params = new URLSearchParams();
    for (const id of userIds) params.append('ids[]', String(id));
    for (const name of usernames) params.append('names[]', name);
    try {
      const users = await api('users/lookup?' + params.toString());
      for (const user of users) {
        userByIdCache.set(Number(user.id), user);
        userByNameCache.set(normalizeName(user.username), user);
      }
    } catch {
      // leave mentions unresolved
    }
  }

  if (keyIds.length || keyNames.length) {
    const params = new URLSearchParams();
    for (const id of keyIds) params.append('ids[]', String(id));
    for (const name of keyNames) params.append('names[]', name);
    try {
      const keys = await api('keys/lookup?' + params.toString());
      for (const key of keys) {
        keyByIdCache.set(Number(key.id), key);
        keyByNameCache.set(normalizeName(key.key_name), key);
      }
    } catch {
      // leave references unresolved
    }
  }
}

export async function enhanceReferences(root) {
  const mentions = Array.from(root.querySelectorAll('.sk-mention'));
  const refs = Array.from(root.querySelectorAll('.sk-keyref'));
  if (!mentions.length && !refs.length) return;

  const missingUserIds = new Set();
  const missingUsernames = new Set();
  const missingKeyIds = new Set();
  const missingKeyNames = new Set();

  for (const node of mentions) {
    if (node.dataset.userId && !userByIdCache.has(Number(node.dataset.userId))) {
      missingUserIds.add(node.dataset.userId);
    }
    if (node.dataset.username && !userByNameCache.has(normalizeName(node.dataset.username))) {
      missingUsernames.add(node.dataset.username);
    }
  }
  for (const node of refs) {
    if (node.dataset.keyId && !keyByIdCache.has(Number(node.dataset.keyId))) {
      missingKeyIds.add(node.dataset.keyId);
    }
    if (node.dataset.keyName && !keyByNameCache.has(normalizeName(node.dataset.keyName))) {
      missingKeyNames.add(node.dataset.keyName);
    }
  }

  if (missingUserIds.size || missingUsernames.size || missingKeyIds.size || missingKeyNames.size) {
    await fetchMissingLookups(
      [...missingUserIds],
      [...missingUsernames],
      [...missingKeyIds],
      [...missingKeyNames],
    );
  }

  for (const node of mentions) {
    const label = node.querySelector('.sk-mention-label');
    let user = null;
    if (node.dataset.userId) {
      user = userByIdCache.get(Number(node.dataset.userId)) || null;
    } else if (node.dataset.username) {
      user = userByNameCache.get(normalizeName(node.dataset.username)) || null;
    }
    if (user) {
      applyText(label, '@' + user.username);
      node.title = `用户 @${user.username}`;
      node.classList.remove('sk-ref-missing');
    } else {
      node.classList.add('sk-ref-missing');
      node.title = '没有找到该用户';
    }
  }

  for (const node of refs) {
    const label = node.querySelector('.sk-keyref-label');
    const line = node.dataset.line ? Number(node.dataset.line) : 0;
    let key = null;
    if (node.dataset.keyId) {
      key = keyByIdCache.get(Number(node.dataset.keyId)) || null;
    } else if (node.dataset.keyName) {
      key = keyByNameCache.get(normalizeName(node.dataset.keyName)) || null;
    }
    if (!key) {
      node.classList.add('sk-ref-missing');
      node.title = '没有找到该 Key';
      continue;
    }
    node.classList.remove('sk-ref-missing');
    node.dataset.keyId = String(key.id);
    applyText(label, `${key.key_name}${line ? ` · 第 ${line} 行` : ''}`);
    node.title = line ? `打开 Key「${key.key_name}」第 ${line} 行` : `打开 Key「${key.key_name}」`;
    if (node.tagName === 'A') {
      node.setAttribute('href', url(`keys/${key.id}`) + (line ? `?line=${line}` : ''));
    }
  }
}

export function renderMarkdown(markdown) {
  const html = md.render(String(markdown ?? ''), { baseUrl: SK.baseUrl });
  const clean = DOMPurify.sanitize(html, sanitizeConfig);
  const template = document.createElement('template');
  template.innerHTML = clean;
  enhanceMath(template.content);
  return template.content;
}

export function renderMarkdownInto(container, markdown) {
  container.replaceChildren(renderMarkdown(markdown));
  enhanceReferences(container);
}

export function extractMentions(markdown) {
  const found = new Set();
  const tokens = md.parse(String(markdown ?? ''), {});
  const walk = (tokenList) => {
    for (const token of tokenList) {
      if (token.type === 'sk_mention' && token.meta?.value) {
        found.add(String(token.meta.value));
      }
      if (Array.isArray(token.children)) walk(token.children);
    }
  };
  walk(tokens);
  return [...found];
}
