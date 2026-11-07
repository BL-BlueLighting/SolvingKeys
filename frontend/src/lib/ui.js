import { Modal, Toast } from 'bootstrap';
import { url } from './context.js';

export function el(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs || {})) {
    if (value === null || value === undefined || value === false) continue;
    if (key === 'class') node.className = value;
    else if (key === 'text') node.textContent = value;
    else if (key === 'dataset') Object.assign(node.dataset, value);
    else if (key === 'style' && typeof value === 'object') Object.assign(node.style, value);
    else if (key.startsWith('on') && typeof value === 'function') {
      node.addEventListener(key.slice(2).toLowerCase(), value);
    } else if (key === 'html') {
      node.innerHTML = value;
    } else {
      node.setAttribute(key, value === true ? '' : String(value));
    }
  }
  for (const child of children.flat()) {
    if (child === null || child === undefined || child === false) continue;
    node.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return node;
}

function toastContainer() {
  let container = document.getElementById('sk-toasts');
  if (!container) {
    container = el('div', {
      id: 'sk-toasts',
      class: 'toast-container position-fixed bottom-0 end-0 p-3',
      style: { zIndex: '2000' },
      'aria-live': 'polite',
      'aria-atomic': 'true',
    });
    document.body.append(container);
  }
  return container;
}

export function toast(message, type = 'success') {
  const palette = {
    success: 'text-bg-success',
    danger: 'text-bg-danger',
    warning: 'text-bg-warning',
    info: 'text-bg-info',
  };
  const node = el('div', {
    class: `toast align-items-center border-0 ${palette[type] || palette.info}`,
    role: 'alert',
  });
  const body = el('div', { class: 'd-flex' },
    el('div', { class: 'toast-body', text: message }),
    el('button', {
      type: 'button',
      class: 'btn-close btn-close-white me-2 m-auto',
      'data-bs-dismiss': 'toast',
      'aria-label': '关闭',
    }),
  );
  node.append(body);
  toastContainer().append(node);
  node.addEventListener('hidden.bs.toast', () => node.remove(), { once: true });
  new Toast(node, { delay: type === 'danger' ? 6500 : 4000 }).show();
}

export function confirmDialog({
  title = '请确认',
  message = '',
  confirmText = '确定',
  cancelText = '取消',
  danger = false,
} = {}) {
  return new Promise((resolve) => {
    const wrap = el('div', { class: 'modal fade', tabindex: '-1', 'aria-hidden': 'true' });
    const confirmButton = el('button', {
      type: 'button',
      class: `btn ${danger ? 'btn-danger' : 'btn-primary'}`,
      text: confirmText,
    });
    const cancelButton = el('button', {
      type: 'button',
      class: 'btn btn-secondary',
      'data-bs-dismiss': 'modal',
      text: cancelText,
    });
    const dialog = el('div', { class: 'modal-dialog modal-dialog-centered' },
      el('div', { class: 'modal-content' },
        el('div', { class: 'modal-header' },
          el('h1', { class: 'modal-title fs-5', text: title }),
          el('button', {
            type: 'button',
            class: 'btn-close',
            'data-bs-dismiss': 'modal',
            'aria-label': '关闭',
          }),
        ),
        el('div', { class: 'modal-body' },
          el('p', { class: 'mb-0 sk-pre-line', text: message }),
        ),
        el('div', { class: 'modal-footer' }, cancelButton, confirmButton),
      ),
    );
    wrap.append(dialog);
    document.body.append(wrap);
    const modal = new Modal(wrap, { backdrop: true });
    let settled = false;
    const finish = (value) => {
      if (settled) return;
      settled = true;
      modal.hide();
      resolve(value);
    };
    confirmButton.addEventListener('click', () => finish(true));
    wrap.addEventListener('hidden.bs.modal', () => {
      wrap.remove();
      if (!settled) {
        settled = true;
        resolve(false);
      }
    }, { once: true });
    modal.show();
  });
}

