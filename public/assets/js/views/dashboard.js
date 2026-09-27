import { h, ic, clear, stagger } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { on, prefs } from '../lib/state.js';
import { timeSeries, seriesTable, hbars, sparkline } from '../lib/charts.js';
import { button, countUp, emptyState, skeletonCards, flag, progressBar, badge, alertBox, segmented } from '../lib/ui.js';
import { num, pct, ago } from '../lib/format.js';
import { statTile, querySummary } from './common.js';
import { openWizard } from './wizard.js';

export async function mount(ctx) {
  ctx.setTitle('Dashboard', [{ label: 'Overview' }]);
  const root = ctx.el;
  root.append(h('div', { class: 'grid grid-4' }, skeletonCards(4)));

  let data;
  try {
    data = await api.get('dashboard.summary', { tz_offset: -new Date().getTimezoneOffset() });
  } catch (e) {
    clear(root).append(h('div', { class: 'card' }, emptyState({ title: 'Could not load the dashboard', text: e.message, action: button('Retry', { icon: 'refresh', onClick: () => ctx.navigate('dashboard') }) })));
    return;
  }
  if (!ctx.isActive()) return;
  clear(root);

  const firstName = (document.querySelector('.user-card strong')?.textContent || '').split(' ')[0];

  // ---- Welcome hero when nothing has been run yet
  if (data.campaigns.total === 0) {
    root.append(h('div', { class: 'hero mb-4' },
      h('h2', {}, `Welcome${firstName ? ', ' + firstName : ''} 👋`),
      h('p', {}, 'Create your first campaign: pick a country, a category and a city, choose which contact details you need, and Scrap collects qualified leads for you.'),
      button('Create your first campaign', { icon: 'sparkles', onClick: () => openWizard({ navigate: ctx.navigate }) }),
    ));
  }

  // ---- Worker notice
  const w = data.worker;
  if (w.running > 0 && !w.cron_active && !w.browser_enabled) {
    root.append(h('div', { class: 'mb-4' }, alertBox('danger', 'Campaigns are waiting.', 'The browser worker is disabled and no cron job has run in the last few minutes, so nothing is being processed.', [button('Set up cron', { size: 'sm', icon: 'clock', onClick: () => ctx.navigate('settings/worker') })])));
  } else if (!w.cron_active && !prefs.get('dismiss-cron-tip', false) && data.campaigns.total > 0) {
    const tip = alertBox('info', 'Tip:', 'Campaigns run while this app is open. Add the cron job to keep them running in the background when you close the tab.', [
      button('Show me how', { size: 'sm', icon: 'clock', onClick: () => ctx.navigate('settings/worker') }),
      button('Dismiss', { size: 'sm', variant: 'ghost', onClick: () => { prefs.set('dismiss-cron-tip', true); tip.remove(); } }),
    ]);
    root.append(h('div', { class: 'mb-4' }, tip));
  }

  // ---- KPI tiles
  const series = data.series;
  const t = data.totals;
  const delta = t.prev7 === 0 ? (t.last7 > 0 ? 1 : 0) : (t.last7 - t.prev7) / t.prev7;
  const deltaEl = h('span', { class: ['delta', delta > 0 ? 'up' : delta < 0 ? 'down' : 'flat'] }, ic(delta > 0 ? 'trending-up' : delta < 0 ? 'trending-down' : 'minus'), t.prev7 === 0 && t.last7 > 0 ? 'new' : pct(Math.abs(delta)));

  const tiles = [
    statTile({ id: 'leads', label: 'Qualified leads', icon: 'users', value: t.leads, foot: [h('span', {}, 'All time')], spark: sparkline(series.map((d) => d.leads)) }),
    statTile({ id: 'emails', label: 'Leads with email', icon: 'mail', value: t.with_email, foot: [h('span', {}, t.leads ? `${pct(t.with_email / t.leads)} of leads` : 'No leads yet')] }),
    statTile({ id: 'week', label: 'Last 7 days', icon: 'activity', value: t.last7, foot: [deltaEl, h('span', {}, 'vs previous 7 days')] }),
    statTile({ id: 'active', label: 'Active campaigns', icon: 'rocket', value: data.campaigns.running, foot: [h('span', {}, `${num(data.campaigns.paused)} paused · ${num(data.campaigns.total)} total`)] }),
  ];
  const tileGrid = stagger(h('div', { class: 'grid grid-4' }, tiles));
  root.append(tileGrid);
  tiles.forEach((tile) => countUp(tile.valueEl, tile.initial));

  // ---- Chart + active campaigns
  const chartData = {
    labels: series.map((d) => d.date),
    series: [
      { label: 'Qualified leads', color: 'var(--series-1)', values: series.map((d) => d.leads) },
      { label: 'With email', color: 'var(--series-2)', values: series.map((d) => d.emails) },
    ],
    height: 260,
    ariaLabel: 'Qualified leads and leads with email per day, last 30 days',
  };
  const chartBody = h('div', { class: 'card-body', style: { paddingTop: '12px' } });
  const legend = h('div', { class: 'legend', style: { marginTop: '0', marginBottom: '8px' } },
    chartData.series.map((s) => h('span', {}, h('i', { style: { background: s.color, height: '3px', width: '14px', borderRadius: '2px' } }), s.label, ' ', h('b', {}, num(s.values.reduce((a, b) => a + b, 0))))));
  let chart = null;
  const showChart = () => {
    clear(chartBody).append(legend);
    const holder = h('div');
    chartBody.append(holder);
    chart = timeSeries(holder, chartData);
  };
  const showTable = () => {
    chart?.destroy();
    chart = null;
    clear(chartBody).append(seriesTable(chartData));
  };
  const viewToggle = segmented([{ value: 'chart', label: 'Chart' }, { value: 'table', label: 'Table' }], 'chart', (v) => (v === 'chart' ? showChart() : showTable()));
  const chartCard = h('div', { class: 'card' },
    h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Leads collected'), h('div', { class: 'sub' }, 'Last 30 days')), viewToggle),
    chartBody,
  );

  const activeList = h('div', { class: 'campaign-mini' });
  const activeCard = h('div', { class: 'card' },
    h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Active campaigns'), h('div', { class: 'sub' }, 'Live progress')), button('All', { size: 'sm', variant: 'ghost', iconRight: 'chevron-right', onClick: () => ctx.navigate('campaigns') })),
    activeList,
  );
  const rowsById = new Map();
  function renderActive(list) {
    clear(activeList);
    rowsById.clear();
    const items = list.length ? list : data.recent_campaigns.slice(0, 4);
    if (!items.length) {
      activeList.append(emptyState({ art: false, icon: 'rocket', title: 'No campaigns yet', text: 'Your running campaigns will appear here.' }));
      return;
    }
    items.forEach((c) => {
      const prog = progressBar(c.progress, c.status);
      const leads = h('b', { class: 'tnum' }, num(c.stats.leads_qualified));
      const badgeWrap = h('span', {}, badge(c.status));
      const row = h('div', { class: 'list-item', onClick: () => ctx.navigate(`campaigns/${c.id}`) },
        flag(c.country),
        h('div', { class: 'li-main' },
          h('div', { class: 'flex items-center justify-between gap-2' }, h('strong', {}, c.name), badgeWrap),
          h('small', {}, querySummary(c)),
          h('div', { class: 'flex items-center gap-2 mt-2' }, h('div', { style: { flex: '1' } }, prog), h('span', { class: 'small muted nowrap' }, leads, ' leads')),
        ),
      );
      row.update = (n) => {
        prog.set(n.progress, n.status);
        leads.textContent = num(n.stats.leads_qualified);
        badgeWrap.replaceChildren(badge(n.status));
      };
      rowsById.set(c.id, row);
      activeList.append(row);
    });
    stagger(activeList);
  }
  renderActive(data.active);

  root.append(h('div', { class: 'grid grid-main mt-4' }, chartCard, activeCard));
  showChart();

  // ---- Recent leads + activity + sources
  const leadsCard = h('div', { class: 'card' },
    h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Latest leads'), h('div', { class: 'sub' }, 'Most recently qualified')), button('View all', { size: 'sm', variant: 'ghost', iconRight: 'chevron-right', onClick: () => ctx.navigate('leads') })),
    data.recent_leads.length
      ? stagger(h('div', {}, data.recent_leads.map((l) => h('div', { class: 'list-item', onClick: () => ctx.navigate(`leads?open=${l.id}`) },
        h('div', { class: 'icon-circle' }, (l.name || '?').slice(0, 1).toUpperCase()),
        h('div', { class: 'li-main' }, h('strong', {}, l.name), h('small', {}, l.email || l.phone || l.website || '—')),
        h('div', { class: 'small muted nowrap' }, ago(l.created_at)),
      ))))
      : emptyState({ art: false, icon: 'users', title: 'No leads yet', text: 'Qualified leads show up here as campaigns run.' }),
  );

  const feedCard = h('div', { class: 'card' },
    h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Activity'), h('div', { class: 'sub' }, 'What the scraper has been doing'))),
    data.activity.length
      ? stagger(h('div', { class: 'feed' }, data.activity.map((a) => h('div', { class: ['feed-item', a.level] },
        h('span', { class: 'lv' }),
        h('div', { class: 'fi-body' }, h('p', {}, a.message), h('small', {}, a.campaign ? h('a', { href: `#/campaigns/${a.campaign_id}` }, a.campaign) : 'System', ' · ', ago(a.created_at))),
      ))))
      : emptyState({ art: false, icon: 'activity', title: 'Nothing yet', text: 'Campaign activity will stream here.' }),
  );

  const sourcesCard = h('div', { class: 'card' },
    h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Leads by source'), h('div', { class: 'sub' }, 'Qualified, all time'))),
    h('div', { class: 'card-body' }, data.sources.length
      ? hbars(data.sources.map((s) => ({ label: s.name, value: s.count, prefix: flag(s.country) })))
      : h('p', { class: 'muted' }, 'No data yet.')),
  );

  root.append(stagger(h('div', { class: 'grid grid-3 mt-4' }, leadsCard, feedCard, sourcesCard)));

  // ---- Live updates
  ctx.onCleanup(on('campaigns:live', (items) => {
    items.forEach((c) => rowsById.get(c.id)?.update(c));
    const running = items.filter((c) => c.status === 'running').length;
    countUp(tiles[3].valueEl, running);
  }));
  let lastLeads = t.leads;
  const refresh = async () => {
    try {
      const fresh = await api.get('dashboard.summary', { tz_offset: -new Date().getTimezoneOffset() });
      if (!ctx.isActive()) return;
      countUp(tiles[0].valueEl, fresh.totals.leads);
      countUp(tiles[1].valueEl, fresh.totals.with_email);
      countUp(tiles[2].valueEl, fresh.totals.last7);
      if (fresh.totals.leads !== lastLeads) {
        lastLeads = fresh.totals.leads;
        chartData.series[0].values = fresh.series.map((d) => d.leads);
        chartData.series[1].values = fresh.series.map((d) => d.emails);
        chart?.update(chartData);
      }
      if (fresh.active.map((c) => c.id).join() !== data.active.map((c) => c.id).join()) {
        data.active = fresh.active;
        renderActive(fresh.active);
      }
    } catch (e) {
      if (e.status !== 401) console.warn(e);
    }
  };
  const timer = setInterval(refresh, 15000);
  ctx.onCleanup(() => {
    clearInterval(timer);
    chart?.destroy();
  });
}
