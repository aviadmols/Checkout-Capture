/*
 * Shared helpers for the embedded app pages. fetch() to our own origin is signed by
 * App Bridge (Authorization: Bearer <ID token>), so no tokens are handled here.
 */
window.App = (() => {
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));

  async function api(path, options = {}) {
    const response = await fetch(`/api/shopify/app/${path}`, {
      ...options,
      headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}), ...options.headers },
    });

    let body = null;
    try { body = await response.json(); } catch (e) { /* empty body */ }

    if (!response.ok) {
      const error = new Error((body && body.message) || `Request failed (${response.status})`);
      error.status = response.status;
      error.errors = (body && body.errors) || {};
      throw error;
    }

    return body;
  }

  const toast = (message, isError = false) => {
    if (window.shopify && shopify.toast) shopify.toast.show(message, { isError, duration: isError ? 8000 : 4000 });
  };

  const money = (amount, currency) => {
    if (amount === null || amount === undefined) return '—';
    try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'USD' }).format(amount); } catch (e) { return `${amount} ${currency || ''}`; }
  };

  const date = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—');

  const ago = (iso) => {
    if (!iso) return 'never';
    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes} min ago`;
    if (minutes < 1440) return `${Math.round(minutes / 60)} h ago`;
    return `${Math.round(minutes / 1440)} days ago`;
  };

  const sourceLabel = (source) => ({ url: 'Link', form: 'Store form', popup: 'Popup', customer: 'Logged in' }[source] || source);

  /** Leaves the iframe — for Shopify's plan approval page and the theme editor. */
  const goTop = (url) => window.open(url, '_top');

  const failed = (container, error) => {
    container.innerHTML = `<s-banner tone="critical" heading="Something went wrong">${esc(error.message)}</s-banner>`;
  };

  return { esc, api, toast, money, date, ago, sourceLabel, goTop, failed };
})();
