import {
  confirmDialog,
  promptDialog,
  setButtonBusy,
  toast,
} from '../lib/ui.js';
import { getVault } from '../lib/vault.js';

const vault = getVault();
const modeBadge = document.getElementById('vault-mode-badge');
const statusText = document.getElementById('vault-status-text');
const keyCount = document.getElementById('vault-key-count');
const createButton = document.getElementById('vault-create');
const passwordButton = document.getElementById('vault-change-password');
const unlockButton = document.getElementById('vault-unlock');
const cloudToggle = document.getElementById('vault-cloud-toggle');
const feedback = document.getElementById('vault-feedback');

function setFeedback(message, type = 'muted') {
  if (!feedback) return;
  feedback.textContent = message || '';
  feedback.className = `small mt-2 ${type === 'danger' ? 'text-danger' : type === 'success' ? 'text-success' : 'sk-muted'}`;
  feedback.hidden = !message;
}

async function refreshStatus() {
  try {
    const status = await vault.status();
    vault.cloudVault = status.cloudVault;
    if (modeBadge) {
      modeBadge.textContent = status.cloudVault ? '云端钥匙库' : '本地钥匙库';
      modeBadge.className = `sk-tag ${status.cloudVault ? 'sk-tag-accent' : ''}`;
    }
    if (statusText) {
      if (!status.hasVault) {
        statusText.textContent = '尚未创建钥匙库。创建后，Keys 的内容密钥会加密保存在这里。';
      } else if (status.unlocked) {
        statusText.textContent = '钥匙库已在本标签页解锁。数字密码仅用于浏览器本地解密，从不上传。';
      } else {
        statusText.textContent = '钥匙库已锁定。打开 Keys 或点击下方按钮时再输入 16 位数字密码。';
      }
    }
    if (keyCount) {
      keyCount.textContent = status.unlocked
        ? `已保存 ${status.keyCount} 个密钥`
        : '解锁后可查看已保存的密钥数量';
    }
    if (createButton) createButton.hidden = status.hasVault;
    if (passwordButton) passwordButton.hidden = !status.hasVault;
    if (unlockButton) unlockButton.hidden = !status.hasVault;
    if (cloudToggle) {
      cloudToggle.checked = !!status.cloudVault;
      cloudToggle.disabled = !status.hasVault;
    }
    setFeedback('');
  } catch (error) {
    setFeedback(error?.message || '无法读取钥匙库状态。', 'danger');
  }
}

createButton?.addEventListener('click', async () => {
  setButtonBusy(createButton, true, '创建中…');
  try {
    const ok = await vault.ensureUnlocked();
    if (ok) {
      toast('钥匙库已创建。', 'success');
      await refreshStatus();
    }
  } catch (error) {
    setFeedback(error?.message || '创建钥匙库失败。', 'danger');
  } finally {
    setButtonBusy(createButton, false);
  }
});

unlockButton?.addEventListener('click', async () => {
  try {
    const ok = await vault.ensureUnlocked();
    if (ok) {
      toast('钥匙库已解锁。', 'success');
      await refreshStatus();
    }
  } catch (error) {
    setFeedback(error?.message || '解锁钥匙库失败。', 'danger');
  }
});

passwordButton?.addEventListener('click', async () => {
  try {
    const unlocked = await vault.ensureUnlocked();
    if (!unlocked) return;
    let error = '';
    while (true) {
      const next = await promptDialog({
        title: '修改钥匙库数字密码',
        message: '输入新的 16 位数字密码。修改后请立即记住新密码；忘记将无法恢复钥匙库。',
        label: '新数字密码',
        type: 'password',
        inputmode: 'numeric',
        maxlength: 16,
        validate: (value) => (/^\d{16}$/.test(value) ? '' : '必须正好是 16 位数字。'),
        error,
      });
      if (next === null) return;
      const confirm = await promptDialog({
        title: '确认新数字密码',
        message: '请再次输入新的 16 位数字密码。',
        label: '再次输入',
        type: 'password',
        inputmode: 'numeric',
        maxlength: 16,
        validate: (value) => (/^\d{16}$/.test(value) ? '' : '必须正好是 16 位数字。'),
      });
      if (confirm === null) return;
      if (confirm !== next) {
        error = '两次输入的数字密码不一致。';
        continue;
      }
      await vault.changePassword(next);
      toast('数字密码已更新，钥匙库已用新密码重新加密。', 'success');
      await refreshStatus();
      return;
    }
  } catch (err) {
    setFeedback(err?.message || '修改数字密码失败。', 'danger');
  }
});

cloudToggle?.addEventListener('change', async () => {
  const enable = !!cloudToggle.checked;
  const previous = !enable;
  const ok = await confirmDialog({
    title: enable ? '启用云端钥匙库' : '停用云端钥匙库',
    message: enable
      ? '启用后，加密后的钥匙库会保存到服务器，便于在其他设备登录后解密。数字密码与明文密钥仍然不会上传。'
      : '停用后，服务器上的加密钥匙库会被清除，仅保留在此浏览器的 localStorage 中。清理浏览器数据将导致钥匙库丢失。',
    confirmText: enable ? '启用' : '停用',
    danger: !enable,
  });
  if (!ok) {
    cloudToggle.checked = previous;
    return;
  }
  try {
    const unlocked = await vault.ensureUnlocked();
    if (!unlocked) {
      cloudToggle.checked = previous;
      return;
    }
    const cloud = await vault.setCloud(enable);
    toast(cloud ? '已启用云端钥匙库。' : '已切换为本地钥匙库。', 'success');
    await refreshStatus();
  } catch (error) {
    cloudToggle.checked = previous;
    setFeedback(error?.message || '切换存储方式失败。', 'danger');
  }
});

refreshStatus();
