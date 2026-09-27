// Shared pieces used by several views.

import { h, ic } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { state, emit } from '../lib/state.js';
import { workerPump } from '../lib/worker.js';
import { badge, progressBar, flag, menu, confirmDialog, notify, showError } from '../lib/ui.js';
import { num, pct, ago } from '../lib/format.js';

export async function loadCatalogue(force = false) {
  if (!state.catalogue || force) {
    state.catalogue = await api.get('sources.list');
  }
  return state.catalogue;
}

export function sourceLogo(key, size = '') {
  const map = { yellowpages_au: ['yp', 'YP'], yelp: ['yelp', 'y'] };
  const [cls, text] = map[key] || ['generic', (key || '?').slice(0, 2).toUpperCase()];
  return h('div', { class: ['source-logo', cls], style: size === 'sm' ? { width: '30px', height: '30px', borderRadius: '9px', fontSize: '11px' } : undefined }, text);
}

export function querySummary(c) {
  const k = c.keywords || [];
  const l = c.locations || [];
  const kText = k.length > 2 ? `${k.slice(0, 2).join(', ')} +${k.length - 2}` : k.join(', ');
  const lText = l.length > 2 ? `${l.slice(0, 2).join(', ')} +${l.length - 2}` : l.join(', ');
  return `${kText} · ${lText}`;
}

export function conditionsSummary(options) {
  const req = Object.entries(options?.require || {}).filter(([, v]) => v).map(([k]) => (k === 'socials' ? 'social profile' : k));
  const parts = [];
  if (req.length) parts.push(`Must have ${req.join(options.match === 'any' ? ' or ' : ' + ')}`);
  if (options?.min_rating > 0) parts.push(`rating ≥ ${options.min_rating}`);
  if (options?.min_reviews > 0) parts.push(`${options.min_reviews}+ reviews`);
  return parts.length ? parts.join(', ') : 'No conditions — every listing is kept';
}

/** Row in the campaigns list. `refs` lets live updates patch it in place. */
export function campaignRow(c, { onOpen, onChanged } = {}) {
  const prog = progressBar(c.progress, c.status);
  const pctEl = h('span', {}, pct(c.progress));
  const pagesEl = h('span', {}, `${num(c.stats.pages_done)} pages`);
  const leadsEl = h('strong', {}, num(c.stats.leads_qualified));
  const leadsSub = h('small', {}, `${num(c.stats.emails_found)} with email`);
  const badgeWrap = h('div', {}, badge(c.status));
  const more = h('button', { class: 'btn btn-ghost btn-sm btn-icon', type: 'button', 'aria-label': 'Campaign actions' }, ic('more'));

  const row = h('div', { class: 'campaign-row', role: 'button', tabindex: '0', dataset: { id: c.id } },
    h('div', { class: 'c-name' }, flag(c.country), h('div', { style: { minWidth: '0' } }, h('strong', {}, c.name), h('small', {}, `${c.source_name} · created ${ago(c.created_at)}`))),
    h('div', { class: 'c-query' }, h('div', { class: 'truncate text-2', style: { fontSize: '13px' } }, querySummary(c)), h('div', { class: 'truncate muted small' }, conditionsSummary(c.options))),
    h('div', { class: 'c-progress' }, h('div', { class: 'meta' }, pctEl, pagesEl), prog),
    h('div', { class: 'c-leads' }, leadsEl, leadsSub),
    h('div', { style: { display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: '6px' } }, more),
  );
  row.querySelector('.c-name > div').append(h('div', { class: 'mt-1' }, badgeWrap));

  let data = c;
  row.addEventListener('click', (e) => {
    if (e.target.closest('button')) return;
    onOpen?.(data);
  });
  row.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target === row) onOpen?.(data);
  });
  more.addEventListener('click', (e) => {
    e.stopPropagation();
    menu(more, campaignMenuItems(data, { onChanged, onOpen }));
  });

  row.update = (next) => {
    data = next;
    prog.set(next.progress, next.status);
    pctEl.textContent = pct(next.progress);
    pagesEl.textContent = `${num(next.stats.pages_done)} pages`;
    if (leadsEl.textContent !== num(next.stats.leads_qualified)) {
      leadsEl.textContent = num(next.stats.leads_qualified);
      leadsEl.animate?.([{ transform: 'scale(1.18)', color: 'var(--accent-text)' }, { transform: 'scale(1)' }], { duration: 450 });
    }
    leadsSub.textContent = `${num(next.stats.emails_found)} with email`;
    badgeWrap.replaceChildren(badge(next.status));
  };
  return row;
}

