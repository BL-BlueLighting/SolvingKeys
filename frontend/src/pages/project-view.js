import { api, ApiError } from '../lib/api.js';
import { SK, url } from '../lib/context.js';
import {
  decryptKeysContent,
  decryptMessaging,
  encryptMessaging,
  isContentKey,
  normalizeContentKey,
} from '../lib/format.js';
import { renderMarkdown, enhanceReferences, extractMentions } from '../lib/markdown.js';
import {
  avatarImage,
  confirmDialog,
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

const app = document.getElementById('project-app');
const projectId = app ? Number(app.dataset.projectId) : 0;

if (app && projectId) {
  const vault = getVault();
  const lockPanel = document.getElementById('chat-lock');
  const noKeyPanel = document.getElementById('chat-no-key');
  const chatPanel = document.getElementById('chat-panel');
  const messagesRoot = document.getElementById('chat-messages');
  const chatEmpty = document.getElementById('chat-empty');
  const chatError = document.getElementById('chat-lock-error');
  const chatKeyInput = document.getElementById('chat-key-input');
  const chatKeySubmit = document.getElementById('chat-key-submit');
  const chatUnlockVault = document.getElementById('chat-unlock-vault');
  const chatKeyName = document.getElementById('chat-key-name');
  const messagingForm = document.getElementById('messaging-key-form');
  const messagingSelect = document.getElementById('messaging-key-select');
  const messagingError = document.getElementById('messaging-key-error');
  const composer = document.getElementById('chat-composer');
  const composerMount = document.getElementById('chat-editor-mount');
  const composerModes = document.getElementById('chat-editor-modes');
  const sendButton = document.getElementById('chat-send');
  const composerError = document.getElementById('chat-composer-error');
  const readOnlyNotice = document.getElementById('chat-readonly');
  const loadingNode = document.getElementById('chat-loading');
  const chatTitle = document.getElementById('chat-title');

  let project = null;
  let messagingKey = null;
  let messages = [];
  let version = 0;
  let mode = getPreferredMode();
  let editor = null;
  let editorLoading = false;
  let editorSeq = 0;
  let sending = false;
  let pollTimer = null;
  let lastRenderedCount = 0;
  const senderCache = new Map();

  const setChatError = (message = '') => {
    if (!chatError) return;
    chatError.textContent = message;
    chatError.hidden = message === '';
  };

  const setMessagingError = (message = '') => {
    if (!messagingError) return;
    messagingError.textContent = message;
    messagingError.hidden = message === '';
  };

  const setComposerError = (message = '') => {
    if (!composerError) return;
    composerError.textContent = message;
    composerError.hidden = message === '';
  };

  const showOnly = (which) => {
    if (lockPanel) lockPanel.hidden = which !== 'lock';
    if (noKeyPanel) noKeyPanel.hidden = which !== 'none';
    if (chatPanel) chatPanel.hidden = which !== 'chat';
    if (loadingNode) loadingNode.hidden = which !== 'loading';
  };

  const updateKeyMeta = () => {
    if (!project) return;
    const name = project.messaging_key_name || '（未设置）';
    if (chatKeyName) {
      chatKeyName.replaceChildren();
      if (project.messaging_key_id) {
        const link = el('a', {
          href: url(`keys/${project.messaging_key_id}`),
          text: name,
        });
        chatKeyName.append(link);
      } else {
        chatKeyName.textContent = name;
      }
    }
    if (chatTitle) {
      chatTitle.textContent = project.messaging_key_id ? `聊天 · 消息密钥：${name}` : '聊天';
    }
    if (messagingSelect) {
      const current = project.messaging_key_id ? String(project.messaging_key_id) : '';
      if (current) messagingSelect.value = current;
    }
  };

  const resolveSenders = async () => {
    if (!project) return;
    for (const member of project.members || []) {
      senderCache.set(Number(member.id), member);
    }
    const missing = new Set();
    for (const message of messages) {
      const id = Number(message.From);
      if (id > 0 && !senderCache.has(id)) missing.add(id);
    }
    if (!missing.size) return;
    const params = new URLSearchParams();
    for (const id of missing) params.append('ids[]', String(id));
    try {
      const users = await api('users/lookup?' + params.toString());
      for (const user of users) senderCache.set(Number(user.id), user);
    } catch {
      // ignore, fall back to placeholders
    }
  };

  const fallbackAvatar = (label) => el('span', {
    class: 'rounded-circle sk-avatar d-inline-flex align-items-center justify-content-center',
    style: {
      width: '36px',
      height: '36px',
      fontSize: '0.85rem',
      fontWeight: '700',
      background: 'var(--sk-surface-3)',
      color: 'var(--sk-muted)',
    },
    text: String(label || '?').slice(0, 1).toUpperCase(),
  });

  const renderMessages = ({ scroll = false } = {}) => {
    if (!messagesRoot) return;
    const list = messagesRoot;
    const nearBottom = (list.scrollHeight - list.scrollTop - list.clientHeight) < 140;
    list.replaceChildren();
    for (const message of messages) {
      const sender = senderCache.get(Number(message.From));
      const isSelf = Number(message.From) === Number(SK.user?.id);
      const avatar = sender ? avatarImage(sender, 38) : fallbackAvatar(message.From || '?');
      const name = sender?.username || (Number(message.From) ? `用户 #${message.From}` : '未知用户');
      const body = el('div', { class: 'sk-markdown' });
      body.append(renderMarkdown(message.Content || ''));
      const bubble = el('div', { class: 'sk-chat-bubble' }, body);
      const main = el('div', { class: 'sk-chat-main' },
        el('div', { class: 'sk-chat-meta' }, el('span', { text: name })),
        bubble,
      );
      list.append(el('div', { class: `sk-chat-message ${isSelf ? 'is-self' : ''}` }, avatar, main));
    }
    if (chatEmpty) chatEmpty.hidden = messages.length > 0;
    enhanceReferences(list);
    if (scroll || nearBottom || messages.length > lastRenderedCount) {
      list.scrollTop = list.scrollHeight;
    }
    lastRenderedCount = messages.length;
  };

  const buildComposerModes = () => {
    if (!composerModes) return;
    composerModes.replaceChildren();
    for (const entry of EDITOR_MODES) {
      composerModes.append(el('button', {
        type: 'button',
        class: `btn btn-sm btn-outline-secondary ${entry.id === mode ? 'active' : ''}`,
        title: entry.hint,
        text: entry.label,
        onclick: () => switchComposerMode(entry.id),
      }));
    }
  };

  const destroyComposerEditor = async () => {
    if (!editor) return;
    const old = editor;
    editor = null;
    try {
      await old.destroy();
    } catch {
      // ignore
    }
  };

  const createComposerEditor = async (initial = '') => {
    if (!composerMount || !project?.can_write) return;
    const sequence = ++editorSeq;
    editorLoading = true;
    if (sendButton) sendButton.disabled = true;
    if (composerModes) {
      for (const node of composerModes.querySelectorAll('button')) node.disabled = true;
    }
    await destroyComposerEditor();
    composerMount.replaceChildren(el('div', { class: 'text-center text-secondary py-4' },
      el('span', { class: 'spinner-border spinner-border-sm me-2' }),
      '正在加载编辑器…',
    ));
    try {
      const mount = document.createElement('div');
      composerMount.replaceChildren(mount);
      const created = await createEditor(mode, mount, initial, {
        compact: true,
        onInput: () => {},
        placeholder: '输入消息… Ctrl/⌘ + Enter 发送',
      });
      if (sequence !== editorSeq) {
        await created.destroy();
        return;
      }
      editor = created;
    } catch (error) {
      composerMount.replaceChildren(el('div', { class: 'sk-callout sk-callout-warning' },
        `编辑器加载失败：${error?.message || error}`,
      ));
      toast(error?.message || '聊天编辑器加载失败。', 'danger');
    } finally {
      if (sequence === editorSeq) {
        editorLoading = false;
        if (sendButton) sendButton.disabled = false;
        if (composerModes) {
          for (const node of composerModes.querySelectorAll('button')) node.disabled = false;
        }
      }
    }
  };

  const getDraft = async () => {
    if (!editor) return '';
    try {
      return await editor.getMarkdown();
    } catch {
      return '';
    }
  };

  const switchComposerMode = async (next) => {
    if (editorLoading || next === mode) return;
    const draft = await getDraft();
    mode = next;
    setPreferredMode(mode);
    buildComposerModes();
    await createComposerEditor(draft);
  };

  const enterChat = async (keyHex, { silent = false } = {}) => {
    if (!project?.messaging_key_id) {
      initChat();
      return false;
    }
    const normalized = normalizeContentKey(keyHex);
    let decrypted = [];
    if (project.messaging_record) {
      try {
        decrypted = await decryptMessaging(project.messaging_record, normalized);
      } catch (error) {
        if (!silent) {
          setChatError(error?.message || '消息密钥错误，或聊天记录已损坏。');
        }
        return false;
      }
    }
    messagingKey = normalized;
    messages = decrypted;
    version = Number(project.messaging_version || 0);
    vault.rememberSessionKey(project.messaging_key_id, normalized);
    if (vault.unlocked) {
      try {
        await vault.addKey(project.messaging_key_id, normalized);
      } catch (error) {
        if (!silent) toast(error?.message || '消息密钥未能保存到钥匙库。', 'warning');
      }
    }
    setChatError('');
    showOnly('chat');
    if (readOnlyNotice) readOnlyNotice.hidden = !!project.can_write;
    if (composer) composer.hidden = !project.can_write;
    if (chatEmpty) chatEmpty.hidden = true;
    await resolveSenders();
    renderMessages({ scroll: true });
    if (project.can_write && !editor) {
      buildComposerModes();
      await createComposerEditor('');
    }
    return true;
  };

  const initChat = () => {
    if (!project) return;
    updateKeyMeta();
    if (!project.messaging_key_id) {
      showOnly('none');
      if (composer) composer.hidden = true;
      if (readOnlyNotice) readOnlyNotice.hidden = true;
      destroyComposerEditor();
      return;
    }
    const cached = vault.getKey(project.messaging_key_id);
    if (cached) {
      enterChat(cached, { silent: true }).then((ok) => {
        if (!ok) {
          setChatError('钥匙库中的消息密钥无法解密当前聊天记录（可能已更换）。请输入新的消息密钥。');
          showOnly('lock');
        }
      });
      return;
    }
    setChatError('');
    showOnly('lock');
    if (composer) composer.hidden = true;
  };

  const saveProjectData = (data) => {
    const previousKeyId = project?.messaging_key_id;
    project = data;
    updateKeyMeta();
    if (previousKeyId !== project.messaging_key_id) {
      messagingKey = null;
      editorSeq += 1;
      destroyComposerEditor();
    }
  };

  const refreshProject = async ({ silent = true } = {}) => {
    try {
      const data = await api(`projects/${projectId}`);
      const keyChanged = project && Number(project.messaging_key_id || 0) !== Number(data.messaging_key_id || 0);
      saveProjectData(data);
      if (composer) composer.hidden = !project.can_write;
      if (readOnlyNotice) readOnlyNotice.hidden = !!project.can_write;
      if (keyChanged) {
        initChat();
        return data;
      }
      if (messagingKey && Number(data.messaging_version) !== version) {
        let nextMessages;
        try {
          nextMessages = await decryptMessaging(data.messaging_record || '', messagingKey);
        } catch (error) {
          setChatError('聊天记录已变化，但当前消息密钥无法解密。请重新输入消息密钥。');
          showOnly('lock');
          return data;
        }
        messages = nextMessages;
        version = Number(data.messaging_version);
        await resolveSenders();
        renderMessages();
      } else if (!messagingKey && data.messaging_key_id) {
        const cached = vault.getKey(data.messaging_key_id);
        if (cached) {
          await enterChat(cached, { silent: true });
        } else {
          initChat();
        }
      }
      return data;
    } catch (error) {
      if (!silent) throw error;
      if (error instanceof ApiError && (error.status === 403 || error.status === 404)) {
        window.clearInterval(pollTimer);
        showOnly('none');
        if (noKeyPanel) {
          noKeyPanel.replaceChildren(el('div', { class: 'sk-callout sk-callout-warning' },
            '你已无法访问此项目的聊天。',
          ));
        }
      }
      return project;
    }
  };

  const sendMessage = async () => {
    if (sending || editorLoading || !project?.can_write || !messagingKey) return;
    setComposerError('');
    const draft = (await getDraft()).trim();
    if (!draft) {
      setComposerError('消息不能为空。');
      return;
    }
    sending = true;
    setButtonBusy(sendButton, true, '发送中…');
    const newMessage = { From: Number(SK.user.id), Content: draft };

    try {
      let baseMessages = [...messages];
      let baseVersion = version;
      let sent = false;
      let attempts = 0;
      while (attempts < 4 && !sent) {
        const record = await encryptMessaging([...baseMessages, newMessage], messagingKey);
        try {
          const result = await api(`projects/${projectId}/messages`, {
            method: 'PUT',
            body: { record, version: baseVersion },
          });
          messages = [...baseMessages, newMessage];
          version = Number(result.messaging_version);
          sent = true;
        } catch (error) {
          if (error instanceof ApiError && error.code === 'version_conflict') {
            const extra = error.extra || {};
            if (Number(extra.messaging_key_id || 0) !== Number(project.messaging_key_id || 0)) {
              setComposerError('项目消息密钥已被更换，请重新载入后再发送。');
              await refreshProject({ silent: false });
              break;
            }
            try {
              baseMessages = await decryptMessaging(extra.messaging_record || '', messagingKey);
            } catch {
              setComposerError('聊天记录已变化且无法解密，请重新输入消息密钥。');
              showOnly('lock');
              break;
            }
            const last = baseMessages[baseMessages.length - 1];
            if (last && Number(last.From) === newMessage.From && last.Content === newMessage.Content) {
              // A previous attempt may have reached the server despite a network error.
              messages = baseMessages;
              version = Number(extra.messaging_version || 0);
              sent = true;
              break;
            }
            baseVersion = Number(extra.messaging_version || 0);
            attempts += 1;
            continue;
          }
          throw error;
        }
      }
      if (!sent) {
        if (!composerError?.textContent) {
          setComposerError('发送冲突次数过多，请刷新页面后重试。');
        }
        return;
      }

      await refreshProject({ silent: true }).catch(() => {});
      await resolveSenders();
      renderMessages({ scroll: true });
      await createComposerEditor('');
      const mentions = extractMentions(draft);
      if (mentions.length) {
        api('notifications/mentions', {
          method: 'POST',
          body: { subject_type: 'project', subject_id: projectId, mentions },
        }).catch(() => {
          toast('消息已发送，但部分 @ 提醒发送失败。', 'warning');
        });
      }
    } catch (error) {
      setComposerError(error?.message || '发送消息失败。');
    } finally {
      sending = false;
      setButtonBusy(sendButton, false);
    }
  };

  sendButton?.addEventListener('click', sendMessage);

  document.addEventListener('keydown', (event) => {
    if (!(event.ctrlKey || event.metaKey) || event.key !== 'Enter') return;
    if (!composer?.contains(document.activeElement) && document.activeElement !== composerMount) return;
    event.preventDefault();
    sendMessage();
  });

  chatKeySubmit?.addEventListener('click', async () => {
    if (!project?.messaging_key_id) return;
    const value = chatKeyInput?.value || '';
    if (!isContentKey(value)) {
      setChatError('消息密钥必须是 64 位十六进制字符。');
      chatKeyInput?.focus();
      return;
    }
    setButtonBusy(chatKeySubmit, true, '验证中…');
    try {
      if (await enterChat(value)) {
        setChatError('');
        if (chatKeyInput) chatKeyInput.value = '';
      }
    } finally {
      setButtonBusy(chatKeySubmit, false);
    }
  });

  chatKeyInput?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      chatKeySubmit?.click();
    }
  });

  chatUnlockVault?.addEventListener('click', async () => {
    try {
      setChatError('');
      const ok = await vault.ensureUnlocked();
      if (!ok) return;
      const candidate = vault.getKey(project.messaging_key_id);
      if (candidate) {
        if (await enterChat(candidate)) return;
      } else {
        setChatError('钥匙库中没有此项目的消息密钥，请在下方输入。');
      }
      chatKeyInput?.focus();
    } catch (error) {
      setChatError(error?.message || '解锁钥匙库失败。');
    }
  });

  const askAndVerifyKey = async ({ title, message, verify }) => {
    let error = '';
    while (true) {
      const value = await promptDialog({
        title,
        message,
        label: '内容密钥（64 位十六进制）',
        maxlength: 64,
        validate: (input) => (isContentKey(input) ? '' : '请输入 64 位十六进制密钥。'),
        error,
      });
      if (value === null) return null;
      const normalized = normalizeContentKey(value);
      try {
        await verify(normalized);
        return normalized;
      } catch (err) {
        error = err?.message || '无法用这个密钥验证，请检查后重试。';
      }
    }
  };

  const acquireProjectKey = async (keyId) => {
    const keyData = await api(`keys/${keyId}`);
    let keyHex = vault.getKey(keyId);
    if (keyHex) {
      try {
        await decryptKeysContent(keyData.content, keyHex);
        return { keyData, keyHex };
      } catch {
        keyHex = null;
      }
    }
    keyHex = await askAndVerifyKey({
      title: '输入 Key 内容密钥',
      message: `项目将使用 Key「${keyData.key_name}」的内容密钥加密聊天记录。请输入它的 64 位内容密钥。`,
      verify: (candidate) => decryptKeysContent(keyData.content, candidate),
    });
    if (!keyHex) return null;
    vault.rememberSessionKey(keyId, keyHex);
    if (vault.unlocked) {
      try {
        await vault.addKey(keyId, keyHex);
      } catch {
        // the chat can still work with the session key
      }
    }
    return { keyData, keyHex };
  };

  messagingForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!project) return;
    setMessagingError('');
    const keyId = Number(messagingSelect?.value || 0);
    if (!keyId) {
      setMessagingError('请选择一个项目内的 Key。');
      return;
    }
    if (!project.is_owner) {
      setMessagingError('只有项目所有者可以设置消息密钥。');
      return;
    }
    const submit = document.getElementById('messaging-key-submit');
    const discardHistory = !!document.getElementById('discard-chat-history')?.checked;
    if (discardHistory) {
      const ok = await confirmDialog({
        title: '放弃历史消息？',
        message: '这会用新密钥初始化一条空的消息记录，旧聊天记录将无法恢复。\n仅当旧消息密钥确实已经丢失时使用。',
        confirmText: '我确认继续',
        danger: true,
      });
      if (!ok) return;
    }
    setButtonBusy(submit, true, '正在初始化加密聊天…');
    try {
      let decodedMessages = [];
      if (!discardHistory && project.messaging_key_id && project.messaging_record) {
        let oldKey = vault.getKey(project.messaging_key_id);
        if (oldKey) {
          try {
            decodedMessages = await decryptMessaging(project.messaging_record, oldKey);
          } catch {
            oldKey = null;
          }
        }
        if (!oldKey) {
          oldKey = await askAndVerifyKey({
            title: '验证当前消息密钥',
            message: '更换消息密钥前，需要用当前消息密钥解密现有聊天记录以重新加密。',
            verify: async (candidate) => {
              await decryptMessaging(project.messaging_record, candidate);
            },
          });
          if (!oldKey) {
            setButtonBusy(submit, false);
            return;
          }
          vault.rememberSessionKey(project.messaging_key_id, oldKey);
        }
        if (!decodedMessages.length) {
          decodedMessages = await decryptMessaging(project.messaging_record, oldKey);
        }
      }

      const acquired = await acquireProjectKey(keyId);
      if (!acquired) {
        setButtonBusy(submit, false);
        return;
      }
      const record = await encryptMessaging(decodedMessages, acquired.keyHex);
      const result = await api(`projects/${projectId}/messaging-key`, {
        method: 'POST',
        body: { key_id: keyId, record, version: project.messaging_version },
      });
      project.messaging_key_id = Number(result.messaging_key_id);
      project.messaging_version = Number(result.messaging_version);
      project.messaging_key_name = acquired.keyData.key_name;
      project.messaging_record = record;
      messagingKey = acquired.keyHex;
      messages = decodedMessages;
      version = Number(result.messaging_version);
      vault.rememberSessionKey(keyId, acquired.keyHex);
      if (vault.unlocked) {
        try {
          await vault.addKey(keyId, acquired.keyHex);
        } catch {
          // session key is enough
        }
      }
      updateKeyMeta();
      showOnly('chat');
      if (composer) composer.hidden = !project.can_write;
      if (readOnlyNotice) readOnlyNotice.hidden = !!project.can_write;
      if (chatEmpty) chatEmpty.hidden = messages.length > 0;
      await resolveSenders();
      renderMessages({ scroll: true });
      if (project.can_write) {
        buildComposerModes();
        await createComposerEditor('');
      }
      toast('消息密钥已设置，聊天记录已加密。', 'success');
    } catch (error) {
      if (error instanceof ApiError && error.code === 'version_conflict') {
        setMessagingError('项目消息已被更新，请刷新页面后重新设置。');
      } else {
        setMessagingError(error?.message || '设置消息密钥失败。');
      }
    } finally {
      setButtonBusy(submit, false);
    }
  });

  const start = async () => {
    showOnly('loading');
    try {
      project = await api(`projects/${projectId}`);
    } catch (error) {
      showOnly('none');
      if (noKeyPanel) {
        noKeyPanel.replaceChildren(el('div', { class: 'sk-callout sk-callout-warning' },
          error?.message || '无法读取项目聊天。',
        ));
      }
      return;
    }
    updateKeyMeta();
    try {
      await vault.loadSource();
    } catch {
      // The chat lock panel handles an inaccessible vault.
    }
    initChat();
    pollTimer = window.setInterval(() => {
      if (!document.hidden) refreshProject({ silent: true });
    }, 8000);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) refreshProject({ silent: true });
    });
    window.addEventListener('focus', () => refreshProject({ silent: true }));
  };

  start();
}
