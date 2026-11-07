import { Modal } from 'bootstrap';
import { api, ApiError } from '../lib/api.js';
import { SK, url } from '../lib/context.js';
import {
  createContentKey,
  decryptKeysContent,
  encryptKeysContent,
  findReferencedLine,
  isContentKey,
  normalizeContentKey,
} from '../lib/format.js';
import { renderMarkdown, enhanceReferences } from '../lib/markdown.js';
import {
  confirmDialog,
  copyText,
  downloadText,
  el,
  formatDate,
  setButtonBusy,
  toast,
} from '../lib/ui.js';
import { getVault } from '../lib/vault.js';

const app = document.getElementById('key-view-app');
const keyId = app ? Number(app.dataset.keyId) : 0;

if (app && keyId) {
  const vault = getVault();
  const lockPanel = document.getElementById('key-lock-panel');
  const contentPanel = document.getElementById('key-content');
  const sectionsRoot = document.getElementById('key-sections');
  const lockError = document.getElementById('key-lock-error');
  const keyInput = document.getElementById('key-input');
  const keySubmit = document.getElementById('key-submit');
  const unlockVaultButton = document.getElementById('unlock-vault-button');
  const saveVaultButton = document.getElementById('save-key-to-vault');
  const saveVaultWrap = document.getElementById('key-save-vault-wrap');
  const refBanner = document.getElementById('key-ref-banner');
  const shareModalNode = document.getElementById('share-modal');
  const newKeyModalNode = document.getElementById('new-key-modal');
  const shareModal = shareModalNode ? new Modal(shareModalNode) : null;
  const newKeyModal = newKeyModalNode ? new Modal(newKeyModalNode) : null;

  let keyData = null;
  let contentKey = null;
  let sections = [];
  let shareLink = '';
  let lastNewKey = '';

  const setLockError = (message = '') => {
    if (!lockError) return;
    lockError.textContent = message;
    lockError.hidden = message === '';
  };

  const setContentVisible = (visible) => {
    if (lockPanel) lockPanel.hidden = visible;
    if (contentPanel) contentPanel.hidden = !visible;
  };

  const refreshVaultButtons = () => {
    const stored = contentKey ? vault.hasKeyStored(keyId) : false;
    if (saveVaultWrap) saveVaultWrap.hidden = !contentKey || stored;
    for (const button of document.querySelectorAll('[data-requires-key]')) {
      button.disabled = !contentKey;
    }
  };

  const updateShareLink = () => {
    shareLink = contentKey
      ? `${window.location.origin}${url(`keys/${keyId}`)}#key=${contentKey}`
      : '';
    const linkField = document.getElementById('share-link-value');
    const keyField = document.getElementById('share-key-value');
    if (linkField) linkField.value = shareLink;
    if (keyField) keyField.value = contentKey || '';
  };

  const showShareModal = () => {
    if (!contentKey) {
      toast('请先解锁内容后再分享密钥。', 'warning');
      return;
    }
    updateShareLink();
    shareModal?.show();
  };

  const showNewKeyModal = (newKey, message) => {
    lastNewKey = newKey;
    const keyField = document.getElementById('new-key-value');
    const warning = document.getElementById('new-key-warning');
    if (keyField) keyField.textContent = newKey;
    if (warning && message) warning.textContent = message;
    newKeyModal?.show();
  };

  const renderSections = () => {
    if (!sectionsRoot) return;
    sectionsRoot.replaceChildren();
    if (!sections.length) {
      sectionsRoot.append(el('div', { class: 'sk-empty' },
        el('i', { class: 'bi bi-journal-text' }),
        '此 Key 还没有任何内容。',
      ));
      return;
    }
    sections.forEach((section, index) => {
      const body = el('div', { class: 'sk-markdown' });
      body.append(renderMarkdown(section.Content));
      const card = el('section', { class: 'sk-section', id: `sk-section-${index}` },
        el('h2', { text: section.Title || `段落 ${index + 1}` }),
        body,
      );
      sectionsRoot.append(card);
    });
    enhanceReferences(sectionsRoot);
  };

  const renderLineReference = () => {
    const line = Number(new URLSearchParams(window.location.search).get('line'));
    if (!line || !refBanner) return;
    const found = findReferencedLine(sections, line);
    if (!found) {
      toast(`引用的第 ${line} 行不存在。`, 'warning');
      return;
    }
    const textNode = document.getElementById('key-ref-line-text');
    const titleNode = document.getElementById('key-ref-line-title');
    if (textNode) textNode.textContent = found.text || '（空行）';
    if (titleNode) titleNode.textContent = `来自「${found.sectionTitle}」第 ${line} 行`;
    refBanner.hidden = false;
    const target = document.getElementById(`sk-section-${found.sectionIndex}`);
    if (target) {
      window.setTimeout(() => {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        target.classList.add('sk-section-highlight');
        window.setTimeout(() => target.classList.remove('sk-section-highlight'), 2400);
      }, 80);
    }
  };

  const updateMetadata = () => {
    if (!keyData) return;
    const version = document.getElementById('key-version');
    const updated = document.getElementById('key-updated-at');
    if (version) version.textContent = String(keyData.version);
    if (updated) updated.textContent = formatDate(keyData.updated_at);
  };

  const unlockWithContentKey = async (hex, { fromHash = false } = {}) => {
    if (!keyData) return false;
    const normalized = normalizeContentKey(hex);
    let decrypted;
    try {
      decrypted = await decryptKeysContent(keyData.content, normalized);
    } catch (error) {
      setLockError(error?.message || '密钥错误或内容已损坏。');
      return false;
    }
    contentKey = normalized;
    sections = decrypted;
    vault.rememberSessionKey(keyId, normalized);
    if (vault.unlocked) {
      try {
        await vault.addKey(keyId, normalized);
      } catch (error) {
        toast(error?.message || '密钥已在本页解锁，但保存到钥匙库失败。', 'warning');
      }
    }
    if (fromHash) {
      history.replaceState(null, '', window.location.pathname + window.location.search);
    }
    setLockError('');
    setContentVisible(true);
    renderSections();
    renderLineReference();
    updateShareLink();
    refreshVaultButtons();
    return true;
  };

  const tryCachedKey = async () => {
    const cached = vault.getKey(keyId);
    if (cached) {
      const ok = await unlockWithContentKey(cached);
      if (ok) return true;
      setLockError('钥匙库中的密钥无法解密此内容（可能已被轮换）。请输入新的内容密钥。');
    }
    return false;
  };

  const readHashKey = () => {
    if (!window.location.hash) return '';
    const params = new URLSearchParams(window.location.hash.slice(1));
    const value = params.get('key');
    return value && isContentKey(value) ? value : '';
  };

  const load = async () => {
    try {
      keyData = await api(`keys/${keyId}`);
    } catch (error) {
      setLockError(error?.message || '无法读取 Key。');
      setContentVisible(false);
      return;
    }
    updateMetadata();
    refreshVaultButtons();

    try {
      await vault.loadSource();
    } catch {
      // A locked or unavailable vault is handled by the UI below.
    }

    const hashKey = readHashKey();
    if (hashKey) {
      const ok = await unlockWithContentKey(hashKey, { fromHash: true });
      if (ok) return;
      history.replaceState(null, '', window.location.pathname + window.location.search);
    }
    if (await tryCachedKey()) return;
    setContentVisible(false);
    refreshVaultButtons();
  };

  keySubmit?.addEventListener('click', async () => {
    const value = keyInput?.value || '';
    if (!isContentKey(value)) {
      setLockError('内容密钥必须是 64 位十六进制字符。');
      keyInput?.focus();
      return;
    }
    setButtonBusy(keySubmit, true, '解密中…');
    const ok = await unlockWithContentKey(value);
    setButtonBusy(keySubmit, false);
    if (ok && keyInput) keyInput.value = '';
  });

  keyInput?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      keySubmit?.click();
    }
  });

  unlockVaultButton?.addEventListener('click', async () => {
    try {
      setLockError('');
      const ok = await vault.ensureUnlocked();
      if (!ok) return;
      const candidate = vault.getKey(keyId);
      if (candidate) {
        if (await tryCachedKey()) return;
      } else {
        setLockError('钥匙库中没有此 Key 的密钥，请在下方直接输入内容密钥。');
      }
      keyInput?.focus();
    } catch (error) {
      setLockError(error?.message || '解锁钥匙库失败。');
    }
  });

  saveVaultButton?.addEventListener('click', async () => {
    if (!contentKey) return;
    try {
      const ok = await vault.ensureUnlocked();
      if (!ok) return;
      await vault.addKey(keyId, contentKey);
      refreshVaultButtons();
      toast('密钥已保存到钥匙库。', 'success');
    } catch (error) {
      toast(error?.message || '保存到钥匙库失败。', 'danger');
    }
  });

  document.getElementById('copy-key-button')?.addEventListener('click', async () => {
    if (!contentKey) {
      toast('请先解锁内容。', 'warning');
      return;
    }
    const ok = await copyText(contentKey);
    toast(ok ? '内容密钥已复制。' : '复制失败，请手动复制。', ok ? 'success' : 'danger');
  });

  document.getElementById('share-key-button')?.addEventListener('click', showShareModal);

  document.getElementById('copy-share-key')?.addEventListener('click', async () => {
    if (!contentKey) return;
    const ok = await copyText(contentKey);
    toast(ok ? '密钥已复制。' : '复制失败。', ok ? 'success' : 'danger');
  });

  document.getElementById('copy-share-link')?.addEventListener('click', async () => {
    if (!shareLink) return;
    const ok = await copyText(shareLink);
    toast(ok ? '分享链接已复制（链接中含密钥，请谨慎发送）。' : '复制失败。', ok ? 'success' : 'danger');
  });

  document.getElementById('copy-new-key')?.addEventListener('click', async () => {
    if (!lastNewKey) return;
    const ok = await copyText(lastNewKey);
    toast(ok ? '新密钥已复制。' : '复制失败。', ok ? 'success' : 'danger');
  });

  document.getElementById('download-new-key')?.addEventListener('click', () => {
    if (!lastNewKey) return;
    downloadText(`SolvingKeys-Key-${keyId}-new-key.txt`, [
      'SolvingKeys 新的内容密钥',
      `Key: ${keyData?.key_name || ''} (#${keyId})`,
      `密钥: ${lastNewKey}`,
      '',
      '旧密钥将无法再解密此内容。',
    ].join('\n'));
  });

  document.getElementById('rotate-key-button')?.addEventListener('click', async () => {
    if (!contentKey) {
      toast('请先解锁内容，再轮换密钥。', 'warning');
      return;
    }
    const messagingProjects = keyData?.messaging_projects || [];
    let message = '轮换后，旧密钥将无法解密此 Key 的内容，其他用户必须获得新密钥。\n此操作不可撤销。';
    if (messagingProjects.length) {
      message += '\n\n该 Key 正被以下项目用作消息密钥：\n'
        + messagingProjects.map((project) => `· ${project.project_name}`).join('\n')
        + '\n轮换后需要在那些项目中重新设置消息密钥并重新加密聊天记录。';
    }
    const ok = await confirmDialog({
      title: '轮换内容密钥',
      message,
      confirmText: '生成并轮换',
      danger: true,
    });
    if (!ok) return;

    const vaultOk = await vault.ensureUnlocked();
    if (!vaultOk) {
      toast('轮换密钥前必须解锁钥匙库，以便安全保存新密钥。', 'warning');
      return;
    }

    const button = document.getElementById('rotate-key-button');
    setButtonBusy(button, true, '正在轮换…');
    const newKey = createContentKey();
    try {
      const content = await encryptKeysContent(sections, newKey);
      const result = await api(`keys/${keyId}`, {
        method: 'PUT',
        body: { content, version: keyData.version },
      });
      keyData.version = Number(result.version);
      keyData.content = content;
      updateMetadata();
      contentKey = newKey;
      vault.rememberSessionKey(keyId, newKey);
      try {
        await vault.rotateKey(keyId, newKey);
        showNewKeyModal(newKey, '新密钥已保存到钥匙库。请将新密钥安全地分享给需要阅读此 Key 的用户。');
      } catch (error) {
        showNewKeyModal(newKey, '服务器内容已使用新密钥加密，但保存到钥匙库失败。请立即备份下面的密钥。');
        toast(error?.message || '新密钥未能保存到钥匙库，请立即备份。', 'danger');
      }
      updateShareLink();
      toast('内容密钥已轮换。', 'success');
    } catch (error) {
      toast(error?.message || '轮换密钥失败。', 'danger');
    } finally {
      setButtonBusy(button, false);
    }
  });

  document.getElementById('delete-key-button')?.addEventListener('click', async () => {
    const ok = await confirmDialog({
      title: '删除 Key',
      message: '删除后，所有拥有此 Key 链接的用户都将无法再访问它，且服务器上无法恢复。确定删除吗？',
      confirmText: '永久删除',
      danger: true,
    });
    if (!ok) return;
    try {
      await api(`keys/${keyId}`, { method: 'DELETE' });
      try {
        await vault.removeKey(keyId);
      } catch {
        vault.forgetSessionKey(keyId);
      }
      window.location.assign(url('keys'));
    } catch (error) {
      if (error instanceof ApiError && error.code === 'key_in_use') {
        const projects = error.extra?.projects || [];
        await confirmDialog({
          title: '无法删除',
          message: '此 Key 仍被以下项目用作消息密钥：\n'
            + projects.map((project) => `· ${project.project_name}`).join('\n')
            + '\n请先在对应项目中更换消息密钥，再删除此 Key。',
          confirmText: '知道了',
          cancelText: '关闭',
        });
      } else {
        toast(error?.message || '删除 Key 失败。', 'danger');
      }
    }
  });

  document.getElementById('lock-content-button')?.addEventListener('click', () => {
    vault.forgetSessionKey(keyId);
    contentKey = null;
    sections = [];
    if (keyData) {
      // Keep the ciphertext in memory for a re-unlock.
    }
    setContentVisible(false);
    refreshVaultButtons();
    if (refBanner) refBanner.hidden = true;
    setLockError('');
  });

  document.getElementById('remove-vault-key-button')?.addEventListener('click', async () => {
    const ok = await confirmDialog({
      title: '从钥匙库移除密钥',
      message: '将从你的钥匙库中移除此 Key 的内容密钥。之后再次阅读时需要重新输入密钥。确定移除吗？',
      confirmText: '移除',
      danger: true,
    });
    if (!ok) return;
    try {
      if (!vault.unlocked) {
        const unlocked = await vault.ensureUnlocked();
        if (!unlocked) return;
      }
      await vault.removeKey(keyId);
      contentKey = null;
      sections = [];
      setContentVisible(false);
      refreshVaultButtons();
      toast('已从钥匙库移除密钥。', 'success');
    } catch (error) {
      toast(error?.message || '移除密钥失败。', 'danger');
    }
  });

  load();
}
