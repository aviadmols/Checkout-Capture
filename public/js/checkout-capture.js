/*!
 * Checkout Capture — storefront widget.
 *
 * Flow on every checkout click:
 *   email known (localStorage / logged-in customer) → attach to Shopify cart → continue to checkout
 *   email unknown → popup → localStorage → Laravel → attach to Shopify cart → continue to checkout
 *
 * Fail-open by design: any error or timeout lets the original checkout action through.
 *
 * <script src="https://APP/js/checkout-capture.js" data-key="cc_..." defer></script>
 */
(() => {
  'use strict';

  if (window.CheckoutCapture) return;

  const script = document.currentScript || document.querySelector('script[data-key][src*="checkout-capture"]');
  if (!script) return;

  const KEY = script.dataset.key;
  const API = (script.dataset.api || new URL(script.src, location.href).origin).replace(/\/$/, '');
  const CUSTOMER_EMAIL = (script.dataset.customerEmail || '').trim();
  const STOREFRONT_API_VERSION = script.dataset.storefrontApiVersion || '2026-07';
  const ROOT = (window.Shopify && window.Shopify.routes && window.Shopify.routes.root) || '/';
  const STORAGE_KEY = 'checkoutCapture:v1';
  const CONFIG_CACHE_KEY = `checkoutCapture:config:${KEY}`;
  const CONFIG_TTL = 5 * 60 * 1000;
  const STEP_TIMEOUT = 4000;
  const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  const PHONE_RE = /^\+?[\d\s\-()]{7,20}$/;
  const CHECKOUT_PATH_RE = /^\/(?:[a-z]{2}(?:-[a-z]{2})?\/)?checkouts?\/?$/i;

  if (!KEY) {
    console.warn('[CheckoutCapture] missing data-key — widget disabled.');
    return;
  }

  // Mirrors Shop::DEFAULT_SETTINGS so the popup still works if the config call fails.
  const DEFAULT_SETTINGS = {
    direction: 'rtl',
    title: 'רגע לפני התשלום',
    subtitle: 'השאירו מייל ונשמור לכם את העגלה — כדי שלא תאבדו אותה בדרך.',
    email_label: 'אימייל',
    name_label: 'שם מלא',
    phone_label: 'טלפון',
    submit_label: 'המשך לתשלום',
    skip_label: 'המשך בלי למלא',
    consent_label: 'אשמח לקבל עדכונים ומבצעים במייל',
    privacy_note: '',
    error_email: 'נא להזין כתובת מייל תקינה',
    error_required: 'שדה חובה',
    name_field: 'optional',
    phone_field: 'hidden',
    consent_enabled: true,
    consent_checked: false,
    allow_skip: false,
    accent_color: '#000000',
    extra_selectors: '',
  };

  const state = {
    config: { enabled: true, storefrontToken: null, settings: DEFAULT_SETTINGS },
    configReady: null,
    passthrough: false,
    busy: false,
    memoryDetails: null,
    popup: null,
  };

  /* ---------------------------------------------------------------- utils */

  const noop = () => {};

  const withTimeout = (promise, ms) => new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('timeout')), ms);
    promise.then(
      (value) => { clearTimeout(timer); resolve(value); },
      (error) => { clearTimeout(timer); reject(error); },
    );
  });

  const fetchJson = (url, options = {}, ms = STEP_TIMEOUT) => withTimeout(
    fetch(url, {
      credentials: 'same-origin',
      ...options,
      headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}), ...options.headers },
    }).then((response) => {
      if (!response.ok) throw new Error(`HTTP ${response.status} ${url}`);
      return response.json();
    }),
    ms,
  );

  const storage = {
    get(key, store = window.localStorage) {
      try { return JSON.parse(store.getItem(key)); } catch (e) { return null; }
    },
    set(key, value, store = window.localStorage) {
      try { store.setItem(key, JSON.stringify(value)); return true; } catch (e) { return false; }
    },
    remove(key, store = window.localStorage) {
      try { store.removeItem(key); } catch (e) { /* storage blocked */ }
    },
  };

  const emit = (name, detail) => {
    document.dispatchEvent(new CustomEvent(`checkout-capture:${name}`, { detail }));
  };

  const settings = () => state.config.settings;

  /* --------------------------------------------------------------- config */

  function loadConfig() {
    const cached = storage.get(CONFIG_CACHE_KEY, window.sessionStorage);
    if (cached && cached.at > Date.now() - CONFIG_TTL) {
      applyConfig(cached.config);
      return Promise.resolve();
    }

    return fetchJson(`${API}/api/v1/widget/${encodeURIComponent(KEY)}`, { credentials: 'omit' })
      .then((config) => {
        storage.set(CONFIG_CACHE_KEY, { at: Date.now(), config }, window.sessionStorage);
        applyConfig(config);
      })
      .catch((error) => console.warn('[CheckoutCapture] config unavailable, using defaults.', error));
  }

  function applyConfig(config) {
    state.config = {
      enabled: config.enabled !== false,
      storefrontToken: config.storefrontToken || null,
      settings: { ...DEFAULT_SETTINGS, ...(config.settings || {}) },
    };
  }

  /* -------------------------------------------------------------- details */

  function knownDetails() {
    const stored = storage.get(STORAGE_KEY) || state.memoryDetails;
    if (stored && EMAIL_RE.test(stored.email || '')) return stored;
    if (EMAIL_RE.test(CUSTOMER_EMAIL)) return { email: CUSTOMER_EMAIL, source: 'customer' };
    return null;
  }

  function rememberDetails(details) {
    const record = { ...details, savedAt: new Date().toISOString() };
    state.memoryDetails = record;
    storage.set(STORAGE_KEY, record);
    return record;
  }

  /* ------------------------------------------------------------- shopify */

  const getCart = () => fetchJson(`${ROOT}cart.js`);

  function cartSnapshot(cart) {
    if (!cart) return null;
    return {
      token: cart.token,
      total_price: cart.total_price,
      currency: cart.currency,
      item_count: cart.item_count,
      items: (cart.items || []).slice(0, 100).map((item) => ({
        variant_id: item.variant_id,
        product_title: item.product_title,
        variant_title: item.variant_title,
        quantity: item.quantity,
        price: item.final_price ?? item.price,
      })),
    };
  }

  /** Cart attributes (always) + Storefront API buyer identity (when a token is configured). */
  async function attachToCart(details) {
    const attributes = { _capture_email: details.email };
    if (details.name) attributes._capture_name = details.name;
    if (details.phone) attributes._capture_phone = details.phone;

    const cart = await fetchJson(`${ROOT}cart/update.js`, {
      method: 'POST',
      body: JSON.stringify({ attributes }),
    });

    if (state.config.storefrontToken && cart && cart.token) {
      await setBuyerIdentity(cart.token, details.email).catch((error) => {
        console.warn('[CheckoutCapture] buyer identity not set.', error);
      });
    }

    return cart;
  }

  async function setBuyerIdentity(cartToken, email) {
    const result = await fetchJson(`${location.origin}/api/${STOREFRONT_API_VERSION}/graphql.json`, {
      method: 'POST',
      headers: { 'X-Shopify-Storefront-Access-Token': state.config.storefrontToken },
      body: JSON.stringify({
        query: `mutation CheckoutCaptureIdentity($cartId: ID!, $buyerIdentity: CartBuyerIdentityInput!) {
          cartBuyerIdentityUpdate(cartId: $cartId, buyerIdentity: $buyerIdentity) {
            cart { id }
            userErrors { field message }
          }
        }`,
        variables: {
          cartId: `gid://shopify/Cart/${cartToken}`,
          buyerIdentity: { email },
        },
      }),
    });

    const errors = (result.errors || []).concat(result.data?.cartBuyerIdentityUpdate?.userErrors || []);
    if (errors.length) throw new Error(errors.map((e) => e.message).join('; '));
  }

  /* -------------------------------------------------------------- laravel */

  function saveRemote(details, cart) {
    return fetchJson(`${API}/api/v1/captures`, {
      method: 'POST',
      credentials: 'omit',
      // keepalive lets the request finish even if checkout navigation starts first.
      keepalive: true,
      body: JSON.stringify({
        key: KEY,
        email: details.email,
        name: details.name || null,
        phone: details.phone || null,
        accepts_marketing: !!details.acceptsMarketing,
        website: details.website || '',
        locale: document.documentElement.lang || navigator.language || null,
        page_url: location.href.slice(0, 2000),
        cart: cartSnapshot(cart),
      }),
    });
  }

  /* ------------------------------------------------------------ triggers */

  function triggerSelector() {
    const extra = (settings().extra_selectors || '').trim();
    return ['button[name="checkout"]', 'input[name="checkout"]', 'a[href]', '[data-checkout-capture]']
      .concat(extra ? [extra] : [])
      .join(',');
  }

  function isCheckoutTrigger(el) {
    if (el.matches('[data-checkout-capture-ignore]')) return false;
    if (el.tagName === 'A') {
      try {
        const url = new URL(el.href, location.href);
        return url.origin === location.origin && CHECKOUT_PATH_RE.test(url.pathname);
      } catch (e) { return false; }
    }
    return true;
  }

  function findTrigger(target) {
    if (!target || !target.closest) return null;
    let el;
    try { el = target.closest(triggerSelector()); } catch (e) { el = target.closest('button[name="checkout"], input[name="checkout"], a[href]'); }
    return el && isCheckoutTrigger(el) ? el : null;
  }

  /** Replays the shopper's original click so the theme's own checkout logic still runs. */
  function resume(trigger, details) {
    state.passthrough = true;
    try {
      if (trigger && trigger.isConnected) {
        if (trigger.tagName === 'A' && details) {
          const url = new URL(trigger.href, location.href);
          url.searchParams.set('checkout[email]', details.email);
          trigger.href = url.toString();
        }
        trigger.click();
      } else {
        const url = new URL(`${ROOT}checkout`, location.href);
        if (details) url.searchParams.set('checkout[email]', details.email);
        location.href = url.toString();
      }
    } finally {
      state.passthrough = false;
    }
  }

  function onClick(event) {
    if (state.passthrough || !state.config.enabled) return;
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    const trigger = findTrigger(event.target);
    if (!trigger) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    beginCheckout(trigger);
  }

  async function beginCheckout(trigger) {
    if (state.busy) return;
    state.busy = true;

    try {
      await withTimeout(state.configReady, 1500).catch(noop);

      if (!state.config.enabled) return resume(trigger, null);

      const known = knownDetails();
      if (known) {
        await attachToCart(known).catch(noop);
        emit('checkout', { details: known, popup: false });
        return resume(trigger, known);
      }

      const details = await openPopup(trigger);
      if (details === null) return; // dismissed — stay on the page
      resume(trigger, details.skipped ? null : details);
    } catch (error) {
      console.warn('[CheckoutCapture] falling back to plain checkout.', error);
      resume(trigger, null);
    } finally {
      state.busy = false;
    }
  }

  /* --------------------------------------------------------------- popup */

  const STYLES = `
    :host { all: initial; font-family: inherit; color-scheme: light; }
    *, *::before, *::after { box-sizing: border-box; }

    .backdrop {
      --ink: #000000;
      --paper: #ffffff;
      --rule: #bcbcbc;
      --muted: #5c5c5c;
      --accent-warn: #c4452d;
      --accent-primary: var(--cc-accent, var(--ink));
      --ease: 0.5s ease;

      position: fixed; inset: 0; z-index: 2147483000;
      display: flex; align-items: flex-end; justify-content: center;
      background: rgb(0 0 0 / 0);
      transition: background var(--ease);
      font-family: inherit; color: var(--ink);
      -webkit-font-smoothing: antialiased;
    }
    .backdrop.is-open { background: rgb(0 0 0 / 45%); }

    .dialog {
      position: relative; width: 100%; max-height: 92vh; overflow-y: auto;
      background: var(--paper);
      padding: 40px 24px calc(28px + env(safe-area-inset-bottom));
      transform: translateY(24px); opacity: 0;
      transition: transform var(--ease), opacity var(--ease);
    }
    .is-open .dialog { transform: none; opacity: 1; }

    .close {
      position: absolute; inset-block-start: 12px; inset-inline-end: 12px;
      width: 40px; height: 40px; padding: 0; border: 0; background: none; cursor: pointer;
      color: var(--ink); font: inherit; font-size: 28px; font-weight: 300; line-height: 1;
      opacity: .6; transition: opacity var(--ease);
    }
    .close:hover, .close:focus-visible { opacity: 1; }

    .title {
      margin: 0 0 12px; font-size: 32px; font-weight: 900; line-height: 1; letter-spacing: 0;
    }
    .subtitle {
      margin: 0 0 28px; font-size: 18px; font-weight: 300; line-height: 1.4; color: var(--muted);
    }

    form { display: grid; gap: 24px; margin: 0; }

    .field { display: grid; gap: 6px; }
    .field-label {
      font-size: 14px; font-weight: 500; letter-spacing: 1px; color: var(--muted);
    }
    .field input {
      width: 100%; padding: 8px 0; margin: 0;
      border: 0; border-block-end: 1px solid var(--rule); border-radius: 0;
      background: transparent; color: var(--ink);
      font: inherit; font-size: 18px; font-weight: 400;
      outline: none; transition: border-color var(--ease);
      -webkit-appearance: none; appearance: none;
    }
    .field input:focus { border-block-end: 2px solid var(--ink); padding-block-end: 7px; }
    .field input[aria-invalid="true"] { border-block-end-color: var(--accent-warn); }
    .field input[type="email"], .field input[type="tel"] { direction: ltr; text-align: start; }
    [dir="rtl"] .field input[type="email"], [dir="rtl"] .field input[type="tel"] { text-align: right; }
    .error { min-height: 0; font-size: 14px; color: var(--accent-warn); }
    .error:empty { display: none; }

    .consent {
      display: flex; gap: 10px; align-items: flex-start; cursor: pointer;
      font-size: 14px; font-weight: 400; line-height: 1.45; color: var(--muted);
    }
    .consent input {
      flex: none; width: 18px; height: 18px; margin: 1px 0 0; accent-color: var(--ink); cursor: pointer;
    }

    .submit {
      display: block; width: 100%; min-height: 56px; margin-block-start: 4px; padding: 16px 24px;
      border: 1px solid var(--accent-primary); border-radius: 0;
      background: var(--accent-primary); color: var(--paper);
      font: inherit; font-size: 16px; font-weight: 500; letter-spacing: 2px;
      cursor: pointer; transition: all var(--ease);
    }
    .submit:hover, .submit:focus-visible { background: transparent; color: var(--accent-primary); }
    .submit:focus-visible { outline: 2px solid var(--accent-primary); outline-offset: 3px; }
    .submit[aria-busy="true"] { opacity: .6; cursor: progress; }

    .skip {
      justify-self: center; padding: 4px; border: 0; background: none; cursor: pointer;
      color: var(--muted); font: inherit; font-size: 14px;
      text-decoration: underline; text-underline-offset: 4px; transition: color var(--ease);
    }
    .skip:hover, .skip:focus-visible { color: var(--ink); }

    .privacy { margin: 0; font-size: 12px; line-height: 1.5; color: var(--muted); text-align: center; }
    .privacy:empty { display: none; }

    .hp { position: absolute !important; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); opacity: 0; }

    @media (min-width: 768px) {
      .backdrop { align-items: center; padding: 24px; }
      .dialog { max-width: 480px; padding: 56px 48px 40px; }
      .title { font-size: 40px; line-height: .95; }
    }

    @media (prefers-reduced-motion: reduce) {
      .backdrop, .dialog, .submit, .close, .skip, .field input { transition: none; }
    }
  `;

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));

  function fieldHtml(name, type, label, mode, autocomplete) {
    if (mode === 'hidden') return '';
    const required = mode === 'required';
    return `
      <label class="field">
        <span class="field-label">${escapeHtml(label)}${required ? ' *' : ''}</span>
        <input name="${name}" type="${type}" autocomplete="${autocomplete}"
          ${type === 'email' ? 'inputmode="email" spellcheck="false" autocapitalize="off"' : ''}
          ${type === 'tel' ? 'inputmode="tel"' : ''}
          ${required ? 'required aria-required="true"' : ''}
          aria-describedby="cc-error-${name}">
        <span class="error" id="cc-error-${name}" role="alert"></span>
      </label>`;
  }

  function buildPopup() {
    const s = settings();
    const rtl = s.direction === 'rtl';
    const host = document.createElement('div');
    host.setAttribute('data-checkout-capture-root', '');
    const root = host.attachShadow({ mode: 'open' });

    root.innerHTML = `
      <style>${STYLES}</style>
      <div class="backdrop" part="backdrop" style="--cc-accent:${escapeHtml(s.accent_color || '#000000')}">
        <div class="dialog" part="dialog" role="dialog" aria-modal="true" aria-labelledby="cc-title"
          ${s.subtitle ? 'aria-describedby="cc-subtitle"' : ''} dir="${rtl ? 'rtl' : 'ltr'}" lang="${rtl ? 'he' : 'en'}">
          <button type="button" class="close" data-action="close" aria-label="${rtl ? 'סגירה' : 'Close'}">&times;</button>
          <h2 class="title" id="cc-title">${escapeHtml(s.title)}</h2>
          ${s.subtitle ? `<p class="subtitle" id="cc-subtitle">${escapeHtml(s.subtitle)}</p>` : ''}
          <form novalidate>
            ${fieldHtml('email', 'email', s.email_label, 'required', 'email')}
            ${fieldHtml('name', 'text', s.name_label, s.name_field, 'name')}
            ${fieldHtml('phone', 'tel', s.phone_label, s.phone_field, 'tel')}
            <input class="hp" name="website" type="text" tabindex="-1" autocomplete="off" aria-hidden="true">
            ${s.consent_enabled ? `
              <label class="consent">
                <input type="checkbox" name="accepts_marketing" ${s.consent_checked ? 'checked' : ''}>
                <span>${escapeHtml(s.consent_label)}</span>
              </label>` : ''}
            <button type="submit" class="submit">${escapeHtml(s.submit_label)}</button>
            ${s.allow_skip ? `<button type="button" class="skip" data-action="skip">${escapeHtml(s.skip_label)}</button>` : ''}
            <p class="privacy">${escapeHtml(s.privacy_note)}</p>
          </form>
        </div>
      </div>`;

    return { host, root };
  }

  function validate(form) {
    const s = settings();
    const values = {
      email: (form.elements.email?.value || '').trim(),
      name: (form.elements.name?.value || '').trim(),
      phone: (form.elements.phone?.value || '').trim(),
      acceptsMarketing: !!form.elements.accepts_marketing?.checked,
      website: form.elements.website?.value || '',
    };
    const errors = {};

    if (!EMAIL_RE.test(values.email)) errors.email = s.error_email;
    if (s.name_field === 'required' && !values.name) errors.name = s.error_required;
    if (s.phone_field === 'required' && !values.phone) errors.phone = s.error_required;
    if (values.phone && !PHONE_RE.test(values.phone)) errors.phone = s.error_required;

    ['email', 'name', 'phone'].forEach((name) => {
      const input = form.elements[name];
      if (!input) return;
      input.setAttribute('aria-invalid', errors[name] ? 'true' : 'false');
      form.querySelector(`#cc-error-${name}`).textContent = errors[name] || '';
    });

    return { values, errors };
  }

  /**
   * Resolves with the submitted details, `{ skipped: true }`, or `null` when dismissed.
   */
  function openPopup(trigger) {
    return new Promise((resolve) => {
      const { host, root } = buildPopup();
      const backdrop = root.querySelector('.backdrop');
      const form = root.querySelector('form');
      const submit = root.querySelector('.submit');
      const previousOverflow = document.documentElement.style.overflow;
      const cartPromise = getCart().catch(() => null);
      let settled = false;

      document.body.appendChild(host);
      document.documentElement.style.overflow = 'hidden';
      requestAnimationFrame(() => backdrop.classList.add('is-open'));
      setTimeout(() => form.elements.email.focus({ preventScroll: true }), 50);
      emit('open', {});

      const finish = (result) => {
        if (settled) return;
        settled = true;
        document.removeEventListener('keydown', onKeydown, true);
        backdrop.classList.remove('is-open');
        setTimeout(() => {
          host.remove();
          document.documentElement.style.overflow = previousOverflow;
        }, result ? 0 : 400);
        if (!result && trigger && trigger.focus) trigger.focus({ preventScroll: true });
        state.popup = null;
        resolve(result);
      };

      const onKeydown = (event) => {
        if (event.key === 'Escape') { event.preventDefault(); finish(null); return; }
        if (event.key !== 'Tab') return;
        const focusables = [...root.querySelectorAll('button, input:not(.hp)')].filter((el) => !el.disabled);
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        if (event.shiftKey && root.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && root.activeElement === last) { event.preventDefault(); first.focus(); }
      };
      document.addEventListener('keydown', onKeydown, true);

      backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) finish(null);
        const action = event.target.closest && event.target.closest('[data-action]')?.dataset.action;
        if (action === 'close') finish(null);
        if (action === 'skip') { emit('skip', {}); finish({ skipped: true }); }
      });

      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.getAttribute('aria-busy') === 'true') return;

        const { values, errors } = validate(form);
        const firstInvalid = Object.keys(errors)[0];
        if (firstInvalid) { form.elements[firstInvalid].focus(); return; }

        submit.setAttribute('aria-busy', 'true');
        submit.disabled = true;

        const details = rememberDetails({
          email: values.email.toLowerCase(),
          name: values.name || null,
          phone: values.phone || null,
          acceptsMarketing: values.acceptsMarketing,
          source: 'popup',
        });

        const cart = await withTimeout(cartPromise, 1500).catch(() => null);
        await Promise.allSettled([
          saveRemote({ ...details, website: values.website }, cart),
          attachToCart(details),
        ]);

        emit('captured', { details });
        finish(details);
      });

      state.popup = { close: () => finish(null) };
    });
  }

  /* ---------------------------------------------------------------- boot */

  state.configReady = loadConfig();
  window.addEventListener('click', onClick, true);

  // Back button from checkout restores the page from bfcache — make sure nothing is stuck.
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
      state.busy = false;
      state.passthrough = false;
      if (state.popup) state.popup.close();
    }
  });

  window.CheckoutCapture = {
    version: '1.0.0',
    /** For themes that start checkout from JS: `await CheckoutCapture.ensure()` before redirecting. */
    async ensure() {
      await withTimeout(state.configReady, 1500).catch(noop);
      if (!state.config.enabled) return null;
      const known = knownDetails();
      if (known) { await attachToCart(known).catch(noop); return known; }
      const details = await openPopup(null);
      return details && !details.skipped ? details : null;
    },
    open: () => openPopup(null),
    details: () => knownDetails(),
    forget() { state.memoryDetails = null; storage.remove(STORAGE_KEY); },
  };
})();
