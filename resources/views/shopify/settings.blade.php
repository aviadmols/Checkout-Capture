@extends('shopify.layout')

@section('content')
<s-page heading="Settings">
    <s-button slot="primary-action" variant="primary" id="save" disabled>Save</s-button>
    <s-button slot="secondary-actions" id="preview">Preview popup</s-button>
    <form id="settings" onsubmit="return false"><s-spinner accessibilityLabel="Loading"></s-spinner></form>
</s-page>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', async () => {
  const { esc, api, toast, failed } = App;
  const form = document.getElementById('settings');
  const saveButton = document.getElementById('save');
  let state = null;
  let fields = [];

  const TEXT_KEYS = ['eyebrow', 'title', 'subtitle', 'submit_label', 'skip_label', 'close_label', 'error_email', 'error_required', 'privacy_note'];
  const BOOL_KEYS = ['url_capture', 'form_capture', 'abandoned_carts', 'identify_marketing_apps', 'allow_skip'];
  const MODE_HELP = {
    popup: 'Shoppers we don\'t know yet get the popup before checkout.',
    url: 'No form. When a shopper arrives from a link with their email (e.g. a campaign: ?email=…), the email is saved and attached to their order at checkout.',
    off: 'The app steps aside; checkout works as if it isn\'t installed.',
  };

  /* ---------- small builders ---------- */
  const attr = (name, value) => (value === null || value === undefined || value === '' ? '' : ` ${name}="${esc(value)}"`);
  const text = (name, label, { area = false, details = '', placeholder = '' } = {}) => {
    const v = state.settings.values[name] ?? '';
    const ph = placeholder || state.settings.defaults[name] || '';
    return area
      ? `<s-text-area label="${esc(label)}" name="${name}" rows="2"${attr('value', v)}${attr('placeholder', ph)}${attr('details', details)}></s-text-area>`
      : `<s-text-field label="${esc(label)}" name="${name}"${attr('value', v)}${attr('placeholder', ph)}${attr('details', details)}></s-text-field>`;
  };
  const check = (name, label, details = '') =>
    `<s-checkbox label="${esc(label)}" name="${name}"${state.settings.values[name] ? ' checked' : ''}${attr('details', details)}></s-checkbox>`;
  const select = (name, label, value, options, details = '') =>
    `<s-select label="${esc(label)}" name="${name}"${attr('details', details)}>${options.map(([v, l]) =>
      `<s-option value="${esc(v)}"${v === value ? ' selected' : ''}>${esc(l)}</s-option>`).join('')}</s-select>`;
  const el = (name) => form.querySelector(`[name="${name}"]`);

  /* ---------- fields list (kept in JS so it can be reordered) ---------- */
  function syncFieldsFromDom() {
    fields = fields.map((f, i) => {
      const row = form.querySelector(`[data-field-row="${i}"]`);
      if (!row) return f;
      return {
        ...f,
        enabled: f.key === 'email' ? true : row.querySelector('[data-f="enabled"]').checked,
        required: f.key === 'email' ? true : (f.type === 'checkbox' ? false : row.querySelector('[data-f="required"]').checked),
        label: row.querySelector('[data-f="label"]').value || null,
      };
    });
  }

  function fieldsHtml() {
    return fields.map((f, i) => `
      <s-box padding="base" border="base" borderRadius="base" data-field-row="${i}">
        <s-stack direction="inline" gap="base" alignItems="center" justifyContent="space-between">
          <s-text type="strong">${esc(f.name)}</s-text>
          <s-stack direction="inline" gap="small-200">
            <s-button data-move="${i}:-1" ${i === 0 ? 'disabled' : ''} accessibilityLabel="Move up">↑</s-button>
            <s-button data-move="${i}:1" ${i === fields.length - 1 ? 'disabled' : ''} accessibilityLabel="Move down">↓</s-button>
          </s-stack>
        </s-stack>
        <s-query-container><s-grid gridTemplateColumns="@container (inline-size > 600px) 1fr 1fr 1fr, 1fr" gap="base" paddingBlockStart="small">
          <s-checkbox label="Show" data-f="enabled"${f.enabled ? ' checked' : ''}${f.key === 'email' ? ' disabled' : ''}></s-checkbox>
          ${f.type === 'checkbox'
            ? '<s-text color="subdued">Consent boxes are never required.</s-text>'
            : `<s-checkbox label="Required" data-f="required"${f.required ? ' checked' : ''}${f.key === 'email' ? ' disabled' : ''}></s-checkbox>`}
          <s-text-field label="Label" data-f="label"${attr('value', f.label)}${attr('placeholder', f.default_label)}></s-text-field>
        </s-grid></s-query-container>
      </s-box>`).join('');
  }

  function renderFields() {
    const list = form.querySelector('#fields-list');
    list.innerHTML = fieldsHtml();
    list.querySelectorAll('[data-move]').forEach((button) => button.addEventListener('click', () => {
      syncFieldsFromDom();
      const [index, step] = button.dataset.move.split(':').map(Number);
      const target = index + step;
      [fields[index], fields[target]] = [fields[target], fields[index]];
      renderFields();
      dirty();
    }));
  }

  /* ---------- page ---------- */
  function render() {
    const s = state.settings;
    fields = s.fields.map((f) => ({ ...f }));

    form.innerHTML = `
      <s-stack gap="base" direction="block">
        <s-section heading="How it works">
          <s-stack gap="base" direction="block">
            ${select('mode', 'Mode', s.mode, [['popup', 'Popup form'], ['url', 'Email from link only — no form'], ['off', 'Off']], MODE_HELP[s.mode])}
            ${check('url_capture', 'Also read the email from links', 'Shoppers who arrive with ?email=… skip the popup. Always on in "Email from link only".')}
            ${text('url_param', 'Link parameter', { details: `Campaign link example: ${s.campaign_link}` })}
          </s-stack>
        </s-section>

        <s-section heading="Recognising shoppers">
          <s-stack gap="base" direction="block">
            ${check('form_capture', 'Pick up emails typed into your store\'s own forms', 'Newsletter, contact or account forms. The email is kept in a cookie and attached at checkout.')}
            ${check('identify_marketing_apps', 'Identify recognised shoppers to Klaviyo / Omnisend', 'Their own abandoned-cart flows can then send for shoppers who came from a link or a store form.')}
          </s-stack>
        </s-section>

        <s-section heading="Abandoned carts">
          <s-stack gap="base" direction="block">
            ${check('abandoned_carts', 'Track abandoned carts of recognised shoppers', 'A recognised shopper with products in the cart who goes idle without heading to checkout. You get the cart with a link that restores it — Checkout Capture never emails shoppers itself.')}
            <s-number-field label="Abandoned after (minutes idle)" name="abandon_after_minutes" min="5" max="1440" step="1"${attr('value', s.values.abandon_after_minutes)}${attr('placeholder', s.defaults.abandon_after_minutes)}></s-number-field>
            <s-url-field label="Send abandoned carts to (webhook URL)" name="webhook_url"${attr('value', s.webhook_url)} placeholder="https://hooks.example.com/abandoned-cart" details="Each abandoned cart is POSTed here as signed JSON. Shopify Flow also receives a “Cart abandoned” trigger."></s-url-field>
            ${s.webhook_secret ? `<s-text-field label="Signing secret" value="${esc(s.webhook_secret)}" readOnly details="X-Checkout-Capture-Signature = sha256= + HMAC-SHA256(raw body, secret)"></s-text-field>` : ''}
            <s-stack direction="inline"><s-button id="test-webhook" ${s.webhook_url ? '' : 'disabled'}>Send test webhook</s-button></s-stack>
          </s-stack>
        </s-section>

        <s-section heading="Popup design">
          <s-stack gap="base" direction="block">
            ${select('design', 'Layout', s.values.design, [['classic', 'Classic — centred card, bottom sheet on mobile'], ['split', 'Split — brand image beside the form']])}
            <s-url-field label="Image (Split layout)" name="image_url"${attr('value', s.values.image_url)} placeholder="https://cdn.shopify.com/…" details="Upload in Content → Files and paste the link. Without an image the panel shows the title in large type."></s-url-field>
            <s-text-field label="Button and panel colour" name="accent_color"${attr('value', s.values.accent_color)} placeholder="#000000"></s-text-field>
          </s-stack>
        </s-section>

        <s-section heading="Language and texts">
          <s-stack gap="base" direction="block">
            <s-stack direction="inline" gap="base" alignItems="end">
              ${select('direction', 'Text direction', s.values.direction, [['ltr', 'Left to right'], ['rtl', 'Right to left (Hebrew, Arabic)']])}
              <s-button data-fill="en">Fill in English</s-button>
              <s-button data-fill="he">Fill in Hebrew</s-button>
            </s-stack>
            <s-text color="subdued">Every text can be translated. Leave a text empty to use the English default shown in grey.</s-text>
            <s-query-container><s-grid gridTemplateColumns="@container (inline-size > 560px) 1fr 1fr, 1fr" gap="base">
              ${text('eyebrow', 'Small line above the title', { placeholder: 'Optional' })}
              ${text('title', 'Title')}
              ${text('submit_label', 'Submit button')}
              ${text('skip_label', 'Skip link')}
              ${text('close_label', 'Close button (screen readers)')}
              ${text('error_email', 'Invalid email message')}
              ${text('error_required', 'Required field message')}
            </s-grid></s-query-container>
            ${text('subtitle', 'Subtitle', { area: true })}
            ${text('privacy_note', 'Small print under the button', { area: true, placeholder: 'Optional' })}
          </s-stack>
        </s-section>

        <s-section heading="Form fields">
          <s-stack gap="base" direction="block">
            <s-text color="subdued">Choose which fields appear, which are required, their labels and order.</s-text>
            <div id="fields-list"></div>
          </s-stack>
        </s-section>

        <s-section heading="Behaviour">
          <s-stack gap="base" direction="block">
            ${check('allow_skip', 'Allow continuing without filling the popup')}
            ${text('extra_selectors', 'Extra checkout selectors (CSS)', { placeholder: '.my-theme-checkout-btn', details: 'Only needed when your theme\'s checkout button isn\'t a name="checkout" button or a /checkout link.' })}
          </s-stack>
        </s-section>
      </s-stack>`;

    renderFields();
    saveButton.disabled = true;

    form.querySelectorAll('[data-fill]').forEach((b) => b.addEventListener('click', () => fill(b.dataset.fill)));
    const testButton = form.querySelector('#test-webhook');
    testButton.addEventListener('click', () => testWebhook(testButton));
  }

  function dirty() { saveButton.disabled = false; }

  function fill(language) {
    const hebrew = state.settings.hebrew;
    syncFieldsFromDom();
    ['title', 'subtitle', 'submit_label', 'skip_label', 'close_label', 'error_email', 'error_required'].forEach((key) => {
      el(key).value = language === 'he' ? (hebrew.texts[key] || '') : '';
    });
    el('direction').value = language === 'he' ? 'rtl' : 'ltr';
    fields = fields.map((f) => ({ ...f, label: language === 'he' ? (hebrew.labels[f.key] || null) : null }));
    renderFields();
    dirty();
    toast(language === 'he' ? 'Hebrew texts filled in — save to apply' : 'English defaults restored — save to apply');
  }

  function payload() {
    syncFieldsFromDom();
    const settings = {};
    ['design', 'direction', 'image_url', 'accent_color', 'url_param', 'extra_selectors', ...TEXT_KEYS].forEach((key) => {
      settings[key] = el(key) ? (el(key).value || null) : null;
    });
    BOOL_KEYS.forEach((key) => { settings[key] = !!(el(key) && el(key).checked); });
    const minutes = el('abandon_after_minutes').value;
    settings.abandon_after_minutes = minutes === '' || minutes === null ? null : Number(minutes);
    settings.fields = fields.map(({ key, enabled, required, label }) => ({ key, enabled, required, label }));

    return { mode: el('mode').value, webhook_url: el('webhook_url').value || null, settings };
  }

  async function save() {
    form.querySelectorAll('[error]').forEach((node) => node.removeAttribute('error'));
    saveButton.loading = true;
    try {
      state = await api('settings', { method: 'PUT', body: JSON.stringify(payload()) });
      render();
      toast('Settings saved');
    } catch (error) {
      Object.entries(error.errors || {}).forEach(([key, messages]) => {
        const node = el(key.replace(/^settings\./, ''));
        if (node) node.error = messages[0];
      });
      toast(error.message, true);
    } finally {
      saveButton.loading = false;
    }
  }

  async function testWebhook(button) {
    button.loading = true;
    try {
      const result = await api('webhook/test', { method: 'POST' });
      toast(result.message, !result.ok);
    } catch (error) {
      toast(error.message, true);
    } finally {
      button.loading = false;
    }
  }

  // The form element survives re-renders, so these are attached once.
  form.addEventListener('input', dirty);
  form.addEventListener('change', (event) => {
    dirty();
    if (event.target.getAttribute('name') === 'mode') event.target.details = MODE_HELP[event.target.value];
  });
  saveButton.addEventListener('click', save);
  document.getElementById('preview').addEventListener('click', () => state && window.open(state.setup.preview_url, '_blank'));

  try { state = await api('state'); render(); } catch (error) { failed(form, error); }
});
</script>
@endpush
