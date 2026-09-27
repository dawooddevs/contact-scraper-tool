// Leads: reusable data table (global page + inside a campaign), lead drawer, export.

import { h, ic, clear, append, debounce } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { on, prefs } from '../lib/state.js';
import { button, withLoading, emptyState, skeletonRows, showError, notify, drawer, modal, confirmDialog, copyable, copyText, select, flag, badge, saveResponse, menu, field, input, alertBox, showFieldErrors } from '../lib/ui.js';
import { num, ago, dateTime, hostOf } from '../lib/format.js';

const COLUMNS = [
  { id: 'email', label: 'Email', default: true },
  { id: 'phone', label: 'Phone', default: true },
  { id: 'website', label: 'Website', default: true },
  { id: 'location', label: 'Location', default: true },
  { id: 'rating', label: 'Rating', default: true, sort: 'rating' },
  { id: 'socials', label: 'Socials', default: true },
  { id: 'campaign', label: 'Campaign', default: false },
  { id: 'added', label: 'Added', default: true, sort: 'created_at' },
];

const SOCIAL_ORDER = ['facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'tiktok', 'pinterest'];
const SOCIAL_LABEL = { facebook: 'Facebook', instagram: 'Instagram', linkedin: 'LinkedIn', twitter: 'X / Twitter', youtube: 'YouTube', tiktok: 'TikTok', pinterest: 'Pinterest' };

export const EXPORT_COLUMNS = [
  ['name', 'Business name', true], ['email', 'Email', true], ['emails', 'All emails', false], ['phone', 'Phone', true],
  ['website', 'Website', true], ['address', 'Address', true], ['city', 'City / suburb', true], ['state', 'State', true],
  ['postcode', 'Postcode', true], ['country', 'Country', true], ['category', 'Category', true], ['rating', 'Rating', true],
  ['reviews', 'Reviews', true], ['facebook', 'Facebook', true], ['instagram', 'Instagram', true], ['linkedin', 'LinkedIn', true],
  ['twitter', 'X / Twitter', false], ['youtube', 'YouTube', false], ['tiktok', 'TikTok', false], ['latitude', 'Latitude', false],
  ['longitude', 'Longitude', false], ['source', 'Source', false], ['source_url', 'Listing URL', false], ['campaign', 'Campaign', true],
  ['keyword', 'Search keyword', false], ['search_location', 'Search location', false], ['status', 'Status', false],
  ['reject_reason', 'Reject reason', false], ['notes', 'Notes', false], ['created_at', 'Collected at', true],
];

/* ------------------------------------------------------------------ page */

export async function mount(ctx) {
  ctx.setTitle('Leads', [{ label: 'Workspace' }]);
  const q = ctx.query;
  let campaigns = [];
  try {
    const res = await api.get('campaigns.list', { per_page: 100 });
    campaigns = res.items;
  } catch {
    /* the table still works without the campaign filter */
  }
  if (!ctx.isActive()) return;

  const table = createLeadsTable({
    filters: {
      status: q.get('status') || 'qualified',
      campaign_id: q.get('campaign') || '',
      q: q.get('q') || '',
      has: (q.get('has') || '').split(',').filter(Boolean),
      starred: q.get('starred') === '1',
      sort: q.get('sort') || 'created_at',
      dir: q.get('dir') || 'desc',
      page: Number(q.get('page')) || 1,
    },
    campaigns,
    showCampaign: true,
    onFiltersChange: (f) => ctx.setQuery({
      status: f.status !== 'qualified' ? f.status : '',
      campaign: f.campaign_id,
      q: f.q,
      has: f.has.join(','),
      starred: f.starred ? '1' : '',
      sort: f.sort !== 'created_at' ? f.sort : '',
      dir: f.dir !== 'desc' ? f.dir : '',
      page: f.page > 1 ? f.page : '',
    }),
  });
  ctx.el.append(table.el);
  ctx.setActions([button('Export', { icon: 'download', onClick: () => table.exportDialog() })]);
  ctx.onCleanup(() => table.destroy());
  await table.reload();
  const open = Number(q.get('open'));
  if (open) openLeadDrawer(open, { onChange: () => table.reload({ quiet: true }) });
}

/* ------------------------------------------------------------ component */

export function createLeadsTable({ filters: initial = {}, campaigns = [], showCampaign = false, fixedCampaign = null, onFiltersChange, onTotal } = {}) {
  const f = {
    status: 'qualified',
    campaign_id: fixedCampaign ? String(fixedCampaign) : '',
    q: '',
    has: [],
    starred: false,
    sort: 'created_at',
    dir: 'desc',
    page: 1,
    per_page: prefs.get('leads-per-page', 25),
    ...initial,
  };
  if (fixedCampaign) f.campaign_id = String(fixedCampaign);
  const visible = new Set(prefs.get('lead-columns', COLUMNS.filter((c) => c.default || (showCampaign && c.id === 'campaign')).map((c) => c.id)));
  if (!showCampaign) visible.delete('campaign');

  let data = { items: [], total: 0, pages: 1 };
  let selected = new Set();
  let allMatching = false;
  const seen = new Set();
  let loading = false;
  let destroyed = false;

  // ---- toolbar
  const search = h('input', { class: 'input input-sm', type: 'search', placeholder: 'Search name, email, phone, city…', value: f.q, 'data-search': !fixedCampaign, 'aria-label': 'Search leads' });
  search.addEventListener('input', debounce(() => {
    f.q = search.value.trim();
    f.page = 1;
    reload();
  }, 300));

  const statusSel = select([
    { value: 'qualified', label: 'Qualified' },
    { value: 'rejected', label: 'Rejected' },
    ...(fixedCampaign ? [{ value: 'pending', label: 'In progress' }] : []),
    { value: 'all', label: 'All statuses' },
  ], f.status, { class: 'select input-sm', style: { width: 'auto' }, 'aria-label': 'Status' });
  statusSel.addEventListener('change', () => {
    f.status = statusSel.value;
    f.page = 1;
    reload();
  });

  let campaignSel = null;
  if (!fixedCampaign && campaigns.length) {
    campaignSel = select([{ value: '', label: 'All campaigns' }, ...campaigns.map((c) => ({ value: String(c.id), label: c.name }))], f.campaign_id, { class: 'select input-sm', style: { width: 'auto', maxWidth: '220px' }, 'aria-label': 'Campaign' });
    campaignSel.addEventListener('change', () => {
      f.campaign_id = campaignSel.value;
      f.page = 1;
      reload();
    });
  }

  const hasChips = [['email', 'Has email', 'mail'], ['phone', 'Has phone', 'phone'], ['website', 'Has website', 'globe'], ['socials', 'Has socials', 'link']].map(([key, label, icon]) => {
    const b = h('button', { type: 'button', class: ['chip', f.has.includes(key) && 'active'] }, ic(icon), label);
    b.addEventListener('click', () => {
      f.has = f.has.includes(key) ? f.has.filter((x) => x !== key) : [...f.has, key];
      b.classList.toggle('active', f.has.includes(key));
      f.page = 1;
      reload();
    });
    return b;
  });
  const starChip = h('button', { type: 'button', class: ['chip', f.starred && 'active'] }, ic('star'), 'Starred');
  starChip.addEventListener('click', () => {
    f.starred = !f.starred;
    starChip.classList.toggle('active', f.starred);
    f.page = 1;
    reload();
  });

  const colsBtn = button('', { icon: 'sliders', size: 'sm', title: 'Columns' });
  colsBtn.addEventListener('click', () => {
    menu(colsBtn, COLUMNS.filter((c) => showCampaign || c.id !== 'campaign').map((c) => ({
      label: `${visible.has(c.id) ? '✓ ' : '   '}${c.label}`,
      onClick: () => {
        if (visible.has(c.id)) visible.delete(c.id);
        else visible.add(c.id);
        prefs.set('lead-columns', [...visible]);
        renderTable();
      },
    })));
  });

  const toolbar = h('div', { class: 'toolbar' },
    h('div', { class: 'input-group search' }, ic('search'), search),
    statusSel,
    campaignSel,
    h('div', { class: 'chips' }, hasChips, starChip),
    h('div', { class: 'grow' }),
    colsBtn,
  );

  const tableHost = h('div', { class: 'table-wrap' });
  const pager = h('div', { class: 'pagination' });
  const el = h('div', { class: 'card' }, toolbar, tableHost, pager);

  // ---- bulk bar
  const bulkCount = h('strong', {});
  const selectAllLink = h('button', { type: 'button', class: 'btn btn-sm hidden' });
  selectAllLink.addEventListener('click', () => {
    allMatching = true;
    updateBulk();
  });
  const bulk = h('div', { class: 'bulkbar', role: 'region', 'aria-label': 'Bulk actions' },
    bulkCount,
    selectAllLink,
    button('Export', { icon: 'download', size: 'sm', onClick: () => exportDialog(true) }),
    button('Copy emails', { icon: 'mail', size: 'sm', onClick: copyEmails }),
    button('Star', { icon: 'star', size: 'sm', onClick: () => bulkAction('star') }),
    button('Delete', { icon: 'trash', size: 'sm', variant: 'danger', onClick: () => bulkAction('delete') }),
    button('', { icon: 'x', size: 'sm', title: 'Clear selection', onClick: () => clearSelection() }),
  );
  document.body.append(bulk);

  function scopePayload() {
    return allMatching ? { all_matching: true, filters: apiFilters() } : { ids: [...selected] };
  }

  function apiFilters() {
    return { status: f.status, campaign_id: f.campaign_id, q: f.q, has: f.has, starred: f.starred };
  }

  function updateBulk() {
    const n = allMatching ? data.total : selected.size;
    bulk.classList.toggle('show', n > 0);
    bulkCount.textContent = `${num(n)} selected`;
    const pageAll = data.items.length > 0 && data.items.every((l) => selected.has(l.id));
    selectAllLink.classList.toggle('hidden', allMatching || !pageAll || data.total <= data.items.length);
    selectAllLink.textContent = `Select all ${num(data.total)}`;
    const head = tableHost.querySelector('thead input[type=checkbox]');
    if (head) {
      head.checked = pageAll;
      head.indeterminate = !pageAll && data.items.some((l) => selected.has(l.id));
    }
  }

  function clearSelection() {
    selected = new Set();
    allMatching = false;
    tableHost.querySelectorAll('tbody input[type=checkbox]').forEach((c) => { c.checked = false; });
    tableHost.querySelectorAll('tr.selected').forEach((r) => r.classList.remove('selected'));
    updateBulk();
  }

  async function bulkAction(action) {
    const n = allMatching ? data.total : selected.size;
    if (action === 'delete') {
      const ok = await confirmDialog({ title: `Delete ${num(n)} lead${n === 1 ? '' : 's'}?`, message: 'They are removed permanently. Campaign counters are updated.', confirmText: 'Delete', danger: true });
      if (!ok) return;
    }
    try {
      const res = await api.post('leads.bulk', { action, ...scopePayload() });
      notify.success(action === 'delete' ? `${num(res.affected)} leads deleted` : `${num(res.affected)} leads starred`);
      clearSelection();
      reload({ quiet: true });
    } catch (e) {
      showError(e, 'Bulk action failed');
    }
  }

  async function copyEmails() {
    const emails = data.items.filter((l) => (allMatching || selected.has(l.id)) && l.email).map((l) => l.email);
    if (allMatching && data.total > data.items.length) {
      notify.info('Tip', 'Copy covers this page. Use Export for all matching leads.');
    }
    if (!emails.length) {
      notify.warning('No emails in the selection');
      return;
    }
    copyText(emails.join('\n'), `${emails.length} email${emails.length === 1 ? '' : 's'} copied`);
  }

  // ---- data
  async function reload({ quiet = false } = {}) {
    if (loading || destroyed) return;
    loading = true;
    onFiltersChange?.({ ...f });
    if (!quiet) clear(tableHost).append(skeletonRows(8));
    try {
      const res = await api.get('leads.list', { ...apiFilters(), page: f.page, per_page: f.per_page, sort: f.sort, dir: f.dir });
      if (destroyed) return;
      if (res.page > res.pages && res.total > 0) {
        f.page = res.pages;
        loading = false;
        return reload({ quiet });
      }
      const fresh = quiet ? new Set(res.items.filter((l) => !seen.has(l.id)).map((l) => l.id)) : new Set();
      res.items.forEach((l) => seen.add(l.id));
      data = res;
      onTotal?.(res.total);
      renderTable(fresh);
      renderPager();
    } catch (e) {
      if (!destroyed) {
        clear(tableHost).append(emptyState({ title: 'Could not load leads', text: e.message, action: button('Retry', { icon: 'refresh', onClick: () => reload() }) }));
      }
    } finally {
      loading = false;
    }
  }

  function renderTable(fresh = new Set()) {
    clear(tableHost);
    if (!data.items.length) {
      const filtered = f.q || f.has.length || f.starred || (!fixedCampaign && f.campaign_id) || f.status !== 'qualified';
      tableHost.append(filtered
        ? emptyState({ title: 'No leads match these filters', text: 'Try removing a filter or searching for something else.' })
        : emptyState({ title: fixedCampaign ? 'No qualified leads yet' : 'No leads yet', text: fixedCampaign ? 'Leads appear here the moment they pass your conditions.' : 'Run a campaign and qualified leads will land here, ready to export.' }));
      updateBulk();
      return;
    }
    const cols = COLUMNS.filter((c) => visible.has(c.id));
    const headCheck = h('input', { type: 'checkbox', 'aria-label': 'Select page' });
    headCheck.addEventListener('change', () => {
      allMatching = false;
      data.items.forEach((l) => (headCheck.checked ? selected.add(l.id) : selected.delete(l.id)));
      tableHost.querySelectorAll('tbody tr').forEach((tr) => {
        const cb = tr.querySelector('input[type=checkbox]');
        cb.checked = headCheck.checked;
        tr.classList.toggle('selected', headCheck.checked);
      });
      updateBulk();
    });
    const sortTh = (label, key, extra = '') => {
      const th = h('th', { class: ['sortable', extra, f.sort === key && 'sorted', f.sort === key && f.dir === 'asc' && 'asc'] }, label, h('span', { class: 'sort-ind', html: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>' }));
      th.addEventListener('click', () => {
        if (f.sort === key) f.dir = f.dir === 'asc' ? 'desc' : 'asc';
        else {
          f.sort = key;
          f.dir = key === 'name' ? 'asc' : 'desc';
        }
        f.page = 1;
        reload();
      });
      return th;
    };
    const thead = h('thead', {}, h('tr', {},
      h('th', { class: 'col-check' }, h('label', { class: 'check' }, headCheck)),
      sortTh('Business', 'name'),
      cols.map((c) => (c.sort ? sortTh(c.label, c.sort, c.id === 'rating' ? 'num' : '') : h('th', {}, c.label))),
      h('th', { style: { width: '44px' } }),
    ));
    const tbody = h('tbody');
    data.items.forEach((l) => tbody.append(renderRow(l, cols, fresh.has(l.id))));
    tableHost.append(h('table', { class: 'table' }, thead, tbody));
    updateBulk();
  }

  function renderRow(l, cols, isNew) {
    const cb = h('input', { type: 'checkbox', checked: allMatching || selected.has(l.id), 'aria-label': `Select ${l.name}` });
    const tr = h('tr', { class: ['clickable', (allMatching || selected.has(l.id)) && 'selected', isNew && 'row-new'] });
    cb.addEventListener('click', (e) => e.stopPropagation());
    cb.addEventListener('change', () => {
      if (allMatching) {
        allMatching = false;
        data.items.forEach((x) => selected.add(x.id));
      }
      if (cb.checked) selected.add(l.id);
      else selected.delete(l.id);
      tr.classList.toggle('selected', cb.checked);
      updateBulk();
    });
    const star = h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon', 'aria-label': l.starred ? 'Unstar' : 'Star', 'data-tip': l.starred ? 'Unstar' : 'Star' }, ic(l.starred ? 'star-fill' : 'star'));
    if (l.starred) star.style.color = 'var(--warning)';
    star.addEventListener('click', async (e) => {
      e.stopPropagation();
      try {
        const upd = await api.post('leads.update', { id: l.id, starred: !l.starred });
        l.starred = upd.starred;
        star.replaceChildren(ic(l.starred ? 'star-fill' : 'star'));
        star.style.color = l.starred ? 'var(--warning)' : '';
        star.dataset.tip = l.starred ? 'Unstar' : 'Star';
      } catch (err) {
        showError(err);
      }
    });

    const cells = cols.map((c) => {
      switch (c.id) {
        case 'email':
          return h('td', { class: 'nowrap' }, l.email ? copyable(l.email, h('a', { class: 'cell-link', href: `mailto:${l.email}`, onClick: (e) => e.stopPropagation() }, h('span', {}, l.email))) : h('span', { class: 'missing' }, '—'),
            l.emails?.length > 1 ? h('span', { class: 'badge', style: { marginLeft: '6px', height: '20px' }, 'data-tip': l.emails.slice(1).join(', ') }, `+${l.emails.length - 1}`) : null);
        case 'phone':
          return h('td', { class: 'nowrap' }, l.phone ? copyable(l.phone, h('a', { class: 'cell-link', href: `tel:${l.phone.replace(/[^\d+]/g, '')}`, onClick: (e) => e.stopPropagation() }, h('span', {}, l.phone))) : h('span', { class: 'missing' }, '—'));
        case 'website':
          return h('td', {}, l.website ? h('a', { class: 'cell-link', href: l.website, target: '_blank', rel: 'noopener noreferrer nofollow', onClick: (e) => e.stopPropagation() }, ic('external'), h('span', {}, hostOf(l.website))) : h('span', { class: 'missing' }, '—'));
        case 'location':
          return h('td', {}, h('span', { class: 'cell-sub', style: { color: 'var(--text-2)' } }, [l.city, l.state].filter(Boolean).join(', ') || l.address || '—'));
        case 'rating':
          return h('td', { class: 'num nowrap' }, l.rating !== null ? h('span', {}, `★ ${l.rating.toFixed(1)}`, l.reviews !== null ? h('span', { class: 'muted small' }, ` (${num(l.reviews)})`) : null) : h('span', { class: 'missing' }, '—'));
        case 'socials': {
          const nets = SOCIAL_ORDER.filter((n) => l.socials?.[n]);
          return h('td', {}, nets.length ? h('span', { class: 'flex gap-2' }, nets.slice(0, 4).map((n) => h('a', { href: l.socials[n], target: '_blank', rel: 'noopener noreferrer nofollow', 'data-tip': SOCIAL_LABEL[n], class: 'cell-link', onClick: (e) => e.stopPropagation() }, ic(n)))) : h('span', { class: 'missing' }, '—'));
        }
        case 'campaign':
          return h('td', {}, h('a', { class: 'cell-sub', href: `#/campaigns/${l.campaign_id}`, onClick: (e) => e.stopPropagation() }, l.campaign_name || `#${l.campaign_id}`));
        case 'added':
          return h('td', { class: 'nowrap muted small', title: dateTime(l.created_at) }, ago(l.created_at));
        default:
          return h('td');
      }
    });

    append(tr, [
      h('td', { class: 'col-check' }, h('label', { class: 'check', onClick: (e) => e.stopPropagation() }, cb)),
      h('td', {}, h('span', { class: 'cell-main' }, l.name), h('span', { class: 'cell-sub' }, l.status === 'rejected' && l.reject_reason ? `✕ ${l.reject_reason}` : (l.category || l.keyword || ''))),
      cells,
      h('td', {}, star),
    ]);
    tr.addEventListener('click', () => openLeadDrawer(l.id, {
      onChange: () => reload({ quiet: true }),
      onDelete: () => reload({ quiet: true }),
    }));
    return tr;
  }

  function renderPager() {
    const from = data.total ? (f.page - 1) * f.per_page + 1 : 0;
    const to = Math.min(data.total, f.page * f.per_page);
    const perSel = select([25, 50, 100].map((n) => ({ value: n, label: `${n} / page` })), f.per_page, { class: 'select input-sm', style: { width: 'auto' }, 'aria-label': 'Rows per page' });
    perSel.addEventListener('change', () => {
      f.per_page = Number(perSel.value);
      prefs.set('leads-per-page', f.per_page);
      f.page = 1;
      reload();
    });
    const prev = button('', { icon: 'chevron-left', size: 'sm', title: 'Previous page', onClick: () => { f.page--; reload(); } });
    const next = button('', { icon: 'chevron-right', size: 'sm', title: 'Next page', onClick: () => { f.page++; reload(); } });
    prev.disabled = f.page <= 1;
    next.disabled = f.page >= data.pages;
    clear(pager).append(
      h('span', {}, data.total ? `${num(from)}–${num(to)} of ${num(data.total)} leads` : '0 leads'),
      h('div', { class: 'flex items-center gap-2' }, perSel, prev, h('span', { class: 'small' }, `${f.page} / ${data.pages}`), next),
    );
  }

  function exportDialog(fromSelection = false) {
    const useSelection = fromSelection && (allMatching || selected.size > 0);
    const saved = prefs.get('export-columns', EXPORT_COLUMNS.filter((c) => c[2]).map((c) => c[0]));
    const boxes = EXPORT_COLUMNS.map(([id, label]) => {
      const cb = h('input', { type: 'checkbox', checked: saved.includes(id), value: id });
      return h('label', { class: 'check', style: { fontSize: '13px' } }, cb, label);
    });
    const count = useSelection ? (allMatching ? data.total : selected.size) : data.total;
    modal({
      title: 'Export leads to CSV',
      subtitle: `${num(count)} lead${count === 1 ? '' : 's'} ${useSelection ? 'selected' : 'matching the current filters'}. Opens in Excel, Google Sheets or any CRM.`,
      icon: 'download',
      size: 'lg',
      body: h('div', {},
        h('div', { class: 'flex justify-between items-center mb-3' }, h('div', { class: 'label' }, 'Columns'),
          h('div', { class: 'flex gap-2' },
            button('All', { size: 'sm', variant: 'ghost', onClick: () => boxes.forEach((b) => { b.querySelector('input').checked = true; }) }),
            button('None', { size: 'sm', variant: 'ghost', onClick: () => boxes.forEach((b) => { b.querySelector('input').checked = false; }) }))),
        h('div', { style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(160px, 1fr))', gap: '10px' } }, boxes),
        count === 0 ? h('div', { class: 'mt-4' }, alertBox('warning', 'Nothing to export.', 'No leads match the current filters.')) : null,
      ),
      footer: (m) => [
        button('Cancel', { onClick: () => m.close() }),
        button('Download CSV', {
          variant: 'primary',
          icon: 'download',
          onClick: (e) => withLoading(e.currentTarget, async () => {
            const columns = boxes.map((b) => b.querySelector('input')).filter((i) => i.checked).map((i) => i.value);
            if (!columns.length) {
              notify.warning('Choose at least one column');
              return;
            }
            prefs.set('export-columns', columns);
            try {
              const body = useSelection ? { ...scopePayload(), columns } : { all_matching: true, filters: apiFilters(), columns };
              const res = await api.download('leads.export', body);
              const name = await saveResponse(res, 'leads.csv');
              notify.success('Export ready', name);
              m.close();
            } catch (err) {
              showError(err, 'Export failed');
            }
          }),
        }),
      ],
    });
  }

  // Keep the table fresh when other parts of the app change leads.
  const off = on('lead:changed', () => reload({ quiet: true }));

  return {
    el,
    reload,
    exportDialog: () => exportDialog(false),
    get total() {
      return data.total;
    },
    setStatus(status) {
      f.status = status;
      statusSel.value = status;
      f.page = 1;
      return reload();
    },
    destroy() {
      destroyed = true;
      off();
      bulk.remove();
    },
  };
}

/* ---------------------------------------------------------- lead drawer */

export async function openLeadDrawer(id, { onChange, onDelete } = {}) {
  const d = drawer({ title: 'Loading…', body: h('div', {}, h('div', { class: 'skeleton sk-title' }), h('div', { class: 'skeleton sk-block mt-4' }), h('div', { class: 'skeleton sk-block mt-4' })) });
  let lead;
  try {
    lead = await api.get('leads.get', { id });
  } catch (e) {
    d.setBody(emptyState({ art: false, icon: 'alert', title: 'Could not load this lead', text: e.message }));
    return;
  }
  render();

  function render() {
    const starBtn = button('', { icon: lead.starred ? 'star-fill' : 'star', variant: 'ghost', size: 'sm', title: lead.starred ? 'Unstar' : 'Star' });
    if (lead.starred) starBtn.style.color = 'var(--warning)';
    starBtn.addEventListener('click', async () => {
      try {
        lead = await api.post('leads.update', { id: lead.id, starred: !lead.starred });
        render();
        onChange?.(lead);
      } catch (e) {
        showError(e);
      }
    });
    d.setHeader(h('div', { style: { flex: '1', minWidth: '0' } },
      h('div', { class: 'flex items-center gap-2 wrap mb-3' },
        badge(lead.status === 'qualified' ? 'completed' : lead.status === 'rejected' ? 'failed' : 'running', lead.status === 'qualified' ? 'Qualified' : lead.status === 'rejected' ? 'Rejected' : 'In progress'),
        lead.rating !== null ? h('span', { class: 'badge accent' }, ic('star-fill'), `${lead.rating.toFixed(1)}${lead.reviews !== null ? ` · ${num(lead.reviews)} reviews` : ''}`) : null,
        flag(lead.country),
      ),
      h('div', { class: 'flex items-center gap-2' }, h('h2', {}, lead.name), starBtn),
      h('p', { class: 'muted small mt-1' }, [lead.category, [lead.city, lead.state].filter(Boolean).join(', ')].filter(Boolean).join(' · ') || '—'),
    ));

    const tile = (iconName, label, value, href, external) => value
      ? h('a', { class: 'contact-tile', href, target: external ? '_blank' : undefined, rel: external ? 'noopener noreferrer nofollow' : undefined }, ic(iconName), h('small', {}, label), h('span', {}, value))
      : h('div', { class: 'contact-tile empty-tile' }, ic(iconName), h('small', {}, label), h('span', { class: 'muted' }, 'Not found'));

    const socials = SOCIAL_ORDER.filter((n) => lead.socials?.[n]);
    const address = [lead.address, [lead.city, lead.state, lead.postcode].filter(Boolean).join(' ')].filter(Boolean).join(', ');
    const mapsUrl = lead.latitude !== null && lead.longitude !== null
      ? `https://www.google.com/maps/search/?api=1&query=${lead.latitude},${lead.longitude}`
      : address ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${lead.name}, ${address}`)}` : null;

    const notes = h('textarea', { class: 'textarea', placeholder: 'Add a private note…', rows: 3 }, lead.notes || '');
    const notesState = h('span', { class: 'muted small' });
    notes.addEventListener('blur', async () => {
      if ((lead.notes || '') === notes.value.trim()) return;
      notesState.textContent = 'Saving…';
      try {
        lead = await api.post('leads.update', { id: lead.id, notes: notes.value });
        notesState.textContent = 'Saved';
        setTimeout(() => { notesState.textContent = ''; }, 1600);
      } catch (e) {
        notesState.textContent = '';
        showError(e, 'Could not save note');
      }
    });

    const dl = h('dl', { class: 'detail-list' });
    const row = (k, v) => v && dl.append(h('dt', {}, k), h('dd', {}, v));
    if (lead.emails.length > 1) row('All emails', h('div', { style: { display: 'flex', flexDirection: 'column', gap: '4px' } }, lead.emails.map((e) => h('div', {}, copyable(e, h('a', { href: `mailto:${e}` }, e))))));
    row('Address', address ? copyable(address) : null);
    row('Map', mapsUrl ? h('a', { href: mapsUrl, target: '_blank', rel: 'noopener noreferrer' }, 'Open in Google Maps ', ic('external')) : null);
    row('Country', lead.country ? h('span', { class: 'flex items-center gap-2' }, flag(lead.country), lead.country) : null);
    row('Socials', socials.length ? h('div', { class: 'socials' }, socials.map((n) => h('a', { class: 'social', href: lead.socials[n], target: '_blank', rel: 'noopener noreferrer nofollow' }, ic(n), SOCIAL_LABEL[n]))) : null);
    row('Found by', lead.keyword ? `“${lead.keyword}” in ${lead.search_location}` : null);
    row('Campaign', h('a', { href: `#/campaigns/${lead.campaign_id}`, onClick: () => d.close() }, lead.campaign_name || `#${lead.campaign_id}`));
    row('Listing', lead.source_url ? h('a', { href: lead.source_url, target: '_blank', rel: 'noopener noreferrer nofollow' }, hostOf(lead.source_url), ' ', ic('external')) : null);
    row('Rejected', lead.status === 'rejected' ? lead.reject_reason : null);
    row('Collected', dateTime(lead.created_at));
    dl.querySelectorAll('svg').forEach((s) => { s.style.cssText = 'width:13px;height:13px;display:inline;vertical-align:-2px'; });

    d.setBody(h('div', {},
      h('div', { class: 'contact-tiles' },
        tile('mail', 'Email', lead.email, lead.email ? `mailto:${lead.email}` : null),
        tile('phone', 'Phone', lead.phone, lead.phone ? `tel:${lead.phone.replace(/[^\d+]/g, '')}` : null),
        tile('globe', 'Website', lead.website ? hostOf(lead.website) : null, lead.website, true),
      ),
      dl,
      h('hr'),
      h('div', { class: 'flex justify-between items-center mb-3' }, h('div', { class: 'label' }, 'Notes'), notesState),
      notes,
    ));

    d.setFooter([
      button('Delete', { icon: 'trash', variant: 'ghost', onClick: async () => {
        const ok = await confirmDialog({ title: 'Delete this lead?', message: `“${lead.name}” will be removed permanently.`, confirmText: 'Delete', danger: true });
        if (!ok) return;
        try {
          await api.post('leads.bulk', { action: 'delete', ids: [lead.id] });
          notify.success('Lead deleted');
          d.close();
          onDelete?.(lead);
        } catch (e) {
          showError(e);
        }
      } }),
      h('div', { style: { flex: '1' } }),
      button('Edit', { icon: 'pencil', onClick: () => editLead() }),
      button('Copy details', { icon: 'copy', variant: 'primary', onClick: () => copyText(
        [lead.name, lead.email, lead.phone, lead.website, address].filter(Boolean).join('\n'), 'Lead details copied',
      ) }),
    ]);
  }

  function editLead() {
    const fields = {
      name: input({ value: lead.name }),
      email: input({ type: 'email', value: lead.email || '' }),
      phone: input({ value: lead.phone || '' }),
      website: input({ value: lead.website || '' }),
    };
    modal({
      title: 'Edit lead',
      icon: 'pencil',
      body: h('div', {},
        field('Business name', fields.name, { name: 'name' }),
        field('Primary email', fields.email, { name: 'email' }),
        field('Phone', fields.phone, { name: 'phone' }),
        field('Website', fields.website, { name: 'website' }),
      ),
      footer: (m) => [
        button('Cancel', { onClick: () => m.close() }),
        button('Save changes', { variant: 'primary', icon: 'check', onClick: (e) => withLoading(e.currentTarget, async () => {
          try {
            lead = await api.post('leads.update', { id: lead.id, name: fields.name.value, email: fields.email.value, phone: fields.phone.value, website: fields.website.value });
            m.close();
            render();
            notify.success('Lead updated');
            onChange?.(lead);
          } catch (err) {
            if (err.fields && showFieldErrors(m.body, err.fields)) return;
            showError(err, 'Could not save');
          }
        }) }),
      ],
    });
  }
}
