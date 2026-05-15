// Thin REST client over the fce/v1 endpoints exposed by
// FCE_REST_Controller. The Vue components stay free of fetch
// plumbing and just call api.foo(...).

const config = window.FCEAdmin || {};
const REST_ROOT = config.restRoot || '/wp-json/fce/v1/';
const NONCE = config.restNonce || '';

/**
 * Internal fetch wrapper. Adds the WP nonce header, parses JSON,
 * and surfaces a uniform error shape for the UI.
 */
async function request(path, { method = 'GET', body } = {}) {
  const url = REST_ROOT.replace(/\/$/, '') + '/' + path.replace(/^\//, '');

  const init = {
    method,
    credentials: 'same-origin',
    headers: {
      'X-WP-Nonce': NONCE,
      'Content-Type': 'application/json',
    },
  };

  if (body !== undefined) {
    init.body = JSON.stringify(body);
  }

  let response;
  try {
    response = await fetch(url, init);
  } catch (networkErr) {
    throw new ApiError('Network error reaching the server.', networkErr);
  }

  const text = await response.text();
  let data = null;
  if (text) {
    try {
      data = JSON.parse(text);
    } catch (parseErr) {
      throw new ApiError(`Server returned non-JSON response (HTTP ${response.status}).`, parseErr);
    }
  }

  if (!response.ok) {
    const message = (data && (data.message || data.code)) || `HTTP ${response.status}`;
    throw new ApiError(message, null, response.status, data);
  }

  return data;
}

export class ApiError extends Error {
  constructor(message, cause = null, status = 0, payload = null) {
    super(message);
    this.name = 'ApiError';
    this.cause = cause;
    this.status = status;
    this.payload = payload;
  }
}

export const api = {
  focusAreas: {
    get: () => request('focus-areas'),
    save: (options) => request('focus-areas', { method: 'POST', body: { options } }),
  },
  capacityTiers: {
    get: () => request('capacity-tiers'),
    save: (options) => request('capacity-tiers', { method: 'POST', body: { options } }),
  },
  bulkResync: {
    // Synchronous on the server. Browser default fetch timeout is
    // forgiving (minutes) — fine for installs with up to a few thousand
    // companies. For larger installs, see the time/memory limits
    // raised on the server side in FCE_REST_Controller::run_bulk_resync.
    run: (confirmation) => request('bulk-resync', { method: 'POST', body: { confirmation } }),
  },
  contextModules: {
    // surface: 'contact' | 'company'
    get: (surface) => request(`context-modules/${surface}`),
    save: (surface, modules) =>
      request(`context-modules/${surface}`, { method: 'POST', body: { modules } }),
  },
  lookupFields: {
    // surface: 'contact' | 'company'
    get: (surface) => request(`lookup-fields/${surface}`),
    save: (surface, slugs) =>
      request(`lookup-fields/${surface}`, { method: 'POST', body: { slugs } }),
  },
  metaPrompt: {
    // surface: 'contact' | 'company'. Returns { prompt: string } or 404
    // if the prompt file is missing — caller should hide the widget on 404.
    get: (surface) => request(`meta-prompt/${surface}`),
  },
};
