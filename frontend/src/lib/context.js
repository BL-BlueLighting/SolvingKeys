const html = document.documentElement;

function intData(name, fallback = 0) {
  const value = html.dataset[name];
  if (value === undefined || value === '') return fallback;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function boolData(name) {
  return html.dataset[name] === '1';
}

export const SK = {
  baseUrl: (html.dataset.baseUrl || '').replace(/\/$/, ''),
  csrf: html.dataset.csrf || '',
  appName: html.dataset.appName || 'SolvingKeys',
  vditorCdn: (html.dataset.vditorCdn || '').replace(/\/$/, ''),
  vaultIterations: intData('vaultIterations', 310000),
  user: html.dataset.userId
    ? {
        id: intData('userId'),
        username: html.dataset.username || '',
        permission: html.dataset.permission || 'User',
        banned: boolData('banned'),
        cloudVault: boolData('cloudVault'),
      }
    : null,
};

export function url(path = '') {
  const clean = String(path).replace(/^\/+/, '');
  if (!clean) return SK.baseUrl + '/';
  return SK.baseUrl + '/' + clean;
}

export function isAdmin() {
  return SK.user?.permission === 'Administrator';
}

export function canWrite() {
  return !!SK.user && !SK.user.banned;
}
