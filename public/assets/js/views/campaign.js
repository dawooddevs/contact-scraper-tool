// Campaign detail: live progress, stats, outcome breakdown, leads, activity log.

import { h, ic, clear, replace, stagger } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { on } from '../lib/state.js';
import { button, badge, progressBar, flag, countUp, emptyState, tabs, alertBox, menu, skeletonCards, showError } from '../lib/ui.js';
import { num, pct, ago, dateTime, duration, time } from '../lib/format.js';
import { campaignAction, campaignMenuItems, conditionsSummary, sourceLogo } from './common.js';
import { createLeadsTable } from './leads.js';
import { openWizard } from './wizard.js';

export async function mount(ctx) {
  const id = Number(ctx.params.id);
  ctx.setTitle('Campaign', [{ label: 'Campaigns', path: 'campaigns' }, { label: 'Loading…' }]);
  ctx.el.append(h('div', { class: 'grid grid-4' }, skeletonCards(4, 90)));

  let c;
  try {
    c = await api.get('campaigns.get', { id });
  } catch (e) {
    if (!ctx.isActive()) return;
    clear(ctx.el).append(h('div', { class: 'card' }, emptyState({
      title: e.status === 404 ? 'Campaign not found' : 'Could not load campaign',
      text: e.status === 404 ? 'It may have been deleted.' : e.message,
      action: button('Back to campaigns', { icon: 'arrow-left', onClick: () => ctx.navigate('campaigns') }),
    })));
    return;
  }
  if (!ctx.isActive()) return;
  clear(ctx.el);
  const root = ctx.el;

  // ---------------------------------------------------------------- header
  const badgeWrap = h('span', {}, badge(c.status));
  const nameEl = h('h2', {}, c.name);
  const timingEl = h('span', {});
  const head = h('div', { class: 'detail-head' },
    h('div', { class: 'dh-main' }, flag(c.country, 'lg'), h('div', { style: { minWidth: '0' } },
      h('div', { class: 'flex items-center gap-3 wrap' }, nameEl, badgeWrap),
      h('div', { class: 'dh-meta' },
        h('span', {}, sourceLogo(c.source, 'sm'), c.source_name),
        h('span', {}, ic('tag'), c.keywords.join(', ')),
        h('span', {}, ic('pin'), c.locations.join(', ')),
        h('span', {}, ic('clock'), timingEl),
      ),
    )),
  );
  root.append(head);

  const alertSlot = h('div');
  root.append(alertSlot);

  // ---------------------------------------------------------- progress card
  const pctEl = h('div', { class: 'pc-pct' }, pct(c.progress));
  const prog = progressBar(c.progress, c.status, 'lg');
  const statusLine = h('div', { class: 'muted small' });
  const notes = h('div', { class: 'pc-note' });
  const progressCard = h('div', { class: 'card progress-card' },
    h('div', { class: 'pc-top' }, h('div', {}, h('div', { class: 'muted small mb-3' }, 'Progress'), pctEl), statusLine),
    prog,
    notes,
  );

  // Outcome breakdown (qualified / rejected / duplicates / in progress)
  const outcomeBar = h('div', { class: 'outcome', role: 'img' });
  const outcomeLegend = h('div', { class: 'legend' });
  const outcomeCard = h('div', { class: 'card card-pad' }, h('div', { class: 'flex justify-between items-center mb-3' }, h('h3', { style: { fontSize: '15px' } }, 'Where listings went'), h('span', { class: 'muted small', dataset: { total: '' } })), outcomeBar, outcomeLegend);

  root.append(h('div', { class: 'grid grid-2' }, progressCard, outcomeCard));

  // ---------------------------------------------------------------- stats
  const STATS = [
    ['leads_qualified', 'Qualified leads', 'check-circle'],
    ['emails_found', 'With email', 'mail'],
    ['listings_found', 'Listings found', 'list'],
    ['websites_crawled', 'Websites crawled', 'globe'],
    ['pages_done', 'Pages scraped', 'file'],
    ['requests', 'Requests', 'activity'],
    ['errors', 'Errors & blocks', 'alert'],
  ];
  const statEls = {};
  const statsGrid = h('div', { class: 'mini-stats mt-4' }, STATS.map(([key, label]) => {
    const v = h('strong', { dataset: { value: '0' } }, '0');
    statEls[key] = v;
    return h('div', { class: 'mini-stat' }, h('small', {}, label), v);
  }));
  root.append(stagger(statsGrid));

  // ---------------------------------------------------------------- tabs
  const tabBar = tabs([
    { id: 'leads', label: 'Leads', icon: 'users', count: num(c.lead_counts.qualified) },
    { id: 'activity', label: 'Activity', icon: 'activity' },
    { id: 'rejections', label: 'Rejections', icon: 'ban', count: num(c.lead_counts.rejected) },
    { id: 'setup', label: 'Setup', icon: 'sliders' },
  ], 'leads', (t) => showTab(t));
  const tabPanel = h('div');
  root.append(h('div', { class: 'mt-5' }, tabBar, tabPanel));

  let leadsTable = null;
  let logBox = null;
  let lastLogId = 0;
  let activeTab = 'leads';

  function showTab(t) {
    activeTab = t;
    leadsTable?.destroy();
    leadsTable = null;
    logBox = null;
    clear(tabPanel);
    const panel = h('div', { class: 'tab-panel' });
    tabPanel.append(panel);
    if (t === 'leads') {
      leadsTable = createLeadsTable({ fixedCampaign: c.id, onTotal: () => {} });
      panel.append(leadsTable.el);
      leadsTable.reload();
    } else if (t === 'activity') {
      renderActivity(panel);
    } else if (t === 'rejections') {
      renderRejections(panel);
    } else {
      renderSetup(panel);
    }
  }

  async function renderActivity(panel) {
    const follow = h('input', { type: 'checkbox', checked: true });
    logBox = h('div', { class: 'log', role: 'log', 'aria-live': 'polite' });
    lastLogId = 0;
    panel.append(h('div', { class: 'card' },
      h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Activity log'), h('div', { class: 'sub' }, 'Live feed of every page, website and decision')), h('label', { class: 'check small' }, follow, 'Auto-scroll')),
      logBox,
    ));
    logBox.follow = follow;
    await pollLogs(true);
  }

  async function pollLogs(initial = false) {
    if (!logBox) return;
    try {
      const res = await api.get('campaigns.logs', { id: c.id, after: lastLogId, limit: initial ? 200 : 150 });
      if (!logBox) return;
      if (initial && !res.items.length) {
        logBox.append(h('div', { class: 'muted', style: { padding: '12px 18px' } }, 'No activity yet.'));
      }
      if (res.items.length && logBox.firstElementChild?.classList.contains('muted')) logBox.firstElementChild.remove();
      res.items.forEach((l) => {
        lastLogId = Math.max(lastLogId, l.id);
        logBox.append(h('div', { class: ['log-line', l.level] }, h('time', { title: dateTime(l.created_at) }, time(l.created_at)), h('span', { class: 'lv' }), h('span', { class: 'msg' }, l.message)));
      });
      while (logBox.children.length > 600) logBox.firstElementChild.remove();
      if (res.items.length && logBox.follow.checked) logBox.scrollTop = logBox.scrollHeight;
    } catch (e) {
      if (initial) showError(e, 'Could not load activity');
    }
  }

  function renderRejections(panel) {
    const reasons = c.reject_reasons || [];
    const total = reasons.reduce((a, r) => a + r.count, 0);
    panel.append(h('div', { class: 'grid grid-2' },
      h('div', { class: 'card' },
        h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Why leads were rejected'), h('div', { class: 'sub' }, conditionsSummary(c.options)))),
        h('div', { class: 'card-body' }, reasons.length
          ? h('div', { class: 'hbars' }, reasons.map((r) => h('div', { class: 'hbar' },
            h('div', { class: 'hb-top' }, h('span', {}, r.reason || 'Unknown'), h('b', {}, `${num(r.count)} · ${pct(r.count / total)}`)),
            h('div', { class: 'hb-track' }, h('div', { class: 'hb-fill', style: { width: `${(r.count / reasons[0].count) * 100}%`, background: 'var(--series-2)' } })))))
          : h('p', { class: 'muted' }, 'Nothing has been rejected.')),
      ),
      h('div', { class: 'card card-pad' },
        h('h3', { style: { fontSize: '15px' } }, 'Review rejected leads'),
        h('p', { class: 'text-2 mt-2' }, 'Rejected leads are kept so you can see what was filtered out. Open them in the leads table, or loosen the conditions and run a new campaign.'),
        h('div', { class: 'btn-group mt-4' },
          button('Show rejected leads', { icon: 'users', onClick: () => { tabBar.set('leads'); showTab('leads'); leadsTable?.setStatus('rejected'); } }),
          button('Duplicate with new conditions', { icon: 'copy', onClick: () => duplicateAndEdit() }),
        ),
      ),
    ));
  }

  function renderSetup(panel) {
    const o = c.options;
    const yes = (v) => (v ? 'Yes' : 'No');
    const collected = Object.entries(o.collect).filter(([, v]) => v).map(([k]) => k).join(', ');
    const rows = [
      ['Source', `${c.source_name} (${c.source_domain})`],
      ['Categories', c.keywords.join(', ')],
      ['Locations', c.locations.join(', ')],
      ['Collect', collected],
      ['Conditions', conditionsSummary(o)],
      ['Target leads', o.max_leads ? num(o.max_leads) : 'No limit'],
      ['Max pages / search', num(o.max_pages)],
      ['Crawl websites', yes(o.crawl_website)],
      ['Skip previous duplicates', yes(o.skip_duplicates)],
      ['Business domains only', yes(o.exclude_free_emails)],
      ['Created', dateTime(c.created_at)],
      ['Started', c.started_at ? dateTime(c.started_at) : '—'],
      ['Finished', c.finished_at ? dateTime(c.finished_at) : '—'],
    ];
    const dl = h('dl', { class: 'summary-list' });
    rows.forEach(([k, v]) => dl.append(h('dt', {}, k), h('dd', {}, v)));
    panel.append(h('div', { class: 'card card-pad' }, dl,
      h('div', { class: 'btn-group mt-4' },
        c.status === 'draft' ? button('Edit campaign', { icon: 'pencil', onClick: () => openWizard({ navigate: ctx.navigate, preset: c, editId: c.id }) }) : null,
        button('Duplicate', { icon: 'copy', onClick: () => duplicateAndEdit() }))));
  }

  async function duplicateAndEdit() {
    try {
      const copy = await api.post('campaigns.action', { id: c.id, action: 'duplicate' });
      ctx.navigate(`campaigns/${copy.id}`);
      setTimeout(() => openWizard({ navigate: ctx.navigate, preset: copy, editId: copy.id }), 350);
    } catch (e) {
      showError(e, 'Could not duplicate');
    }
  }

  // --------------------------------------------------------------- updates
  function actions() {
    const moreBtn = button('', { icon: 'more', title: 'More actions' });
    moreBtn.addEventListener('click', () => menu(moreBtn, campaignMenuItems(c, {
      onChanged: (updated, action) => {
        if (action === 'duplicate' && updated) ctx.navigate(`campaigns/${updated.id}`);
        else if (updated) apply(updated);
      },
      onDeleted: () => ctx.navigate('campaigns'),
    }).filter((it) => !it || !['Start', 'Pause', 'Resume', 'Stop'].includes(it.label))));
    const act = (label, icon, action, variant = '') => button(label, { icon, variant, onClick: async (e) => {
      const btn = e.currentTarget;
      btn.disabled = true;
      const updated = await campaignAction(c, action);
      btn.disabled = false;
      if (updated) apply(updated);
    } });
    return [
      c.status === 'draft' ? button('Edit', { icon: 'pencil', onClick: () => openWizard({ navigate: ctx.navigate, preset: c, editId: c.id }) }) : null,
      c.status === 'draft' ? act('Start', 'play', 'start', 'primary') : null,
      c.status === 'running' ? act('Pause', 'pause', 'pause') : null,
      ['paused', 'failed'].includes(c.status) ? act('Resume', 'play', 'resume', 'primary') : null,
      ['running', 'paused', 'failed'].includes(c.status) ? act('Stop', 'stop', 'stop') : null,
      button('Export', { icon: 'download', onClick: () => {
        if (!leadsTable) {
          tabBar.set('leads');
          showTab('leads');
        }
        setTimeout(() => leadsTable?.exportDialog(), 60);
      } }),
      moreBtn,
    ];
  }

  let prevQualified = c.stats.leads_qualified;
  function apply(next, { fromPoll = false } = {}) {
    const statusChanged = next.status !== c.status;
    c = { ...c, ...next };
    ctx.setTitle('Campaign', [{ label: 'Campaigns', path: 'campaigns' }, { label: c.name }]);
    nameEl.textContent = c.name;
    badgeWrap.replaceChildren(badge(c.status));
    pctEl.textContent = pct(c.progress);
    prog.set(c.progress, c.status);
    timingEl.textContent = c.started_at ? (c.finished_at ? `Ran for ${duration(c.started_at, c.finished_at)}` : `Running for ${duration(c.started_at)}`) : `Created ${ago(c.created_at)}`;

    const st = c.stats;
    Object.entries(statEls).forEach(([k, el]) => countUp(el, st[k]));
    if (statusChanged || !fromPoll) ctx.setActions(actions());

    // Status line + notes
    const q = c.queue || { search: 0, detail: 0, enrich: 0 };
    const statusText = {
      draft: 'Not started yet',
      running: q.search + q.detail + q.enrich > 0 ? 'Collecting leads…' : 'Finishing up…',
      paused: 'Paused',
      completed: c.finished_at ? `Completed ${ago(c.finished_at)}` : 'Completed',
      stopped: 'Stopped',
      failed: 'Failed',
    };
    statusLine.replaceChildren(c.status === 'running' ? h('span', { class: 'flex items-center gap-2' }, h('span', { class: 'live-dot' }), statusText.running) : statusText[c.status] || c.status);
    replace(notes,
      h('span', {}, ic('file'), `${num(st.pages_done)} of ~${num(Math.max(st.pages_planned, st.pages_done))} pages`),
      c.status === 'running' || c.status === 'paused' ? h('span', {}, ic('layers'), `${num(q.search)} pages · ${num(q.detail)} listings · ${num(q.enrich)} websites queued`) : null,
      c.options.max_leads ? h('span', {}, ic('target'), `${num(st.leads_qualified)} / ${num(c.options.max_leads)} target`) : null,
    );
    notes.querySelectorAll('svg').forEach((s) => { s.style.cssText = 'width:14px;height:14px'; });

    // Outcome bar
    const pending = c.pending_leads ?? c.lead_counts?.pending ?? 0;
    const parts = [
      ['Qualified', st.leads_qualified, 'var(--series-1)'],
      ['Rejected', st.leads_rejected, 'var(--series-2)'],
      ['Duplicates', st.duplicates, 'var(--series-3)'],
      ['Checking', pending, 'var(--series-4)'],
    ];
    const total = parts.reduce((a, p) => a + p[1], 0);
    clear(outcomeBar);
    clear(outcomeLegend);
    outcomeCard.querySelector('[data-total]').textContent = `${num(st.listings_found)} listings`;
    outcomeBar.setAttribute('aria-label', parts.map(([l, v]) => `${l}: ${v}`).join(', '));
    parts.forEach(([label, value, color]) => {
      if (total && value) outcomeBar.append(h('span', { style: { width: `${(value / total) * 100}%`, background: color }, 'data-tip': `${label}: ${num(value)}` }));
      outcomeLegend.append(h('span', {}, h('i', { style: { background: color } }), label, h('b', {}, num(value))));
    });
    if (!total) outcomeBar.append(h('span', { style: { width: '100%', background: 'transparent' } }));

    // Alerts
    clear(alertSlot);
    if (c.last_error && ['paused', 'failed'].includes(c.status)) {
      alertSlot.append(h('div', { class: 'mb-4' }, alertBox('danger', c.status === 'failed' ? 'Campaign failed.' : 'Campaign paused automatically.', c.last_error, [
        button('Connection settings', { size: 'sm', icon: 'plug', onClick: () => ctx.navigate('settings/connection') }),
        button('Test source', { size: 'sm', icon: 'zap', onClick: () => ctx.navigate('sources') }),
        button('Resume', { size: 'sm', variant: 'primary', icon: 'play', onClick: async () => { const u = await campaignAction(c, 'resume'); if (u) apply(u); } }),
      ])));
    } else if (c.status === 'draft') {
      alertSlot.append(h('div', { class: 'mb-4' }, alertBox('info', 'This campaign is a draft.', 'Review the setup, then start it when you are ready.', [
        button('Start now', { size: 'sm', variant: 'primary', icon: 'play', onClick: async () => { const u = await campaignAction(c, 'start'); if (u) apply(u); } }),
      ])));
    }

    // Tab counters + live leads
    tabBar.setCount('leads', num(st.leads_qualified));
    tabBar.setCount('rejections', num(st.leads_rejected));
    if (st.leads_qualified !== prevQualified) {
      prevQualified = st.leads_qualified;
      if (activeTab === 'leads') leadsTable?.reload({ quiet: true });
    }
  }

  apply(c);
  showTab('leads');

  // Poll while active; the worker pump also broadcasts snapshots.
  let timer = null;
  const poll = async () => {
    try {
      const fresh = await api.get('campaigns.get', { id: c.id });
      if (!ctx.isActive()) return;
      c.reject_reasons = fresh.reject_reasons;
      c.lead_counts = fresh.lead_counts;
      apply(fresh, { fromPoll: true });
      if (activeTab === 'activity') await pollLogs();
    } catch (e) {
      if (e.status === 404) {
        ctx.navigate('campaigns');
        return;
      }
    }
    if (ctx.isActive()) timer = setTimeout(poll, ['running'].includes(c.status) ? 2500 : 10000);
  };
  timer = setTimeout(poll, 2500);
  ctx.onCleanup(() => {
    clearTimeout(timer);
    leadsTable?.destroy();
  });
  ctx.onCleanup(on('campaigns:live', (items) => {
    const mine = items.find((x) => x.id === c.id);
    if (mine && (mine.status !== c.status || mine.stats.leads_qualified !== c.stats.leads_qualified)) {
      apply({ ...mine, queue: c.queue, pending_leads: c.pending_leads }, { fromPoll: true });
    }
  }));
  ctx.onCleanup(on('campaign:deleted', (ids) => {
    if (ids.includes(c.id)) ctx.navigate('campaigns');
  }));
}
