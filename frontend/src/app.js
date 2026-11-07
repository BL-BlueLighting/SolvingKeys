import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import * as bootstrap from 'bootstrap';
import './styles/app.css';
import { SK, url } from './lib/context.js';
import { api } from './lib/api.js';
import { confirmDialog, toast } from './lib/ui.js';

const THEME_COOKIE = 'sk_theme';

function cookiePath() {
  return SK.baseUrl || '/';
}

function readThemeCookie() {
  const match = document.cookie.match(new RegExp('(?:^|; )' + THEME_COOKIE + '=([^;]*)'));
  return match ? decodeURIComponent(match[1]) : '';
}

function applyTheme(theme) {
  const normalized = theme === 'dark' ? 'dark' : 'light';
  document.documentElement.dataset.bsTheme = normalized;
  document.cookie = `${THEME_COOKIE}=${normalized}; path=${cookiePath()}; max-age=31536000; SameSite=Lax`;
  for (const button of document.querySelectorAll('[data-theme-toggle]')) {
    const icon = button.querySelector('i');
    if (icon) icon.className = normalized === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
    button.setAttribute('title', normalized === 'dark' ? '切换到浅色模式' : '切换到深色模式');
    button.setAttribute('aria-label', button.getAttribute('title'));
  }
}

function initTheme() {
  const stored = readThemeCookie();
  const prefersDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches;
  applyTheme(stored || (prefersDark ? 'dark' : 'light'));
  for (const button of document.querySelectorAll('[data-theme-toggle]')) {
    button.addEventListener('click', () => {
      applyTheme(document.documentElement.dataset.bsTheme === 'dark' ? 'light' : 'dark');
    });
  }
}

function initPasswordToggles() {
  for (const button of document.querySelectorAll('[data-toggle-password]')) {
    button.addEventListener('click', () => {
      const selector = button.getAttribute('data-target');
      const input = selector ? document.querySelector(selector) : null;
      if (!input) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      const icon = button.querySelector('i');
      if (icon) icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
  }
}

function initConfirmForms() {
  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.skConfirmed === '1') {
      delete form.dataset.skConfirmed;
      return;
    }
    if (!form.dataset.confirm) return;
    event.preventDefault();
    const submitter = event.submitter || null;
    const ok = await confirmDialog({
      title: form.dataset.confirmTitle || '请确认',
      message: form.dataset.confirm,
      confirmText: form.dataset.confirmButton || '确定',
      danger: form.dataset.confirmDanger !== '0',
    });
    if (!ok) return;
    form.dataset.skConfirmed = '1';
    if (submitter && typeof form.requestSubmit === 'function') {
      form.requestSubmit(submitter);
    } else if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  });
}

function clearClientVaultSessions() {
  try {
    const keys = [];
    for (let i = 0; i < sessionStorage.length; i++) {
      const key = sessionStorage.key(i);
      if (key && (key.startsWith('sk:vault:') || key.startsWith('sk:sessionKeys:'))) {
        keys.push(key);
      }
    }
    for (const key of keys) sessionStorage.removeItem(key);
  } catch {
    // ignore
  }
}

function initLogout() {
  for (const form of document.querySelectorAll('[data-logout]')) {
    form.addEventListener('submit', () => {
      clearClientVaultSessions();
    });
  }
}

function updateUnreadBadge(count) {
  const badge = document.querySelector('[data-notification-badge]');
  if (!badge) return;
  const value = Number(count) || 0;
  if (value <= 0) {
    badge.hidden = true;
    badge.textContent = '';
    return;
  }
  badge.hidden = false;
  badge.textContent = value > 99 ? '99+' : String(value);
}

async function refreshUnreadCount() {
  if (!SK.user) return;
  try {
    const data = await api('notifications/count');
    updateUnreadBadge(data.unread);
  } catch {
    // silently ignore polling errors
  }
}

function initNotificationPolling() {
  if (!SK.user) return;
  const badge = document.querySelector('[data-notification-badge]');
  if (badge) {
    updateUnreadBadge(Number(badge.dataset.count || badge.textContent || 0));
  }
  window.setInterval(() => {
    if (!document.hidden) refreshUnreadCount();
  }, 60000);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refreshUnreadCount();
  });
}

function initTooltips() {
  for (const node of document.querySelectorAll('[data-bs-toggle="tooltip"]')) {
    new bootstrap.Tooltip(node);
  }
}

function initAvatarFallbacks() {
  const fallback = url('assets/default-avatar.svg');
  for (const image of document.querySelectorAll('img.sk-avatar')) {
    const applyFallback = () => {
      if (image.dataset.fallbackApplied === '1') return;
      image.dataset.fallbackApplied = '1';
      image.src = fallback;
    };
    image.addEventListener('error', applyFallback);
    if (image.complete && image.naturalWidth === 0) {
      applyFallback();
    }
  }
  for (const image of document.querySelectorAll('img.sk-site-logo, img.sk-auth-logo')) {
    const hideBrokenLogo = () => {
      image.hidden = true;
    };
    image.addEventListener('error', hideBrokenLogo, { once: true });
    if (image.complete && image.naturalWidth === 0) {
      hideBrokenLogo();
    }
  }
}

function initFlashAlerts() {
  for (const alert of document.querySelectorAll('.alert[data-autohide]')) {
    window.setTimeout(() => {
      bootstrap.Alert.getOrCreateInstance(alert)?.close();
    }, 6000);
  }
}

let appInitialized = false;

function bootApp() {
  if (appInitialized) return;
  appInitialized = true;
  initTheme();
  initPasswordToggles();
  initConfirmForms();
  initLogout();
  initNotificationPolling();
  initTooltips();
  initFlashAlerts();
  initAvatarFallbacks();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootApp, { once: true });
} else {
  bootApp();
}

window.addEventListener('unhandledrejection', (event) => {
  console.error('Unhandled promise rejection:', event.reason);
});

export { toast, url };
