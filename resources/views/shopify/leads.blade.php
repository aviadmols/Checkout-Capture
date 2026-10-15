@extends('shopify.layout')

@section('content')
<s-page heading="Leads">
    <s-button slot="primary-action" variant="primary" id="export">Export CSV</s-button>
    <s-section padding="none">
        <s-table id="table" paginate>
            <s-stack slot="filters" direction="inline" gap="base">
                <s-text-field label="Search" labelAccessibilityVisibility="exclusive" placeholder="Search by email" id="search"></s-text-field>
                <s-select label="Source" labelAccessibilityVisibility="exclusive" id="source">
                    <s-option value="all" selected>All sources</s-option>
                    <s-option value="popup">Popup</s-option>
                    <s-option value="url">Link</s-option>
                    <s-option value="form">Store form</s-option>
                </s-select>
            </s-stack>
            <s-table-header-row>
                <s-table-header listSlot="primary">Email</s-table-header>
                <s-table-header>Name</s-table-header>
                <s-table-header>Source</s-table-header>
                <s-table-header>Marketing</s-table-header>
                <s-table-header format="numeric">Cart</s-table-header>
                <s-table-header>Last seen</s-table-header>
            </s-table-header-row>
            <s-table-body id="rows"></s-table-body>
        </s-table>
    </s-section>
</s-page>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
  const { esc, api, toast, money, date, sourceLabel } = App;
  const table = document.getElementById('table');
  const rows = document.getElementById('rows');
  const search = document.getElementById('search');
  const source = document.getElementById('source');
  let page = 1;
  let timer = null;

  async function load() {
    table.loading = true;
    try {
      const query = new URLSearchParams({ page, source: source.value || '', q: search.value || '' });
      const result = await api(`leads?${query}`);
      rows.innerHTML = result.data.length ? result.data.map((lead) => `
        <s-table-row>
          <s-table-cell>${esc(lead.email)}</s-table-cell>
          <s-table-cell>${esc(lead.name || '—')}</s-table-cell>
          <s-table-cell><s-badge>${esc(sourceLabel(lead.source))}</s-badge></s-table-cell>
          <s-table-cell>${[lead.accepts_marketing ? 'Email' : '', lead.accepts_sms ? 'SMS' : ''].filter(Boolean).join(' · ') || '—'}</s-table-cell>
          <s-table-cell>${esc(money(lead.cart_total, lead.currency))}</s-table-cell>
          <s-table-cell>${esc(date(lead.last_captured_at))}</s-table-cell>
        </s-table-row>`).join('')
        : '<s-table-row><s-table-cell>No leads yet. They appear here once shoppers leave their email.</s-table-cell></s-table-row>';
      table.hasPreviousPage = page > 1;
      table.hasNextPage = result.has_next;
    } catch (error) {
      toast(error.message, true);
    } finally {
      table.loading = false;
    }
  }

  table.addEventListener('nextpage', () => { page++; load(); });
  table.addEventListener('previouspage', () => { page = Math.max(1, page - 1); load(); });
  source.addEventListener('change', () => { page = 1; load(); });
  search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { page = 1; load(); }, 350); });

  document.getElementById('export').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    button.loading = true;
    try {
      const { url } = await api('leads/export-link');
      window.open(url, '_blank');
    } catch (error) {
      toast(error.message, true);
    } finally {
      button.loading = false;
    }
  });

  load();
});
</script>
@endpush
