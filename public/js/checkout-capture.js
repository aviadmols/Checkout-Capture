/*!
 * Checkout Capture — storefront widget.
 *
 * Modes (set per shop in the admin):
 *   popup — email known (localStorage / link / logged-in customer) → attach to cart → checkout;
 *           unknown → popup → localStorage → Laravel → attach to cart → checkout
 *   url   — no form: only an email that arrives in the link (?email=…) is captured and attached
 *   off   — the widget does nothing
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
  // Admin preview page: config comes inline, nothing is sent to Laravel or Shopify.
  const PREVIEW = script.dataset.preview === 'true';
  const ROOT = (window.Shopify && window.Shopify.routes && window.Shopify.routes.root) || '/';
  const STORAGE_KEY = 'checkoutCapture:v1';
  const CONFIG_CACHE_KEY = `checkoutCapture:config:${KEY}`;
  // Short so a mode or design change in the admin reaches shoppers within a minute.
  const CONFIG_TTL = 60 * 1000;
  const STEP_TIMEOUT = 4000;
  const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  const PHONE_RE = /^\+?[\d\s\-()]{7,20}$/;
  const CHECKOUT_PATH_RE = /^\/(?:[a-z]{2}(?:-[a-z]{2})?\/)?checkouts?\/?$/i;

  if (!KEY && !PREVIEW) {
    console.warn('[CheckoutCapture] missing data-key — widget disabled.');
    return;
  }

  // Mirrors Shop::DEFAULT_SETTINGS / Shop::FIELDS so the popup still works if the config call fails.
  const DEFAULT_SETTINGS = {
    design: 'classic',
    direction: 'ltr',
    eyebrow: '',
    title: 'Before you check out',
    subtitle: 'Leave your email and we\'ll save your cart, so you don\'t lose it on the way.',
    image_url: '',
    submit_label: 'Continue to checkout',
    skip_label: 'Continue without filling',
    close_label: 'Close',
    privacy_note: '',
    error_email: 'Please enter a valid email address',
    error_required: 'This field is required',
    allow_skip: false,
    url_capture: true,
    url_param: 'email',
    form_capture: false,
    abandoned_carts: false,
    accent_color: '#000000',
    extra_selectors: '',
    fields: [
      { key: 'email', type: 'email', label: 'Email', required: true },
      { key: 'full_name', type: 'text', label: 'Full name', required: false },
      { key: 'accepts_marketing', type: 'checkbox', label: 'Email me news and offers', required: false },
    ],
  };

  // The shopper's details also live in a first-party cookie, so they survive cleared localStorage.
  const COOKIE_NAME = 'cc_details';
  const COOKIE_MAX_AGE = 180 * 24 * 60 * 60;

  const AUTOCOMPLETE = {
    email: 'email', full_name: 'name', first_name: 'given-name', last_name: 'family-name', phone: 'tel', birthday: 'bday',
  };

  const state = {
    config: { enabled: true, mode: 'popup', storefrontToken: null, settings: DEFAULT_SETTINGS },
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

  const cookie = {
    get() {
      try {
        const match = document.cookie.split('; ').find((row) => row.startsWith(`${COOKIE_NAME}=`));
        return match ? JSON.parse(decodeURIComponent(match.slice(COOKIE_NAME.length + 1))) : null;
      } catch (e) { return null; }
    },
    set(details) {
      // Only what's needed to recognise the shopper — not the full form.
      const value = encodeURIComponent(JSON.stringify({ email: details.email, source: details.source }));
      const secure = location.protocol === 'https:' ? '; Secure' : '';
      document.cookie = `${COOKIE_NAME}=${value}; Max-Age=${COOKIE_MAX_AGE}; Path=/; SameSite=Lax${secure}`;
    },
    remove() {
      document.cookie = `${COOKIE_NAME}=; Max-Age=0; Path=/; SameSite=Lax`;
    },
  };

  const emit = (name, detail) => {
    document.dispatchEvent(new CustomEvent(`checkout-capture:${name}`, { detail }));
  };

  const settings = () => state.config.settings;

  /* --------------------------------------------------------------- config */

  function loadConfig() {
    if (PREVIEW) {
      const inline = document.getElementById('checkout-capture-config');
      applyConfig(inline ? JSON.parse(inline.textContent) : {});
      return Promise.resolve();
    }

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
    const merged = { ...DEFAULT_SETTINGS, ...(config.settings || {}) };
    if (!Array.isArray(merged.fields) || !merged.fields.some((f) => f.key === 'email')) {
      merged.fields = DEFAULT_SETTINGS.fields;
    }
    state.config = {
      enabled: config.enabled !== false,
      mode: config.mode || 'popup',
      storefrontToken: config.storefrontToken || null,
      settings: merged,
    };
  }

  /* -------------------------------------------------------------- details */

  function knownDetails() {
    const stored = storage.get(STORAGE_KEY) || cookie.get() || state.memoryDetails;
    if (stored && EMAIL_RE.test(stored.email || '')) return stored;
    if (EMAIL_RE.test(CUSTOMER_EMAIL)) return { email: CUSTOMER_EMAIL, source: 'customer' };
    return null;
  }

  function rememberDetails(details) {
    const record = { ...details, savedAt: new Date().toISOString() };
    state.memoryDetails = record;
    if (!PREVIEW) {
      storage.set(STORAGE_KEY, record);
      cookie.set(record);
    }
    return record;
  }

  // The API knows popup | url | form; anything else (e.g. a stored record without a source) counts as popup.
  const apiSource = (d) => (['url', 'form'].includes(d.source) ? d.source : 'popup');

  const displayName = (d) => d.name || [d.firstName, d.lastName].filter(Boolean).join(' ') || null;

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
    if (PREVIEW) return null;

    const attributes = { _capture_email: details.email };
    const name = displayName(details);
    if (name) attributes._capture_name = name;
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
    if (PREVIEW) return Promise.resolve(null);

    return fetchJson(`${API}/api/v1/captures`, {
      method: 'POST',
      credentials: 'omit',
      // keepalive lets the request finish even if checkout navigation starts first.
      keepalive: true,
      body: JSON.stringify({
        key: KEY,
        source: apiSource(details),
        email: details.email,
        name: details.name || null,
        first_name: details.firstName || null,
        last_name: details.lastName || null,
        phone: details.phone || null,
        birthday: details.birthday || null,
        accepts_marketing: !!details.acceptsMarketing,
        accepts_sms: !!details.acceptsSms,
        website: details.website || '',
        locale: document.documentElement.lang || navigator.language || null,
        page_url: location.href.slice(0, 2000),
        cart: cartSnapshot(cart),
      }),
    });
  }

  /**
   * Billing counts one activation per cart the module attached an email to on the way
   * to checkout. Logged-in customers aren't counted — Shopify already has their email.
   */
  function recordActivation(details, cart) {
    if (PREVIEW || !details || details.source === 'customer') return;

    fetchJson(`${API}/api/v1/activations`, {
      method: 'POST',
      credentials: 'omit',
      keepalive: true,
      body: JSON.stringify({
        key: KEY,
        email: details.email,
        source: apiSource(details),
        cart_token: (cart && cart.token) || null,
      }),
    }).catch(noop);
  }

  /** Attach to the cart, then count the activation. Never throws. */
  async function activate(details) {
    const cart = await attachToCart(details).catch(() => null);
    recordActivation(details, cart);
    return cart;
  }

  /* ------------------------------------------------------- email from URL */

  /**
   * Campaign links (?email=dana@example.com) identify the shopper with no form.
   * The email is saved like a popup submission and the parameter is removed from
   * the address bar so it doesn't end up in analytics or shared links.
   */
  async function captureFromUrl() {
    const s = settings();
    if (!state.config.enabled || !s.url_capture || !s.url_param) return;

    const url = new URL(location.href);
    const raw = (url.searchParams.get(s.url_param) || '').trim();
    if (!raw) return;

    url.searchParams.delete(s.url_param);
    try { history.replaceState(history.state, '', url.toString()); } catch (e) { /* sandboxed frame */ }

    const email = raw.toLowerCase();
    if (!EMAIL_RE.test(email)) return;

    const known = knownDetails();
    const details = rememberDetails(known && known.email === email && known.source !== 'customer'
      ? { ...known, email }
      : { email, source: 'url' });

    const cart = await getCart().catch(() => null);
    await Promise.allSettled([saveRemote({ ...details, source: 'url' }, cart), attachToCart(details)]);
    emit('captured', { details, popup: false });
  }

  /* ------------------------------------------------- email from store forms */

  const EMAIL_INPUT_SELECTOR = 'input[type="email"], input[name*="email" i], input[autocomplete="email"], input[id*="email" i]';
  const formCaptured = new Set();

  /**
   * Shoppers often type their email into the store's own forms (newsletter, contact,
   * account). With form capture on, a valid email there identifies them like the popup
   * does. The popup's own inputs live in Shadow DOM, so their events never reach here.
   */
  function onFormEmail(event) {
    if (!state.config.enabled || !settings().form_capture) return;
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches(EMAIL_INPUT_SELECTOR)) return;
    if (input.type === 'password' || input.closest('[data-checkout-capture-ignore]')) return;
    captureTypedEmail(input.value);
  }

  function onFormSubmit(event) {
    if (!state.config.enabled || !settings().form_capture) return;
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.closest('[data-checkout-capture-ignore]')) return;
    form.querySelectorAll(EMAIL_INPUT_SELECTOR).forEach((input) => captureTypedEmail(input.value));
  }

  async function captureTypedEmail(value) {
    const email = String(value || '').trim().toLowerCase();
    if (!EMAIL_RE.test(email) || formCaptured.has(email)) return;
    formCaptured.add(email);

    const known = knownDetails();
    if (known && known.email === email && known.source !== 'customer') return; // already identified

    const details = rememberDetails({ email, source: 'form' });
    const cart = await getCart().catch(() => null);
    await saveRemote(details, cart).catch(noop);
    emit('captured', { details, popup: false });
    trackCart();
  }

  /* ------------------------------------------------------ abandoned carts */

  const CART_SIGNATURE_KEY = 'checkoutCapture:cartSig';
  const ACTIVITY_PING_MS = 2 * 60 * 1000;
  let trackTimer = null;

  /**
   * Reports an identified shopper's cart to the server (debounced). The server marks it
   * abandoned once the shop's idle time passes without the shopper heading to checkout.
   */
  function trackCart(delay = 600) {
    if (PREVIEW || !state.config.enabled || !settings().abandoned_carts) return;
    clearTimeout(trackTimer);
    trackTimer = setTimeout(() => sendCart().catch(noop), delay);
  }

  async function sendCart() {
    const details = knownDetails();
    if (!details) return;

    const cart = await getCart().catch(() => null);
    if (!cart || !cart.token) return;

    const snapshot = cartSnapshot(cart);
    const signature = JSON.stringify([details.email, snapshot.token, snapshot.items.map((i) => [i.variant_id, i.quantity])]);
    const last = storage.get(CART_SIGNATURE_KEY, window.sessionStorage);

    if (!last && snapshot.item_count === 0) return; // nothing in the cart and nothing tracked yet
    // Same cart as last time: only refresh the activity clock every couple of minutes.
    if (last && last.signature === signature && Date.now() - last.at < ACTIVITY_PING_MS) return;

    storage.set(CART_SIGNATURE_KEY, { signature, at: Date.now() }, window.sessionStorage);
    await fetchJson(`${API}/api/v1/carts`, {
      method: 'POST',
      credentials: 'omit',
      keepalive: true,
      body: JSON.stringify({
        key: KEY,
        email: details.email,
        source: details.source === 'customer' ? 'customer' : apiSource(details),
        cart: snapshot,
      }),
    });
  }

  /** Theme add-to-cart requests (/cart/add, /cart/change, …) show up as resource timings. */
  function watchCartRequests() {
    if (PREVIEW || !('PerformanceObserver' in window)) return;
    try {
      new PerformanceObserver((list) => {
        if (list.getEntries().some((entry) => /\/cart\/(add|change|update|clear)(\.js)?(\?|$)/.test(entry.name))) trackCart();
      }).observe({ type: 'resource', buffered: false });
    } catch (e) { /* resource timing unsupported */ }
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
    if (PREVIEW) return;
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
    // Link-only mode has nothing to do for a shopper we don't know — let the click through untouched.
    if (state.config.mode === 'url' && !knownDetails()) return;

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
        await activate(known);
        emit('checkout', { details: known, popup: false });
        return resume(trigger, known);
      }

      if (state.config.mode !== 'popup') return resume(trigger, null);

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
      transform: translateY(24px); opacity: 0;
      transition: transform var(--ease), opacity var(--ease);
    }
    .is-open .dialog { transform: none; opacity: 1; }

    .body { padding: 40px 24px calc(28px + env(safe-area-inset-bottom)); }

    .close {
      position: absolute; inset-block-start: 12px; inset-inline-end: 12px; z-index: 2;
      width: 40px; height: 40px; padding: 0; border: 0; background: none; cursor: pointer;
      color: var(--ink); font: inherit; font-size: 28px; font-weight: 300; line-height: 1;
      opacity: .6; transition: opacity var(--ease);
    }
    .close:hover, .close:focus-visible { opacity: 1; }

    .eyebrow {
      margin: 0 0 12px; font-size: 14px; font-weight: 500; letter-spacing: 3px; text-transform: uppercase; color: var(--muted);
    }
    [dir="rtl"] .eyebrow { letter-spacing: 2px; }
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
      width: 100%; min-height: 40px; padding: 8px 0; margin: 0;
      border: 0; border-block-end: 1px solid var(--rule); border-radius: 0;
      background: transparent; color: var(--ink);
      font: inherit; font-size: 18px; font-weight: 400;
      outline: none; transition: border-color var(--ease);
      -webkit-appearance: none; appearance: none;
    }
    .field input:focus { border-block-end: 2px solid var(--ink); padding-block-end: 7px; }
    .field input[aria-invalid="true"] { border-block-end-color: var(--accent-warn); }
    .field input[type="email"], .field input[type="tel"], .field input[type="date"] { direction: ltr; text-align: start; }
    [dir="rtl"] .field input[type="email"], [dir="rtl"] .field input[type="tel"], [dir="rtl"] .field input[type="date"] { text-align: right; }
    .error { font-size: 14px; color: var(--accent-warn); }
    .error:empty { display: none; }

    .row { display: grid; gap: 24px; }

    .consents { display: grid; gap: 12px; }
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

    .notice { margin: 0; padding: 12px 0; font-size: 14px; color: var(--muted); text-align: center; border-block-start: 1px solid var(--rule); }

    .hp { position: absolute !important; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); opacity: 0; }

    /* Split design: brand image (or oversized type) beside the form. */
    .media {
      position: relative; overflow: hidden;
      display: flex; align-items: flex-end;
      min-height: 160px; padding: 24px;
      background: var(--accent-primary); color: var(--paper);
    }
    .media img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .media-title { position: relative; margin: 0; font-size: 40px; font-weight: 900; line-height: .95; letter-spacing: 0; }
    .media-title .light { display: block; font-weight: 300; }
    .design-split .close { background: var(--paper); opacity: 1; }
    .design-split .body { padding-block-start: 32px; }

    @media (min-width: 768px) {
      .backdrop { align-items: center; padding: 24px; }
      .dialog { max-width: 480px; }
      .body { padding: 56px 48px 40px; }
      .title { font-size: 40px; line-height: .95; }
      .row.two { grid-template-columns: 1fr 1fr; }

      .design-split { display: grid; grid-template-columns: 1fr 1fr; max-width: 880px; min-height: 540px; }
      .design-split .media { min-height: 100%; padding: 48px; }
      .design-split .media-title { font-size: 64px; }
      .design-split .body { display: flex; flex-direction: column; justify-content: center; padding: 64px 48px 48px; }
    }

    @media (prefers-reduced-motion: reduce) {
      .backdrop, .dialog, .submit, .close, .skip, .field input { transition: none; }
    }
  `;

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));

  const safeImageUrl = (value) => (/^https?:\/\//i.test(value || '') ? value : '');

  function inputHtml(field) {
    const { key, type, label, required } = field;
    return `
      <label class="field">
        <span class="field-label">${escapeHtml(label)}${required ? ' *' : ''}</span>
        <input name="${key}" type="${type}" autocomplete="${AUTOCOMPLETE[key] || 'off'}"
          ${type === 'email' ? 'inputmode="email" spellcheck="false" autocapitalize="off"' : ''}
          ${type === 'tel' ? 'inputmode="tel"' : ''}
          ${type === 'date' ? `max="${new Date().toISOString().slice(0, 10)}"` : ''}
          ${required ? 'required aria-required="true"' : ''}
          aria-describedby="cc-error-${key}">
        <span class="error" id="cc-error-${key}" role="alert"></span>
      </label>`;
  }

  /** Renders fields in the configured order; first + last name share a row on desktop. */
  function fieldsHtml(fields) {
    const inputs = fields.filter((f) => f.type !== 'checkbox');
    const consents = fields.filter((f) => f.type === 'checkbox');
    let html = '';

    for (let i = 0; i < inputs.length; i++) {
      const field = inputs[i];
      const next = inputs[i + 1];
      const pair = next && ['first_name', 'last_name'].includes(field.key) && ['first_name', 'last_name'].includes(next.key);
      if (pair) {
        html += `<div class="row two">${inputHtml(field)}${inputHtml(next)}</div>`;
        i++;
      } else {
        html += inputHtml(field);
      }
    }

    if (consents.length) {
      html += `<div class="consents">${consents.map((f) => `
        <label class="consent">
          <input type="checkbox" name="${f.key}">
          <span>${escapeHtml(f.label)}</span>
        </label>`).join('')}</div>`;
    }

    return html;
  }

  /** Oversized two-weight title for the split panel when no image is set: first word light, rest black. */
  function mediaTitleHtml(title) {
    const words = String(title || '').trim().split(/\s+/);
    if (words.length < 2) return `<p class="media-title" id="cc-title">${escapeHtml(title)}</p>`;
    return `<p class="media-title" id="cc-title"><span class="light">${escapeHtml(words[0])}</span>${escapeHtml(words.slice(1).join(' '))}</p>`;
  }

  function buildPopup() {
    const s = settings();
    const rtl = s.direction === 'rtl';
    const split = s.design === 'split';
    const image = safeImageUrl(s.image_url);
    // Split without an image puts the title in the coloured panel instead of above the form.
    const titleInPanel = split && !image;
    const host = document.createElement('div');
    host.setAttribute('data-checkout-capture-root', '');
    const root = host.attachShadow({ mode: 'open' });

    const media = split ? `
      <div class="media" part="media">
        ${image ? `<img src="${escapeHtml(image)}" alt="" loading="eager" decoding="async">` : mediaTitleHtml(s.title)}
      </div>` : '';

    root.innerHTML = `
      <style>${STYLES}</style>
      <div class="backdrop" part="backdrop" style="--cc-accent:${escapeHtml(s.accent_color || '#000000')}">
        <div class="dialog design-${split ? 'split' : 'classic'}" part="dialog" role="dialog" aria-modal="true" aria-labelledby="cc-title"
          ${s.subtitle ? 'aria-describedby="cc-subtitle"' : ''} dir="${rtl ? 'rtl' : 'ltr'}" lang="${escapeHtml(document.documentElement.lang || (rtl ? 'he' : 'en'))}">
          <button type="button" class="close" data-action="close" aria-label="${escapeHtml(s.close_label || 'Close')}">&times;</button>
          ${media}
          <div class="body">
            ${s.eyebrow ? `<p class="eyebrow">${escapeHtml(s.eyebrow)}</p>` : ''}
            ${titleInPanel ? '' : `<h2 class="title" id="cc-title">${escapeHtml(s.title)}</h2>`}
            ${s.subtitle ? `<p class="subtitle" id="cc-subtitle">${escapeHtml(s.subtitle)}</p>` : ''}
            <form novalidate>
              ${fieldsHtml(s.fields)}
              <input class="hp" name="website" type="text" tabindex="-1" autocomplete="off" aria-hidden="true">
              <button type="submit" class="submit">${escapeHtml(s.submit_label)}</button>
              ${s.allow_skip ? `<button type="button" class="skip" data-action="skip">${escapeHtml(s.skip_label)}</button>` : ''}
              <p class="privacy">${escapeHtml(s.privacy_note)}</p>
            </form>
          </div>
        </div>
      </div>`;

    return { host, root };
  }

  function validate(form) {
    const s = settings();
    const value = (key) => (form.elements[key] && form.elements[key].type !== 'checkbox' ? form.elements[key].value.trim() : '');
    const checked = (key) => !!(form.elements[key] && form.elements[key].checked);
    const errors = {};

    const values = {
      email: value('email'),
      name: value('full_name'),
      firstName: value('first_name'),
      lastName: value('last_name'),
      phone: value('phone'),
      birthday: value('birthday'),
      acceptsMarketing: checked('accepts_marketing'),
      acceptsSms: checked('accepts_sms'),
      website: form.elements.website ? form.elements.website.value : '',
    };

    s.fields.forEach((field) => {
      if (field.type === 'checkbox') return;
      const v = value(field.key);
      if (field.required && !v) errors[field.key] = s.error_required;
    });
    if (!EMAIL_RE.test(values.email)) errors.email = s.error_email;
    if (values.phone && !PHONE_RE.test(values.phone)) errors.phone = s.error_required;

    s.fields.forEach((field) => {
      if (field.type === 'checkbox') return;
      const input = form.elements[field.key];
      if (!input) return;
      input.setAttribute('aria-invalid', errors[field.key] ? 'true' : 'false');
      form.querySelector(`#cc-error-${field.key}`).textContent = errors[field.key] || '';
    });

    return { values, errors };
  }

  /**
   * Resolves with the submitted details, `{ skipped: true }`, or `null` when dismissed.
   */
  function openPopup(trigger) {
    if (state.popup) state.popup.close();

    return new Promise((resolve) => {
      const { host, root } = buildPopup();
      const backdrop = root.querySelector('.backdrop');
      const form = root.querySelector('form');
      const submit = root.querySelector('.submit');
      const previousOverflow = document.documentElement.style.overflow;
      const cartPromise = PREVIEW ? Promise.resolve(null) : getCart().catch(() => null);
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

        if (PREVIEW) {
          const notice = document.createElement('p');
          notice.className = 'notice';
          notice.textContent = 'Preview — nothing was saved. On the store the shopper now continues to checkout.';
          form.replaceChildren(notice);
          emit('captured', { details: values, preview: true });
          return;
        }

        submit.setAttribute('aria-busy', 'true');
        submit.disabled = true;

        const details = rememberDetails({
          email: values.email.toLowerCase(),
          name: values.name || null,
          firstName: values.firstName || null,
          lastName: values.lastName || null,
          phone: values.phone || null,
          birthday: values.birthday || null,
          acceptsMarketing: values.acceptsMarketing,
          acceptsSms: values.acceptsSms,
          source: 'popup',
        });

        const cart = await withTimeout(cartPromise, 1500).catch(() => null);
        const [, attached] = await Promise.allSettled([
          saveRemote({ ...details, website: values.website }, cart),
          attachToCart(details),
        ]);
        // After the capture request, so the activation can be linked to the saved lead.
        recordActivation(details, (attached.status === 'fulfilled' && attached.value) || cart);

        emit('captured', { details, popup: true });
        finish(details);
      });

      state.popup = { close: () => finish(null) };
    });
  }

  /* ---------------------------------------------------------------- boot */

  state.configReady = loadConfig();
  state.configReady
    .then(captureFromUrl)
    .catch((error) => console.warn('[CheckoutCapture] link email not captured.', error))
    .then(() => trackCart(0));

  if (!PREVIEW) {
    window.addEventListener('click', onClick, true);
    document.addEventListener('change', onFormEmail, true);
    document.addEventListener('submit', onFormSubmit, true);
    watchCartRequests();
  }

  // Back button from checkout restores the page from bfcache — make sure nothing is stuck.
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
      state.busy = false;
      state.passthrough = false;
      if (state.popup) state.popup.close();
    }
  });

  window.CheckoutCapture = {
    version: '1.3.0',
    /** For themes that start checkout from JS: `await CheckoutCapture.ensure()` before redirecting. */
    async ensure() {
      await withTimeout(state.configReady, 1500).catch(noop);
      if (!state.config.enabled) return null;
      const known = knownDetails();
      if (known) { await activate(known); return known; }
      if (state.config.mode !== 'popup') return null;
      const details = await openPopup(null);
      return details && !details.skipped ? details : null;
    },
    open: async () => {
      await withTimeout(state.configReady, 1500).catch(noop);
      return openPopup(null);
    },
    details: () => knownDetails(),
    forget() { state.memoryDetails = null; storage.remove(STORAGE_KEY); cookie.remove(); },
  };
})();
