import { api } from '../lib/api.js';
import { SK, url } from '../lib/context.js';
import { createNewVault } from '../lib/format.js';
import { setButtonBusy, toast } from '../lib/ui.js';
import { storeSessionVault, writeLocalEnvelope } from '../lib/vault.js';

const form = document.getElementById('register-form');

function showError(message) {
  const box = document.getElementById('register-error');
  if (!box) {
    toast(message, 'danger');
    return;
  }
  box.textContent = message;
  box.hidden = false;
}

function clearError() {
  const box = document.getElementById('register-error');
  if (box) {
    box.textContent = '';
    box.hidden = true;
  }
}

function numericOnly(input) {
  input.addEventListener('input', () => {
    const clean = input.value.replace(/\D+/g, '').slice(0, 16);
    if (input.value !== clean) input.value = clean;
  });
}

function initAvatarFields() {
  const mode = document.getElementById('avatar-mode');
  const qqWrap = document.getElementById('avatar-qq-wrap');
  const urlWrap = document.getElementById('avatar-url-wrap');
  if (!mode) return;
  const update = () => {
    if (qqWrap) qqWrap.hidden = mode.value !== 'qq';
    if (urlWrap) urlWrap.hidden = mode.value !== 'custom';
  };
  mode.addEventListener('change', update);
  update();
}

function avatarValue() {
  const mode = document.getElementById('avatar-mode')?.value || 'gravatar';
  if (mode === 'qq') {
    const qq = (document.getElementById('avatar-qq')?.value || '').replace(/\D+/g, '');
    return qq ? `-2:${qq}` : '-2';
  }
  if (mode === 'custom') {
    return (document.getElementById('avatar-url')?.value || '').trim();
  }
  return '-1';
}

if (form) {
  const vaultPassword = document.getElementById('vault-password');
  const vaultConfirm = document.getElementById('vault-password-confirm');
  if (vaultPassword) numericOnly(vaultPassword);
  if (vaultConfirm) numericOnly(vaultConfirm);
  initAvatarFields();

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearError();

    const username = (document.getElementById('username')?.value || '').trim();
    const email = (document.getElementById('email')?.value || '').trim();
    const password = document.getElementById('password')?.value || '';
    const passwordConfirm = document.getElementById('password-confirm')?.value || '';
    const numericPassword = vaultPassword?.value || '';
    const numericConfirm = vaultConfirm?.value || '';
    const cloudVault = !!document.getElementById('cloud-vault')?.checked;
    const avatar = avatarValue();
    const submit = document.getElementById('register-submit');

    if (!/^[\p{L}\p{N}_-]{2,32}$/u.test(username)) {
      showError('用户名需为 2 到 32 个文字、数字、下划线或连字符。');
      return;
    }
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
      showError('请输入有效的邮箱地址。');
      return;
    }
    if (password.length < 8) {
      showError('登录密码至少需要 8 个字符。');
      return;
    }
    if (password !== passwordConfirm) {
      showError('两次输入的登录密码不一致。');
      return;
    }
    if (!/^\d{16}$/.test(numericPassword)) {
      showError('钥匙库数字密码必须正好是 16 位数字。');
      return;
    }
    if (numericPassword !== numericConfirm) {
      showError('两次输入的数字密码不一致。');
      return;
    }
    if (avatar === '') {
      showError('请输入自定义头像地址。');
      return;
    }

    setButtonBusy(submit, true, '正在创建加密钥匙库…');
    try {
      const vault = await createNewVault(numericPassword, SK.vaultIterations);
      const data = await api('register', {
        method: 'POST',
        body: {
          username,
          email,
          password,
          avatar,
          cloud_vault: cloudVault,
          vault_envelope: cloudVault ? vault.envelope : '',
        },
      });

      const userId = Number(data.user.id);
      if (!cloudVault) {
        writeLocalEnvelope(userId, vault.envelope);
      }
      await storeSessionVault(userId, {
        key: vault.key,
        salt: vault.salt,
        iterations: vault.iterations,
        envelope: vault.envelope,
        entries: {},
      });

      window.location.assign(data.redirect || url(''));
    } catch (error) {
      setButtonBusy(submit, false);
      showError(error?.message || '注册失败，请稍后重试。');
    }
  });
}
