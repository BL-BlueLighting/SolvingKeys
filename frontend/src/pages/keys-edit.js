import { Modal } from 'bootstrap';
import { api, ApiError } from '../lib/api.js';
import { url } from '../lib/context.js';
import {
  createContentKey,
  decryptKeysContent,
  encryptKeysContent,
  isContentKey,
  normalizeContentKey,
} from '../lib/format.js';
import { extractMentions, renderMarkdownInto } from '../lib/markdown.js';
import {
  confirmDialog,
  copyText,
  downloadText,
  el,
  promptDialog,
  setButtonBusy,
  toast,
} from '../lib/ui.js';
import { getVault } from '../lib/vault.js';
import {
  createEditor,
  EDITOR_MODES,
  getPreferredMode,
  setPreferredMode,
} from '../editors/index.js';

const app = document.getElementById('key-edit-app');
const keyId = app ? Number(app.dataset.keyId) : 0;

if (app && keyId) {
  const vault = getVault();
  const lockPanel = document.getElementById('edit-lock-panel');
  const shell = document.getElementById('edit-shell');
  const lockError = document.getElementById('edit-lock-error');
  const keyInput = document.getElementById('edit-key-input');
  const unlockButton = document.getElementById('edit-unlock-vault');
  const inputUnlockButton = document.getElementById('edit-key-submit');
  const sectionList = document.getElementById('section-list');
  const sectionTitle = document.getElementById('section-title');
  const editorMount = document.getElementById('editor-mount');
  const editorModes = document.getElementById('editor-modes');
  const saveButton = document.getElementById('save-button');
  const saveState = document.getElementById('save-state');
  const addSectionButton = document.getElementById('add-section');
  const deleteSectionButton = document.getElementById('delete-section');
  const moveUpButton = document.getElementById('move-section-up');
  const moveDownButton = document.getElementById('move-section-down');
  const previewToggle = document.getElementById('preview-toggle');
  const previewPanel = document.getElementById('preview-panel');
  const previewContent = document.getElementById('preview-content');
  const previewTitle = document.getElementById('preview-title');
  const conflictBanner = document.getElementById('conflict-banner');
  const sectionEmpty = document.getElementById('section-empty');
  const shareModalNode = document.getElementById('share-modal');
  const newKeyModalNode = document.getElementById('new-key-modal');
  const shareModal = shareModalNode ? new Modal(shareModalNode) : null;
  const newKeyModal = newKeyModalNode ? new Modal(newKeyModalNode) : null;

  let keyData = null;
  let contentKey = null;
  let sections = [];
  let activeIndex = -1;
  let editor = null;
  let editorLoading = false;
  let editorSeq = 0;
  let mode = getPreferredMode();
  let dirty = false;
  let saving = false;
  let loadedMentions = new Set();
  let shareLink = '';
  let lastNewKey = '';

  const setLockError = (message = '') => {
    if (!lockError) return;
    lockError.textContent = message;
    lockError.hidden = message === '';
  };

  const setDirty = (value) => {
    dirty = value;
    if (saveState) {
      saveState.textContent = saving
        ? '保存中…'
        : dirty
          ? '有未保存的更改'
          : '已保存';
      saveState.className = dirty ? 'sk-save-state text-warning' : 'sk-save-state sk-muted';
    }
    if (saveButton) saveButton.disabled = saving || editorLoading;
  };

  const setEditorButtonsDisabled = (disabled) => {
    for (const node of [addSectionButton, deleteSectionButton, moveUpButton, moveDownButton]) {
      if (node) node.disabled = disabled;
    }
    for (const node of document.querySelectorAll('[data-editor-mode]')) {
      node.disabled = disabled;
    }
    if (sectionTitle) sectionTitle.disabled = disabled;
  };

  const updateSaveButton = () => {
    if (saveButton) saveButton.disabled = saving || editorLoading;
  };

  const markDirty = () => {
    if (!dirty) setDirty(true);
  };

  const setContentVisible = (visible) => {
    if (lockPanel) lockPanel.hidden = visible;
    if (shell) shell.hidden = !visible;
  };

  const renderModeButtons = () => {
    if (!editorModes) return;
    editorModes.replaceChildren();
    for (const entry of EDITOR_MODES) {
      const button = el('button', {
        type: 'button',
        class: `btn btn-sm btn-outline-secondary ${entry.id === mode ? 'active' : ''}`,
        'data-editor-mode': entry.id,
        title: entry.hint,
        text: entry.label,
        onclick: () => switchMode(entry.id),
      });
      editorModes.append(button);
    }
  };

  const renderSectionList = () => {
    if (!sectionList) return;
    sectionList.replaceChildren();
    sections.forEach((section, index) => {
      const button = el('button', {
        type: 'button',
        class: `sk-section-item ${index === activeIndex ? 'active' : ''}`,
        onclick: () => selectSection(index),
      },
        el('span', { class: 'sk-section-index', text: String(index + 1) }),
        el('span', { class: 'sk-ellipsis', text: section.Title || `段落 ${index + 1}` }),
      );
      sectionList.append(button);
    });
    if (sectionEmpty) sectionEmpty.hidden = sections.length > 0;
  };

  const syncActive = async () => {
    if (editorLoading || activeIndex < 0 || !sections[activeIndex] || !editor) return;
    sections[activeIndex].Title = sectionTitle?.value ?? '';
    try {
      sections[activeIndex].Content = await editor.getMarkdown();
    } catch (error) {
      toast(error?.message || '无法读取编辑器内容。', 'danger');
    }
  };

  const destroyEditor = async () => {
    if (!editor) return;
    const old = editor;
    editor = null;
    try {
      await old.destroy();
    } catch {
      // ignore
    }
  };

  const loadEditorForActive = async () => {
    const sequence = ++editorSeq;
    editorLoading = true;
    setEditorButtonsDisabled(true);
    updateSaveButton();
    if (editorModes) {
      for (const node of editorModes.querySelectorAll('[data-editor-mode]')) {
        node.disabled = true;
      }
    }

    await destroyEditor();
    if (editorMount) editorMount.replaceChildren();

    if (activeIndex < 0 || !sections[activeIndex]) {
      editorLoading = false;
      setEditorButtonsDisabled(false);
      if (editorMount) {
        editorMount.append(el('div', { class: 'sk-empty' },
          el('i', { class: 'bi bi-plus-square' }),
          '点击「添加段落」开始书写内容。',
        ));
      }
      updateSaveButton();
      return;
    }

    if (sectionTitle) sectionTitle.value = sections[activeIndex].Title || '';
    if (editorMount) {
      editorMount.append(el('div', { class: 'text-center text-secondary py-5' },
        el('span', { class: 'spinner-border spinner-border-sm me-2' }),
        '正在加载编辑器…',
      ));
    }

    try {
      const mount = document.createElement('div');
      editorMount.replaceChildren(mount);
      const created = await createEditor(mode, mount, sections[activeIndex].Content || '', {
        compact: false,
        onInput: markDirty,
        placeholder: '使用 Markdown 书写；支持 $$LaTeX$$、@提及 与 &Key 引用。',
      });
      if (sequence !== editorSeq) {
        await created.destroy();
        return;
      }
      editor = created;
    } catch (error) {
      if (sequence === editorSeq) {
        editorMount.replaceChildren(el('div', { class: 'sk-callout sk-callout-warning' },
          `编辑器加载失败：${error?.message || error}`,
        ));
        toast(error?.message || '编辑器加载失败，请切换到其他编辑器模式。', 'danger');
      }
    } finally {
      if (sequence === editorSeq) {
        editorLoading = false;
        setEditorButtonsDisabled(false);
        updateSaveButton();
      }
    }
  };

  const selectSection = async (index) => {
    if (editorLoading || index === activeIndex) return;
    await syncActive();
    activeIndex = index;
    renderSectionList();
    await loadEditorForActive();
  };

  const switchMode = async (nextMode) => {
    if (editorLoading || nextMode === mode) return;
    await syncActive();
    mode = nextMode;
    setPreferredMode(mode);
    renderModeButtons();
    await loadEditorForActive();
  };

  addSectionButton?.addEventListener('click', async () => {
    if (editorLoading) return;
    await syncActive();
    sections.push({ Title: '未命名', Content: '' });
    activeIndex = sections.length - 1;
    renderSectionList();
    await loadEditorForActive();
    markDirty();
    sectionTitle?.focus();
  });

  deleteSectionButton?.addEventListener('click', async () => {
    if (editorLoading || activeIndex < 0) return;
    const index = activeIndex;
    const ok = await confirmDialog({
      title: '删除段落',
      message: `确定删除「${sections[index].Title || `段落 ${index + 1}`}」吗？此操作在保存前仅影响本地。`,
      confirmText: '删除',
      danger: true,
    });
    if (!ok) return;
    await syncActive();
    sections.splice(index, 1);
    activeIndex = Math.min(index, sections.length - 1);
    renderSectionList();
    await loadEditorForActive();
    markDirty();
  });

  moveUpButton?.addEventListener('click', async () => {
    if (editorLoading || activeIndex <= 0) return;
    await syncActive();
    const current = sections[activeIndex];
    sections[activeIndex] = sections[activeIndex - 1];
    sections[activeIndex - 1] = current;
    activeIndex -= 1;
    renderSectionList();
    markDirty();
  });

  moveDownButton?.addEventListener('click', async () => {
    if (editorLoading || activeIndex < 0 || activeIndex >= sections.length - 1) return;
    await syncActive();
    const current = sections[activeIndex];
    sections[activeIndex] = sections[activeIndex + 1];
    sections[activeIndex + 1] = current;
    activeIndex += 1;
    renderSectionList();
    markDirty();
  });

  sectionTitle?.addEventListener('input', markDirty);
  sectionTitle?.addEventListener('blur', () => renderSectionList());

  const collectSections = async () => {
    if (editorLoading) {
      throw new Error('编辑器仍在加载，请稍候。');
    }
    await syncActive();
    return sections.map((section, index) => ({
      Title: (section.Title || '').trim() || `段落 ${index + 1}`,
      Content: section.Content || '',
    }));
  };

  const save = async () => {
    if (saving || editorLoading || !contentKey) return;
    saving = true;
    setDirty(dirty);
    if (saveState) saveState.textContent = '保存中…';
    setButtonBusy(saveButton, true, '保存中…');
    let conflicted = false;
    try {
      const previousMentions = new Set(loadedMentions);
      const payload = await collectSections();
      const content = await encryptKeysContent(payload, contentKey);
      const result = await api(`keys/${keyId}`, {
        method: 'PUT',
        body: { content, version: keyData.version },
      });
      keyData.version = Number(result.version);
      keyData.content = content;
      sections = payload;
      activeIndex = Math.min(activeIndex, sections.length - 1);
      if (sectionTitle && activeIndex >= 0) {
        sectionTitle.value = sections[activeIndex]?.Title || '';
      }
      renderSectionList();
      const currentMentions = new Set(extractMentions(sections.map((section) => section.Content).join('\n\n')));
      const addedMentions = [...currentMentions].filter((mention) => !previousMentions.has(mention));
      loadedMentions = currentMentions;
      dirty = false;
      if (conflictBanner) conflictBanner.hidden = true;
      refreshVaultButton();
      toast('已保存并加密。', 'success');
      if (addedMentions.length) {
        api('notifications/mentions', {
          method: 'POST',
          body: { subject_type: 'key', subject_id: keyId, mentions: addedMentions },
        }).catch(() => {
          toast('内容已保存，但部分 @ 提醒发送失败。', 'warning');
        });
      }
    } catch (error) {
      if (error instanceof ApiError && error.code === 'version_conflict') {
        conflicted = true;
        if (conflictBanner) conflictBanner.hidden = false;
        toast('内容已在其他位置被修改。请复制你的更改后重新加载。', 'danger');
      } else {
        toast(error?.message || '保存失败。', 'danger');
      }
    } finally {
      saving = false;
      setButtonBusy(saveButton, false);
      updateSaveButton();
      if (conflicted) {
        if (saveState) {
          saveState.textContent = '内容已在其他位置修改';
          saveState.className = 'sk-save-state text-danger';
        }
      } else {
        setDirty(dirty);
      }
    }
  };

  saveButton?.addEventListener('click', save);

  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      save();
    }
  });

  window.addEventListener('beforeunload', (event) => {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = '';
  });

  document.getElementById('reload-conflict')?.addEventListener('click', async () => {
    if (dirty) {
      const ok = await confirmDialog({
        title: '放弃本地更改？',
        message: '重新加载会丢弃当前未保存的内容。请先复制需要保留的内容。',
        confirmText: '重新加载',
        danger: true,
      });
      if (!ok) return;
    }
    window.location.reload();
  });

  previewToggle?.addEventListener('click', async () => {
    if (!previewPanel) return;
    const showing = !previewPanel.hidden;
    if (showing) {
      previewPanel.hidden = true;
      previewToggle.classList.remove('active');
      return;
    }
    try {
      await syncActive();
      if (activeIndex < 0) return;
      if (previewTitle) previewTitle.textContent = sections[activeIndex].Title || `段落 ${activeIndex + 1}`;
      if (previewContent) {
        previewContent.replaceChildren();
        renderMarkdownInto(previewContent, sections[activeIndex].Content || '');
      }
      previewPanel.hidden = false;
      previewToggle.classList.add('active');
    } catch (error) {
      toast(error?.message || '预览失败。', 'danger');
    }
  });

  const updateShareLink = () => {
    shareLink = contentKey
      ? `${window.location.origin}${url(`keys/${keyId}`)}#key=${contentKey}`
      : '';
    const linkField = document.getElementById('share-link-value');
    const keyField = document.getElementById('share-key-value');
    if (linkField) linkField.value = shareLink;
    if (keyField) keyField.value = contentKey || '';
  };

  document.getElementById('share-key-button')?.addEventListener('click', () => {
    if (!contentKey) {
      toast('请先解锁内容。', 'warning');
      return;
    }
    updateShareLink();
    shareModal?.show();
  });

  document.getElementById('copy-key-button')?.addEventListener('click', async () => {
    if (!contentKey) return;
    const ok = await copyText(contentKey);
    toast(ok ? '内容密钥已复制。' : '复制失败。', ok ? 'success' : 'danger');
  });

  document.getElementById('copy-share-key')?.addEventListener('click', async () => {
    if (!contentKey) return;
    const ok = await copyText(contentKey);
    toast(ok ? '密钥已复制。' : '复制失败。', ok ? 'success' : 'danger');
  });

  document.getElementById('copy-share-link')?.addEventListener('click', async () => {
    if (!shareLink) return;
    const ok = await copyText(shareLink);
    toast(ok ? '分享链接已复制（链接中含密钥）。' : '复制失败。', ok ? 'success' : 'danger');
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

  const showNewKey = (newKey, message) => {
    lastNewKey = newKey;
    const field = document.getElementById('new-key-value');
    const warning = document.getElementById('new-key-warning');
    if (field) field.textContent = newKey;
    if (warning) warning.textContent = message;
    newKeyModal?.show();
  };

  document.getElementById('rotate-key-button')?.addEventListener('click', async () => {
    if (!contentKey || editorLoading) return;
    const items = keyData?.messaging_projects || [];
    let message = '轮换后旧密钥立即失效，其他用户必须获得新密钥。\n此操作不可撤销。';
    if (items.length) {
      message += '\n\n该 Key 正被以下项目用作消息密钥：\n'
        + items.map((project) => `· ${project.project_name}`).join('\n')
        + '\n轮换后需要在那些项目中重新设置消息密钥。';
    }
    const ok = await confirmDialog({
      title: '轮换内容密钥',
      message,
      confirmText: '轮换',
      danger: true,
    });
    if (!ok) return;
    const unlocked = await vault.ensureUnlocked();
    if (!unlocked) return;
    try {
      await syncActive();
      const newKey = createContentKey();
      const payload = sections.map((section, index) => ({
        Title: (section.Title || '').trim() || `段落 ${index + 1}`,
        Content: section.Content || '',
      }));
      const content = await encryptKeysContent(payload, newKey);
      const result = await api(`keys/${keyId}`, {
        method: 'PUT',
        body: { content, version: keyData.version },
      });
      keyData.version = Number(result.version);
      keyData.content = content;
      contentKey = newKey;
      vault.rememberSessionKey(keyId, newKey);
      try {
        await vault.rotateKey(keyId, newKey);
        showNewKey(newKey, '新密钥已保存到钥匙库。请将新密钥安全地分享给需要访问此 Key 的用户。');
      } catch (error) {
        showNewKey(newKey, '服务器内容已使用新密钥加密，但保存到钥匙库失败。请立即备份下面的密钥。');
        toast(error?.message || '新密钥未能保存到钥匙库。', 'danger');
      }
      updateShareLink();
      refreshVaultButton();
      dirty = false;
      setDirty(false);
      toast('内容密钥已轮换。', 'success');
    } catch (error) {
      toast(error?.message || '轮换失败。', 'danger');
    }
  });

  document.getElementById('delete-key-button')?.addEventListener('click', async () => {
    const ok = await confirmDialog({
      title: '删除 Key',
      message: '删除后无法恢复，且所有持有密钥的用户都将无法访问。确定删除吗？',
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
            + '\n请先更换这些项目的消息密钥。',
          confirmText: '知道了',
          cancelText: '关闭',
        });
      } else {
        toast(error?.message || '删除 Key 失败。', 'danger');
      }
    }
  });

  document.getElementById('rename-key-button')?.addEventListener('click', async () => {
    const name = await promptDialog({
      title: '重命名 Key',
      message: '名称不加密，会显示在链接与引用中。',
      label: 'Key 名称',
      value: keyData?.key_name || '',
      maxlength: 120,
      validate: (value) => (value ? '' : '名称不能为空。'),
    });
    if (name === null) return;
    try {
      const result = await api(`keys/${keyId}`, {
        method: 'PATCH',
        body: { key_name: name },
      });
      keyData.key_name = result.key_name;
      const heading = document.getElementById('key-name-heading');
      if (heading) heading.textContent = result.key_name;
      document.title = `${result.key_name} · SolvingKeys`;
      toast('Key 名称已更新。', 'success');
    } catch (error) {
      toast(error?.message || '重命名失败。', 'danger');
    }
  });

  document.getElementById('save-key-to-vault')?.addEventListener('click', async () => {
    if (!contentKey) return;
    try {
      const ok = await vault.ensureUnlocked();
      if (!ok) return;
      await vault.addKey(keyId, contentKey);
      refreshVaultButton();
      toast('密钥已保存到钥匙库。', 'success');
    } catch (error) {
      toast(error?.message || '保存失败。', 'danger');
    }
  });

  const refreshVaultButton = () => {
    const button = document.getElementById('save-key-to-vault');
    if (!button) return;
    button.hidden = !contentKey || vault.hasKeyStored(keyId);
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
    loadedMentions = new Set(extractMentions(sections.map((section) => section.Content).join('\n\n')));
    vault.rememberSessionKey(keyId, normalized);
    if (vault.unlocked) {
      try {
        await vault.addKey(keyId, normalized);
      } catch (error) {
        toast(error?.message || '保存到钥匙库失败，密钥仍在本页可用。', 'warning');
      }
    }
    if (fromHash) {
      history.replaceState(null, '', window.location.pathname + window.location.search);
    }
    setLockError('');
    setContentVisible(true);
    activeIndex = sections.length ? 0 : -1;
    renderModeButtons();
    renderSectionList();
    refreshVaultButton();
    await loadEditorForActive();
    setDirty(false);
    return true;
  };

  const tryCachedKey = async () => {
    const cached = vault.getKey(keyId);
    if (!cached) return false;
    const ok = await unlockWithContentKey(cached);
    if (!ok) {
      setLockError('钥匙库中的密钥无法解密此内容（可能已轮换）。请输入新的内容密钥。');
    }
    return ok;
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
      return;
    }
    const heading = document.getElementById('key-name-heading');
    if (heading) heading.textContent = keyData.key_name;
    const version = document.getElementById('key-version');
    if (version) version.textContent = String(keyData.version);
    refreshVaultButton();

    try {
      await vault.loadSource();
    } catch {
      // The lock panel handles an inaccessible vault.
    }

    const hashKey = readHashKey();
    if (hashKey) {
      const ok = await unlockWithContentKey(hashKey, { fromHash: true });
      if (ok) return;
      history.replaceState(null, '', window.location.pathname + window.location.search);
    }
    if (await tryCachedKey()) return;
    setContentVisible(false);
    refreshVaultButton();
  };

  inputUnlockButton?.addEventListener('click', async () => {
    const value = keyInput?.value || '';
    if (!isContentKey(value)) {
      setLockError('内容密钥必须是 64 位十六进制字符。');
      keyInput?.focus();
      return;
    }
    setButtonBusy(inputUnlockButton, true, '解密中…');
    const ok = await unlockWithContentKey(value);
    setButtonBusy(inputUnlockButton, false);
    if (ok && keyInput) keyInput.value = '';
  });

  keyInput?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      inputUnlockButton?.click();
    }
  });

  unlockButton?.addEventListener('click', async () => {
    try {
      setLockError('');
      const ok = await vault.ensureUnlocked();
      if (!ok) return;
      if (vault.getKey(keyId)) {
        if (await tryCachedKey()) return;
      }
      setLockError('钥匙库中没有此 Key 的密钥，请在下方直接输入内容密钥。');
      keyInput?.focus();
    } catch (error) {
      setLockError(error?.message || '解锁失败。');
    }
  });

  load();
}
