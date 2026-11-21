/*
 * AlbaBook JSON API client. Vanilla fetch, no framework (plan.md §3.3).
 * Standard envelope: { ok: true, data } / { ok: false, error: { code, message, fields? } }.
 * Never trusts client data for permissions; every call goes to same origin with
 * the session cookie and the CSRF header on state-changing methods.
 */
(function () {
  'use strict';

  const AlbaBook = (window.AlbaBook = window.AlbaBook || {});

  class ApiError extends Error {
    constructor(code, message, status, fields) {
      super(message || 'Request failed');
      this.name = 'ApiError';
      this.code = code || 'UNKNOWN';
      this.status = status || 0;
      this.fields = fields || {};
    }
  }

  function buildQuery(query) {
    if (!query) return '';
    const params = new URLSearchParams();
    Object.entries(query).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') return;
      params.set(key, String(value));
    });
    const str = params.toString();
    return str ? '?' + str : '';
  }

  async function request(method, url, { query, body, signal } = {}) {
    const headers = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    };
    const init = { method, headers, credentials: 'same-origin', signal };
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-Token'] = AlbaBook.csrfToken();
      init.body = JSON.stringify(body);
    }
    if (method !== 'GET' && body === undefined) {
      headers['X-CSRF-Token'] = AlbaBook.csrfToken();
    }

    let response;
    try {
      response = await fetch(url + buildQuery(query), init);
    } catch (error) {
      if (error && error.name === 'AbortError') throw error;
      throw new ApiError('NETWORK', 'Network error. Check your connection and retry.', 0);
    }

    let payload = null;
    try {
      payload = await response.json();
    } catch (error) {
      payload = null;
    }

    if (!response.ok || (payload && payload.ok === false)) {
      const error = (payload && payload.error) || {};
      throw new ApiError(
        error.code || 'HTTP_' + response.status,
        error.message || response.statusText || 'Request failed',
        response.status,
        error.fields || {},
      );
    }

    return payload && typeof payload === 'object' && 'data' in payload ? payload.data : payload;
  }

  AlbaBook.ApiError = ApiError;
  AlbaBook.api = {
    get: (url, options) => request('GET', url, options),
    post: (url, body, options) => request('POST', url, Object.assign({}, options, { body: body || {} })),
    patch: (url, body, options) => request('PATCH', url, Object.assign({}, options, { body: body || {} })),
    del: (url, options) => request('DELETE', url, options),
  };
})();