export function promptDialog({
  title = '请输入',
  message = '',
  label = '',
  value = '',
  placeholder = '',
  type = 'text',
  inputmode = '',
  maxlength = 0,
  autocomplete = 'off',
  validate = null,
  error = '',
  trim = true,
  confirmText = '确定',
  cancelText = '取消',
} = {}) {
  return new Promise((resolve) => {
    const input = el('input', {
      type,
      class: 'form-control',
      value,
      placeholder,
      autocomplete,
      ...(inputmode ? { inputmode } : {}),
      ...(maxlength ? { maxlength: String(maxlength) } : {}),
    });
    const errorNode = el('div', { class: 'invalid-feedback d-block sk-pre-line', text: error });
    errorNode.hidden = !error;
    const form = el('form', { novalidate: true },
      ...(message ? [el('p', { class: 'text-secondary sk-pre-line', text: message })] : []),
      el('div', { class: 'mb-2' },
        ...(label ? [el('label', { class: 'form-label', text: label })] : []),
        input,
        errorNode,
      ),
    );
    const wrap = el('div', { class: 'modal fade', tabindex: '-1', 'aria-hidden': 'true' });
    const submitButton = el('button', { type: 'submit', class: 'btn btn-primary', text: confirmText });
    const cancelButton = el('button', {
      type: 'button',
      class: 'btn btn-secondary',
      'data-bs-dismiss': 'modal',
      text: cancelText,
    });
    form.append(el('div', { class: 'modal-footer px-0 pb-0' }, cancelButton, submitButton));
    const dialog = el('div', { class: 'modal-dialog modal-dialog-centered' },
      el('div', { class: 'modal-content' },
        el('div', { class: 'modal-header' },
          el('h1', { class: 'modal-title fs-5', text: title }),
          el('button', {
            type: 'button',
            class: 'btn-close',
            'data-bs-dismiss': 'modal',
            'aria-label': '关闭',
          }),
        ),
        el('div', { class: 'modal-body' }, form),
      ),
    );
    wrap.append(dialog);
    document.body.append(wrap);
    const modal = new Modal(wrap, { backdrop: true });
    let settled = false;
    const finish = (result) => {
      if (settled) return;
      settled = true;
      modal.hide();
      resolve(result);
    };
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const raw = input.value;
      const result = trim ? raw.trim() : raw;
      const validationError = typeof validate === 'function' ? validate(result) : '';
      if (validationError) {
        errorNode.textContent = validationError;
        errorNode.hidden = false;
        input.classList.add('is-invalid');
        input.focus();
        return;
      }
      finish(result);
    });
    wrap.addEventListener('shown.bs.modal', () => {
      input.focus();
      if (input.value) input.select();
    }, { once: true });
    wrap.addEventListener('hidden.bs.modal', () => {
      wrap.remove();
      if (!settled) {
        settled = true;
        resolve(null);
      }
    }, { once: true });
    modal.show();
  });
}

export async function copyText(value) {
  const text = String(value ?? '');
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch {
      // fall through to the legacy path
    }
  }
  const textarea = el('textarea', { class: 'position-fixed opacity-0', readonly: true });
  textarea.value = text;
  textarea.style.left = '-9999px';
  document.body.append(textarea);
  textarea.select();
  let ok = false;
  try {
    ok = document.execCommand('copy');
  } catch {
    ok = false;
  }
  textarea.remove();
  return ok;
}

export function downloadText(filename, text) {
  const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
  const link = el('a', { href: URL.createObjectURL(blob), download: filename });
  document.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(link.href), 1000);
}

export function formatDate(iso) {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleString('zh-CN', { hour12: false });
}

export function avatarImage(user, size = 48) {
  const image = el('img', {
    src: user?.avatar_url || '',
    alt: user?.username ? `${user.username} 的头像` : '用户头像',
    class: 'rounded-circle sk-avatar',
    width: String(size),
    height: String(size),
    loading: 'lazy',
    referrerpolicy: 'no-referrer',
  });
  image.addEventListener('error', () => {
    if (image.dataset.fallbackApplied === '1') return;
    image.dataset.fallbackApplied = '1';
    image.src = url('assets/default-avatar.svg');
  });
  return image;
}

export function setButtonBusy(button, busy, busyText = '处理中…') {
  if (!button) return;
  if (busy) {
    if (!button.dataset.originalHtml) {
      button.dataset.originalHtml = button.innerHTML;
    }
    button.disabled = true;
    button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${busyText}`;
  } else {
    button.disabled = false;
    if (button.dataset.originalHtml) {
      button.innerHTML = button.dataset.originalHtml;
      delete button.dataset.originalHtml;
    }
  }
}
