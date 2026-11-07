import { Modal } from 'bootstrap';
import { api } from '../lib/api.js';
import { url } from '../lib/context.js';
import { createContentKey, encryptKeysContent } from '../lib/format.js';
import { copyText, downloadText, setButtonBusy, toast } from '../lib/ui.js';
import { getVault } from '../lib/vault.js';

const form = document.getElementById('key-create-form');

function showError(message) {
  const box = document.getElementById('key-create-error');
  if (!box) {
    toast(message, 'danger');
    return;
  }
  box.textContent = message;
  box.hidden = false;
}

function clearError() {
  const box = document.getElementById('key-create-error');
  if (box) {
    box.textContent = '';
    box.hidden = true;
  }
}

if (form) {
  const submit = document.getElementById('key-create-submit');
  const modalNode = document.getElementById('key-created-modal');
  const modal = modalNode ? new Modal(modalNode, { backdrop: 'static', keyboard: false }) : null;
  const keyValue = document.getElementById('created-key-value');
  const saveState = document.getElementById('created-key-save-state');
  const continueLink = document.getElementById('continue-to-edit');
  const saveButton = document.getElementById('save-created-key');
  let createdKey = '';
  let createdId = 0;
  let createdName = '';
  let savedToVault = false;

  const refreshSaveState = () => {
    if (!saveState) return;
    saveState.textContent = savedToVault
      ? '已保存到钥匙库，下次打开无需再次输入密钥。'
      : '当前仅保存在本标签页会话中。请立即备份，或点击「保存到钥匙库」。';
    saveState.className = savedToVault ? 'sk-tag sk-tag-success' : 'sk-tag sk-tag-danger';
    if (saveButton) {
      saveButton.disabled = savedToVault;
      saveButton.textContent = savedToVault ? '已保存到钥匙库' : '保存到钥匙库';
    }
  };

  const persistToVault = async () => {
    if (!createdKey || !createdId || savedToVault) return;
    const vault = getVault();
    try {
      const unlocked = await vault.ensureUnlocked();
      if (!unlocked) {
        toast('未解锁钥匙库，密钥仍只保存在本标签页会话中。', 'warning');
        return;
      }
      vault.rememberSessionKey(createdId, createdKey);
      await vault.addKey(createdId, createdKey);
      savedToVault = true;
      refreshSaveState();
      toast('密钥已保存到钥匙库。', 'success');
    } catch (error) {
      toast(error?.message || '保存到钥匙库失败，请确保已经备份了密钥。', 'danger');
    }
  };

  document.getElementById('copy-created-key')?.addEventListener('click', async () => {
    if (!createdKey) return;
    const ok = await copyText(createdKey);
    toast(ok ? '密钥已复制。' : '复制失败，请手动选择复制。', ok ? 'success' : 'danger');
  });

  document.getElementById('download-created-key')?.addEventListener('click', () => {
    if (!createdKey) return;
    const text = [
      'SolvingKeys 内容密钥',
      `Key: ${createdName} (#${createdId})`,
      `密钥: ${createdKey}`,
      '',
      '警告：保存后请妥善保管。此密钥是解密该 Key 内容的唯一凭证，',
      '服务器与管理员都无法恢复它。',
      '',
    ].join('\n');
    downloadText(`SolvingKeys-Key-${createdId}.txt`, text);
  });

  saveButton?.addEventListener('click', persistToVault);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearError();
    const nameInput = document.getElementById('key-name');
    const name = (nameInput?.value || '').trim();
    if (name.length < 1 || name.length > 120) {
      showError('Key 名称长度需为 1 到 120 个字符。');
      return;
    }

    setButtonBusy(submit, true, '正在生成 AES-256 密钥…');
    try {
      const keyHex = createContentKey();
      const sections = [{ Title: '未命名', Content: '' }];
      const content = await encryptKeysContent(sections, keyHex);
      const data = await api('keys', {
        method: 'POST',
        body: { key_name: name, content },
      });

      createdId = Number(data.id);
      createdName = data.key_name;
      createdKey = keyHex;

      const vault = getVault();
      try {
        await vault.loadSource();
      } catch {
        // The modal still lets the user save or copy the key.
      }
      if (vault.unlocked) {
        try {
          await vault.addKey(createdId, createdKey);
          savedToVault = true;
        } catch (error) {
          savedToVault = false;
          toast(error?.message || '保存到钥匙库失败，请手动备份密钥。', 'danger');
        }
      }
      if (!savedToVault) {
        vault.rememberSessionKey(createdId, createdKey);
      }

      if (keyValue) keyValue.textContent = createdKey;
      if (continueLink) continueLink.setAttribute('href', url(`keys/${createdId}/edit`));
      refreshSaveState();
      setButtonBusy(submit, false);
      modal?.show();
    } catch (error) {
      setButtonBusy(submit, false);
      showError(error?.message || '创建 Key 失败，请稍后重试。');
    }
  });
}
