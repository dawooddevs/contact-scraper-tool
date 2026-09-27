import { h, clear, debounce, stagger } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { on } from '../lib/state.js';
import { button, emptyState, skeletonRows, showError } from '../lib/ui.js';
import { num } from '../lib/format.js';
import { campaignRow } from './common.js';
import { openWizard } from './wizard.js';

const FILTERS = [
  ['all', 'All'],
  ['running', 'Running'],
  ['paused', 'Paused'],
  ['completed', 'Completed'],
  ['draft', 'Drafts'],
  ['stopped', 'Stopped'],
  ['failed', 'Failed'],
];

export async function mount(ctx) {
  ctx.setTitle('Campaigns', [{ label: 'Workspace' }]);
  const state = {
    status: ctx.query.get('status') || 'all',
    q: ctx.query.get('q') || '',
    page: Math.max(1, Number(ctx.query.get('page')) || 1),
  };

  const chips = h('div', { class: 'chips' });
  const search = h('input', { class: 'input input-sm', type: 'search', placeholder: 'Search campaigns…', value: state.q, 'data-search': true, 'aria-label': 'Search campaigns' });
  const listEl = h('div', { class: 'campaign-list' });
  const pager = h('div', { class: 'pagination hidden' });
  const card = h('div', { class: 'card' },
    h('div', { class: 'toolbar' }, chips, h('div', { class: 'grow' }), h('div', { class: 'input-group search' }, search)),
    listEl,
    pager,
  );
  ctx.el.append(card);

  const rows = new Map();
  let loading = false;

  function renderChips(counts = {}) {
    clear(chips);
    FILTERS.forEach(([id, label]) => {
      const n = counts[id] ?? 0;
      if (id !== 'all' && id !== state.status && !n && ['stopped', 'failed', 'draft'].includes(id)) return;
      chips.append(h('button', {
        type: 'button',
        class: ['chip', state.status === id && 'active'],
        onClick: () => {
          state.status = id;
          state.page = 1;
          load();
        },
      }, label, h('span', { class: 'count' }, num(n))));
    });
  }

  async function load({ quiet = false } = {}) {
    if (loading) return;
    loading = true;
    ctx.setQuery({ status: state.status !== 'all' ? state.status : '', q: state.q, page: state.page > 1 ? state.page : '' });
    if (!quiet) clear(listEl).append(skeletonRows(6));
    try {
      const res = await api.get('campaigns.list', { status: state.status, q: state.q, page: state.page, per_page: 20 });
      if (!ctx.isActive()) return;
      renderChips(res.counts);
      clear(listEl);
      rows.clear();
      if (!res.items.length) {
        listEl.append(state.q || state.status !== 'all'
          ? emptyState({ title: 'No matching campaigns', text: 'Try a different filter or search term.', action: button('Clear filters', { onClick: () => { state.status = 'all'; state.q = ''; search.value = ''; load(); } }) })
          : emptyState({ title: 'No campaigns yet', text: 'A campaign searches a directory (like Yellow Pages or Yelp) for a category in a city and keeps only the leads that match your conditions.', action: button('Create campaign', { variant: 'primary', icon: 'plus', onClick: () => openWizard({ navigate: ctx.navigate }) }) }));
      }
      res.items.forEach((c) => {
        const row = campaignRow(c, { onOpen: (x) => ctx.navigate(`campaigns/${x.id}`), onChanged: (updated, action) => (action === 'duplicate' && updated ? ctx.navigate(`campaigns/${updated.id}`) : load({ quiet: true })) });
        rows.set(c.id, row);
        listEl.append(row);
      });
      if (!quiet) stagger(listEl);
      renderPager(res);
    } catch (e) {
      if (ctx.isActive()) {
        clear(listEl).append(emptyState({ title: 'Could not load campaigns', text: e.message, action: button('Retry', { icon: 'refresh', onClick: () => load() }) }));
        showError(e);
      }
    } finally {
      loading = false;
    }
  }

  function renderPager(res) {
    const pages = Math.max(1, Math.ceil(res.total / res.per_page));
    pager.classList.toggle('hidden', pages <= 1);
    clear(pager).append(
      h('span', {}, `${num(res.total)} campaign${res.total === 1 ? '' : 's'}`),
      h('div', { class: 'pages' },
        button('', { icon: 'chevron-left', size: 'sm', title: 'Previous page', onClick: () => { state.page--; load(); } }),
        h('span', { class: 'small', style: { alignSelf: 'center', padding: '0 8px' } }, `Page ${state.page} of ${pages}`),
        button('', { icon: 'chevron-right', size: 'sm', title: 'Next page', onClick: () => { state.page++; load(); } }),
      ),
    );
    const [prev, , next] = pager.querySelector('.pages').children;
    prev.disabled = state.page <= 1;
    next.disabled = state.page >= pages;
  }

  search.addEventListener('input', debounce(() => {
    state.q = search.value.trim();
    state.page = 1;
    load();
  }, 300));

  ctx.onCleanup(on('campaigns:live', (items) => {
    let statusChanged = false;
    items.forEach((c) => {
      const row = rows.get(c.id);
      if (!row) return;
      if (row.dataset.status && row.dataset.status !== c.status) statusChanged = true;
      row.dataset.status = c.status;
      row.update(c);
    });
    if (statusChanged && state.status !== 'all') load({ quiet: true });
  }));
  ctx.onCleanup(on('campaign:changed', () => load({ quiet: true })));
  ctx.onCleanup(on('campaign:deleted', () => load({ quiet: true })));
  ctx.onCleanup(on('campaign:created', () => load({ quiet: true })));

  await load();
}
