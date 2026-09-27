// JSON API client: CSRF header, error normalisation, session-expiry hook.

let csrf = '';
let authLostHandler = null;

export class ApiError extends Error {
  constructor(message, status = 0, code = 'error', fields = {}) {
    super(message);
    this.status = status;
    this.code = code;
    this.fields = fields || {};
  }
}

export function setCsrf(token) {
  csrf = token || '';
}

export function onAuthLost(fn) {
  authLostHandler = fn;
}

function buildUrl(route, params) {
  const qs = new URLSearchParams({ r: route });
  if (params) {
    for (const [k, v] of Object.entries(params)) {
      if (v === undefined || v === null || v === '') continue;
      if (Array.isArray(v)) v.forEach((item) => qs.append(k + '[]', item));
      else if (typeof v === 'boolean') qs.append(k, v ? '1' : '0');
      else qs.append(k, String(v));
    }
  }
  return 'api.php?' + qs.toString();
}

async function refreshCsrf() {
  try {
    const res = await fetch(buildUrl('auth.me'), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const data = await res.json();
    if (data?.ok) setCsrf(data.data.csrf);
  } catch {
    /* ignore — the retry will surface the error */
  }
}

async function request(route, { method = 'GET', params, body, raw = false, retry = true, signal } = {}) {
  const opts = { method, credentials: 'same-origin', headers: { Accept: 'application/json' }, signal };
  if (method === 'POST') {
    opts.headers['Content-Type'] = 'application/json';
    opts.headers['X-CSRF-Token'] = csrf;
    opts.body = JSON.stringify(body || {});
  }

  let res;
  try {
    res = await fetch(buildUrl(route, params), opts);
  } catch (e) {
    if (e?.name === 'AbortError') throw e;
    throw new ApiError('Network error — check your internet connection and try again.', 0, 'network');
  }

  if (raw && res.ok && !(res.headers.get('Content-Type') || '').includes('application/json')) {
    return res;
  }

  let data;
  try {
    data = await res.json();
  } catch {
    throw new ApiError(`The server returned an unexpected response (HTTP ${res.status}). Check storage/logs/app.log.`, res.status, 'bad_response');
  }

  if (res.status === 419 && retry) {
    await refreshCsrf();
    return request(route, { method, params, body, raw, retry: false, signal });
  }
  if (res.status === 401 && route !== 'auth.me' && route !== 'auth.login') {
    authLostHandler?.();
  }
  if (!data || data.ok !== true) {
    const err = data?.error || {};
    throw new ApiError(err.message || `Request failed (HTTP ${res.status}).`, res.status, err.code || 'error', err.fields || {});
  }
  return data.data;
}

export const api = {
  get: (route, params, opts = {}) => request(route, { ...opts, params }),
  post: (route, body, opts = {}) => request(route, { ...opts, method: 'POST', body }),
  /** POST that returns the raw Response (file downloads). */
  download: (route, body) => request(route, { method: 'POST', body, raw: true }),
};
