import { SK, url } from './context.js';

export class ApiError extends Error {
  constructor(message, status, code, extra) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code || 'error';
    this.extra = extra ?? null;
  }
}

export async function api(path, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const headers = {
    Accept: 'application/json',
    ...(options.headers || {}),
  };
  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }
  if (method !== 'GET' && method !== 'HEAD') {
    headers['X-CSRF-Token'] = SK.csrf;
  }

  let response;
  try {
    response = await fetch(url('api/' + String(path).replace(/^\/+/, '')), {
      method,
      headers,
      credentials: 'same-origin',
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      signal: options.signal,
    });
  } catch (error) {
    if (error?.name === 'AbortError') throw error;
    throw new ApiError('网络连接失败，请检查网络后重试。', 0, 'network_error');
  }

  let payload = null;
  const contentType = response.headers.get('content-type') || '';
  if (contentType.includes('application/json')) {
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }
  }

  if (!response.ok || !payload?.ok) {
    const apiError = new ApiError(
      payload?.error?.message || `请求失败（HTTP ${response.status}）`,
      response.status,
      payload?.error?.code,
      payload?.error?.extra,
    );
    if (response.status === 401) {
      const next = encodeURIComponent(window.location.pathname + window.location.search);
      window.location.assign(url('login?next=' + next));
    }
    throw apiError;
  }

  return payload.data;
}

export function apiUrl(path) {
  return url('api/' + String(path).replace(/^\/+/, ''));
}
