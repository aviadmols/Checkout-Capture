@extends('shopify.layout')

@section('content')
<s-page heading="Abandoned carts">
    <s-banner tone="info" heading="We hand carts off — we never email shoppers">
        Carts of recognised shoppers who went idle without heading to checkout. Each one is sent to your webhook and to Shopify Flow's “Cart abandoned” trigger, where your own tools send the recovery message. Turn tracking on in Settings.
    </s-banner>
    <s-section padding="none">
        <s-table id="table" paginate>
            <s-stack slot="filters" direction="inline" gap="base">
                <s-select label="Status" labelAccessibilityVisibility="exclusive" id="status">
                    <s-option value="abandoned" selected>Abandoned</s-option>
                    <s-option value="active">Active</s-option>
                    <s-option value="checkout">Went to checkout</s-option>
                    <s-option value="all">All</s-option>
                </s-select>
            </s-stack>
            <s-table-header-row>
                <s-table-header listSlot="primary">Email</s-table-header>
                <s-table-header>Products</s-table-header>
                <s-table-header format="numeric">Total</s-table-header>
                <s-table-header>Abandoned</s-table-header>
                <s-table-header>Webhook</s-table-header>
                <s-table-header>Recovery link</s-table-header>
            </s-table-header-row>
            <s-table-body id="rows"></s-table-body>
        </s-table>
    </s-section>
</s-page>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
  const { esc, api, toast, money, date } = App;
  const table = document.getElementById('table');
  const rows = document.getElementById('rows');
  const status = document.getElementById('status');
  let page = 1;
  let links = [];

  const webhookBadge = (value) => (value === 'sent'
    ? '<s-badge tone="success">Sent</s-badge>'
    : value === 'failed' ? '<s-badge tone="critical">Failed</s-badge>' : '—');

  async function load() {
    table.loading = true;
    try {
      const result = await api(`carts?${new URLSearchParams({ page, status: status.value })}`);
      links = result.data.map((cart) => cart.recovery_url);
      rows.innerHTML = result.data.length ? result.data.map((cart, i) => `
        <s-table-row>
          <s-table-cell>${esc(cart.email)}</s-table-cell>
          <s-table-cell>${esc(cart.products || '—')}</s-table-cell>
          <s-table-cell>${esc(money(cart.total, cart.currency))}</s-table-cell>
          <s-table-cell>${esc(date(cart.abandoned_at || cart.last_activity_at))}</s-table-cell>
          <s-table-cell>${webhookBadge(cart.webhook_status)}</s-table-cell>
          <s-table-cell>${cart.recovery_url ? `<s-button data-copy="${i}">Copy link</s-button>` : '—'}</s-table-cell>
        </s-table-row>`).join('')
        : '<s-table-row><s-table-cell>Nothing here yet.</s-table-cell></s-table-row>';
      rows.querySelectorAll('[data-copy]').forEach((button) => button.addEventListener('click', () => copy(links[Number(button.dataset.copy)])));
      table.hasPreviousPage = page > 1;
      table.hasNextPage = result.has_next;
    } catch (error) {
      toast(error.message, true);
    } finally {
      table.loading = false;
    }
  }

  async function copy(link) {
    try {
      await navigator.clipboard.writeText(link);
      toast('Recovery link copied');
    } catch (e) {
      window.prompt('Copy the recovery link:', link);
    }
  }

  table.addEventListener('nextpage', () => { page++; load(); });
  table.addEventListener('previouspage', () => { page = Math.max(1, page - 1); load(); });
  status.addEventListener('change', () => { page = 1; load(); });

  load();
});
</script>
@endpush