export function campaignMenuItems(c, { onChanged, onOpen, onDeleted } = {}) {
  return [
    onOpen ? { label: 'Open', icon: 'arrow-up-right', onClick: () => onOpen(c) } : null,
    c.status === 'draft' ? { label: 'Start', icon: 'play', onClick: () => campaignAction(c, 'start', onChanged) } : null,
    c.status === 'running' ? { label: 'Pause', icon: 'pause', onClick: () => campaignAction(c, 'pause', onChanged) } : null,
    c.status === 'paused' || c.status === 'failed' ? { label: 'Resume', icon: 'play', onClick: () => campaignAction(c, 'resume', onChanged) } : null,
    ['running', 'paused', 'failed'].includes(c.status) ? { label: 'Stop', icon: 'stop', onClick: () => campaignAction(c, 'stop', onChanged) } : null,
    { label: 'View leads', icon: 'users', onClick: () => { window.location.hash = `#/leads?campaign=${c.id}`; } },
    { label: 'Duplicate', icon: 'copy', onClick: () => campaignAction(c, 'duplicate', onChanged) },
    { sep: true },
    { label: 'Delete', icon: 'trash', danger: true, onClick: () => deleteCampaigns([c], onDeleted || onChanged) },
  ];
}

export async function campaignAction(c, action, onChanged) {
  if (action === 'stop') {
    const ok = await confirmDialog({
      title: 'Stop this campaign?',
      message: 'Scraping stops now. Leads already collected are kept, and leads still being checked are evaluated with the data found so far. A stopped campaign cannot be resumed.',
      confirmText: 'Stop campaign',
      danger: true,
    });
    if (!ok) return null;
  }
  try {
    const updated = await api.post('campaigns.action', { id: c.id, action });
    const labels = {
      start: ['Campaign started', 'Scraping will begin in a moment.'],
      pause: ['Campaign paused', 'Resume any time — progress is kept.'],
      resume: ['Campaign resumed', 'Picking up where it left off.'],
      stop: ['Campaign stopped', 'All collected leads are kept.'],
      duplicate: ['Campaign duplicated', 'A draft copy was created.'],
    };
    notify.success(...labels[action]);
    if (action === 'start' || action === 'resume') workerPump.kick(1);
    emit('campaign:changed', updated);
    onChanged?.(updated, action);
    return updated;
  } catch (e) {
    showError(e, 'Action failed');
    return null;
  }
}

export async function deleteCampaigns(list, onDone) {
  const one = list.length === 1;
  const leads = list.reduce((a, c) => a + (c.stats?.leads_qualified || 0), 0);
  const ok = await confirmDialog({
    title: one ? `Delete “${list[0].name}”?` : `Delete ${list.length} campaigns?`,
    message: `This permanently deletes ${one ? 'the campaign' : 'these campaigns'} and ${leads ? `their ${num(leads)} collected lead${leads === 1 ? '' : 's'}` : 'their data'}. Export anything you need first.`,
    confirmText: 'Delete permanently',
    danger: true,
  });
  if (!ok) return false;
  try {
    await api.post('campaigns.delete', { ids: list.map((c) => c.id) });
    notify.success(one ? 'Campaign deleted' : `${list.length} campaigns deleted`);
    emit('campaign:deleted', list.map((c) => c.id));
    onDone?.(null, 'delete');
    return true;
  } catch (e) {
    showError(e, 'Delete failed');
    return false;
  }
}

export function statTile({ label, value, icon, foot, spark, id }) {
  const valueEl = h('div', { class: 'stat-value', dataset: { value: '0' } }, '0');
  const tile = h('div', { class: 'card stat', dataset: id ? { stat: id } : undefined },
    h('div', { class: 'stat-label' }, h('span', { class: 'stat-icon' }, ic(icon)), label),
    valueEl,
    foot ? h('div', { class: 'stat-foot' }, foot) : null,
    spark || null,
  );
  tile.valueEl = valueEl;
  tile.initial = value;
  return tile;
}
