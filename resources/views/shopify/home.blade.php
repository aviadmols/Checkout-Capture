@extends('shopify.layout')

@section('content')
<s-page heading="Checkout Capture">
    <s-button slot="primary-action" variant="primary" href="/shopify/settings">Settings</s-button>
    <div id="home"><s-spinner accessibilityLabel="Loading"></s-spinner></div>
</s-page>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', async () => {
  const { esc, api, toast, money, ago, goTop, failed } = App;
  const root = document.getElementById('home');

  const modeText = { popup: 'Popup form', url: 'Email from link only', off: 'Off' };

  function billingBanner(s) {
    if (s.billing.active) return '';
    const used = `${s.usage.checkouts} of ${s.billing.free_limit} free checkouts used this cycle.`;
    return s.billing.serving
      ? `<s-banner tone="info" heading="You're on the free tier">${esc(used)} Approve the plan so the app keeps working past ${s.billing.free_limit}. ${esc(s.billing.terms)}
           <s-button slot="secondary-actions" data-action="subscribe">Approve plan</s-button></s-banner>`
      : `<s-banner tone="critical" heading="Paused — free tier used up">${esc(used)} Checkout works as usual, but emails aren't being captured. Approve the plan to resume.
           <s-button slot="secondary-actions" data-action="subscribe">Approve plan</s-button></s-banner>`;
  }

  function stat(label, value) {
    return `<s-box padding="base" border="base" borderRadius="base">
      <s-text color="subdued">${esc(label)}</s-text>
      <s-heading>${esc(value)}</s-heading>
    </s-box>`;
  }

  function render(s) {
    const live = s.setup.embed_live;
    root.innerHTML = `
      <s-stack gap="base" direction="block">
        ${billingBanner(s)}
        <s-section heading="Setup">
          <s-stack gap="base" direction="block">
            <s-stack direction="inline" gap="base" alignItems="center" justifyContent="space-between">
              <s-stack direction="block" gap="small-200">
                <s-text type="strong">1. Turn on the app embed in your theme</s-text>
                <s-text color="subdued">${live ? `Live on your store — last seen ${esc(ago(s.setup.widget_seen_at))}.` : 'Not detected on your storefront yet. Open the theme editor, switch Checkout Capture on and save.'}</s-text>
              </s-stack>
              ${live ? '<s-badge tone="success">Live</s-badge>' : '<s-button data-action="embed">Open theme editor</s-button>'}
            </s-stack>
            <s-divider></s-divider>
            <s-stack direction="inline" gap="base" alignItems="center" justifyContent="space-between">
              <s-stack direction="block" gap="small-200">
                <s-text type="strong">2. Choose how it works</s-text>
                <s-text color="subdued">Currently: ${esc(modeText[s.settings.mode] || s.settings.mode)}.</s-text>
              </s-stack>
              <s-button href="/shopify/settings">Settings</s-button>
            </s-stack>
            <s-divider></s-divider>
            <s-stack direction="inline" gap="base" alignItems="center" justifyContent="space-between">
              <s-stack direction="block" gap="small-200">
                <s-text type="strong">3. Check the popup</s-text>
                <s-text color="subdued">Opens a preview with your saved settings. Nothing is saved there.</s-text>
              </s-stack>
              <s-button data-action="preview">Preview popup</s-button>
            </s-stack>
          </s-stack>
        </s-section>
        <s-section heading="This billing cycle (since ${esc(s.usage.period_start)})">
          <s-query-container><s-grid gridTemplateColumns="@container (inline-size > 600px) 1fr 1fr 1fr, 1fr" gap="base">
            ${stat('Checkouts created', s.usage.checkouts.toLocaleString())}
            ${stat('Plan', s.usage.price ? `${s.usage.plan} · $${s.usage.price}` : s.usage.plan)}
            ${stat('Orders received', s.usage.orders.toLocaleString())}
            ${stat('Revenue from those orders', money(s.usage.revenue, s.usage.revenue_currency))}
            ${stat('Leads captured (all time)', s.usage.leads_total.toLocaleString())}
            ${stat('Abandoned carts waiting', s.usage.abandoned_open.toLocaleString())}
          </s-grid></s-query-container>
        </s-section>
        <s-section heading="Pricing">
          <s-paragraph>${esc(s.billing.terms)} A checkout counts once per cart, when the app attached an email and sent the shopper to checkout. Logged-in customers aren't counted.</s-paragraph>
        </s-section>
      </s-stack>`;

    root.querySelectorAll('[data-action]').forEach((el) => el.addEventListener('click', () => act(el.dataset.action, s, el)));
  }

  async function act(action, s, el) {
    if (action === 'embed') return goTop(s.setup.embed_url);
    if (action === 'preview') return window.open(s.setup.preview_url, '_blank');
    if (action === 'subscribe') {
      el.loading = true;
      try {
        const { confirmation_url } = await api('billing/subscribe', { method: 'POST' });
        goTop(confirmation_url);
      } catch (error) {
        el.loading = false;
        toast(error.message, true);
      }
    }
  }

  try { render(await api('state')); } catch (error) { failed(root, error); }
});
</script>
@endpush
