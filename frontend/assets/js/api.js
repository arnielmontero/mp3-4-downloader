// Thin fetch wrapper around the REST API. Every call resolves with `data` or throws an ApiError.

export class ApiError extends Error {
  constructor(code, message, status) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
  }
}

async function request(method, path, body) {
  let response;
  try {
    response = await fetch(path, {
      method,
      credentials: 'same-origin',
      headers: body !== undefined ? { 'Content-Type': 'application/json', Accept: 'application/json' } : { Accept: 'application/json' },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError('NETWORK', 'Unable to reach the server. Please check your connection.', 0);
  }
  let payload = null;
  try {
    payload = await response.json();
  } catch {
    throw new ApiError('SERVER_ERROR', 'The server returned an unexpected response.', response.status);
  }
  if (!response.ok || payload.success === false) {
    const err = payload.error || {};
    throw new ApiError(err.code || 'SERVER_ERROR', err.message || 'Something went wrong.', response.status);
  }
  return payload;
}

export const api = {
  search: (query) => request('POST', '/api/search', { query }).then((r) => r.data),
  analyze: (url) => request('POST', '/api/video/info', { url }).then((r) => r.data),
  startDownload: (options) => request('POST', '/api/download', options).then((r) => r.data),
  status: (id) => request('GET', `/api/download/${encodeURIComponent(id)}`).then((r) => r.data),
  cancel: (id) => request('POST', `/api/download/${encodeURIComponent(id)}/cancel`, {}).then((r) => r.data),
  history: () => request('GET', '/api/download/history').then((r) => r.data.items),
  removeHistory: (id) => request('DELETE', `/api/download/history/${encodeURIComponent(id)}`),
  about: () => request('GET', '/api/about').then((r) => r.data),
  fileUrl: (id) => `/api/download/${encodeURIComponent(id)}/file`,
};
