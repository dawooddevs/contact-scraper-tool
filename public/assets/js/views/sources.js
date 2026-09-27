// Sources: countries → directories, per-source settings and live tests.

import { h, ic, clear, stagger } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { state } from '../lib/state.js';
import { button, withLoading, switchInput, select, input, field, modal, notify, showError, flag, emptyState, skeletonCards, alertBox, showFieldErrors, clearFieldErrors } from '../lib/ui.js';
import { num, bytes } from '../lib/format.js';
import { sourceLogo } from './common.js';

export async function mount(ctx) {
  ctx.setTitle('Sources', [{ label: 'Configuration' }]);
  ctx.el.append(h('div', { class: 'grid grid-2' }, skeletonCards(2, 220)));

  let data;
  try {
    data = await api.get('sources.list');
    state.catalogue = data;
  } catch (e) {
    clear(ctx.el).append(h('div', { class: 'card' }, emptyState({ title: 'Could not load sources', text: e.message })));
    return;
  }
  if (!ctx.isActive()) return;
  clear(ctx.el);

  ctx.el.append(h('p', { class: 'text-2 mb-4', style: { maxWidth: '760px' } },
    'Each country is searched on its best business directory. Sources are plug-ins, so new countries and websites can be added without touching the rest of the app. Test a source here before running a big campaign.'));

  data.countries.forEach((country) => {
    ctx.el.append(h('div', { class: 'section-title' }, h('h2', { class: 'flex items-center gap-2' }, flag(country.code), country.name), h('span', { class: 'sub' }, `${country.sources.length} source${country.sources.length === 1 ? '' : 's'}`)));
    const list = h('div', { class: 'stack' });
    country.sources.forEach((src) => list.append(sourcePanel(src, country, data)));
    ctx.el.append(stagger(list));
  });

  ctx.el.append(h('div', { class: 'section-title' }, h('h2', {}, 'More countries')),
    h('div', { class: 'coming-soon' }, h('div', { class: 'icon-circle' }, ic('plus')),
      h('div', {}, h('strong', {}, 'Add another country or directory'), h('p', { class: 'small mt-1' }, 'Create a source class in app/Sources (copy YellowPagesAu.php), register it in app/config/sources.php and add it to a country in app/config/countries.php. It appears here and in the campaign wizard automatically.'))));
}

function sourcePanel(src, country, data) {
  const s = { ...src.settings };
  const enabled = switchInput({ checked: s.enabled, label: s.enabled ? 'Enabled' : 'Disabled', onChange: (v) => { s.enabled = v; enabled.querySelector('strong').textContent = v ? 'Enabled' : 'Disabled'; } });

  const connOptions = [{ value: 'default', label: 'Use global setting' }, ...Object.entries(data.connection_modes).map(([value, label]) => ({ value, label }))];
  const conn = select(connOptions, s.connection || 'default');
  conn.addEventListener('change', () => { s.connection = conn.value; });

  const delay = input({ type: 'number', min: 0, max: 60000, step: 100, value: s.delay_ms ?? '', placeholder: 'Default', inputmode: 'numeric' });
  delay.addEventListener('input', () => { s.delay_ms = delay.value === '' ? '' : Number(delay.value); });

  const extra = [];
  let apiKeyInput = null;
  if (src.modes) {
    const modeSel = select(Object.entries(src.modes).map(([value, label]) => ({ value, label })), s.mode || Object.keys(src.modes)[0]);
    modeSel.addEventListener('change', () => { s.mode = modeSel.value; keyField.classList.toggle('hidden', modeSel.value !== 'api'); });
    extra.push(field('Discovery method', modeSel, { name: 'mode', hint: 'The official API is reliable but paid and capped at 240 results per search; it has no websites, so business pages are still visited for those.' }));
    apiKeyInput = input({ type: 'password', value: data.yelp_api_key_set ? '********' : '', placeholder: 'Paste your Yelp API key', autocomplete: 'off' });
    const keyField = field('Yelp API key', apiKeyInput, { name: 'yelp_api_key', hint: h('span', {}, 'Create one at ', h('a', { href: 'https://www.yelp.com/developers/v3/manage_app', target: '_blank', rel: 'noopener' }, 'yelp.com/developers'), '. Stored on your server only.') });
    keyField.classList.toggle('hidden', (s.mode || 'website') !== 'api');
    extra.push(keyField);
  }

  const form = h('div', {},
    h('div', { class: 'row' },
      field('Connection', conn, { name: 'connection', hint: 'Route this directory through a specific connection.' }),
      field('Delay between requests (ms)', delay, { name: 'delay_ms', hint: 'Blank = global default. Ignored for scraping APIs.' }),
    ),
    extra.length ? h('div', { class: 'row mt-4' }, extra) : null,
  );

  const saveBtn = button('Save changes', { variant: 'primary', icon: 'check', onClick: (e) => withLoading(e.currentTarget, async () => {
    clearFieldErrors(form);
    try {
      const payload = { key: src.key, enabled: s.enabled, connection: s.connection, delay_ms: s.delay_ms ?? '' };
      if (src.modes) payload.mode = s.mode || 'website';
      if (apiKeyInput) payload.yelp_api_key = apiKeyInput.value;
      const res = await api.post('sources.update', payload);
      state.catalogue = res;
      notify.success(`${src.name} saved`);
    } catch (err) {
      if (err.fields && showFieldErrors(form, err.fields)) return;
      showError(err, 'Could not save');
    }
  }) });

  // Test box
  const kw = input({ value: src.keyword_placeholder || 'Restaurants', placeholder: 'Category' });
  const loc = input({ value: src.location_placeholder || country.locations[0] || '', placeholder: 'Location' });
  const testForm = h('div', {},
    field('Category', kw, { name: 'keyword' }),
    field('Location', loc, { name: 'location' }),
  );
  const testBtn = button('Run live test', { icon: 'zap', cls: 'btn-block mt-4', onClick: (e) => withLoading(e.currentTarget, async () => {
    clearFieldErrors(testForm);
    try {
      const res = await api.post('sources.test', { key: src.key, keyword: kw.value, location: loc.value });
      showTestResult(src, res);
    } catch (err) {
      if (err.fields && showFieldErrors(testForm, err.fields)) return;
      showError(err, 'Test failed');
    }
  }) });

  return h('div', { class: 'card source-panel' },
    h('div', { class: 'sp-main' },
      h('div', { class: 'flex justify-between items-center gap-3 wrap' },
        h('div', { class: 'source-title' }, sourceLogo(src.key), h('div', {}, h('h3', {}, src.name), h('small', {}, h('a', { href: src.url, target: '_blank', rel: 'noopener noreferrer' }, src.domain)))),
        enabled),
      h('p', { class: 'text-2 mt-3' }, src.description),
      h('div', { class: 'provides mb-4' }, src.provides.map((p) => h('span', { class: 'badge accent' }, p))),
      src.notes ? h('div', { class: 'mb-4' }, alertBox('info', '', src.notes)) : null,
      form,
      h('div', { class: 'flex justify-between items-center mt-4 wrap gap-3' },
        h('span', { class: 'muted small' }, `Currently via: ${src.connection_label}`),
        saveBtn),
    ),
    h('div', { class: 'sp-side' },
      h('h3', { style: { fontSize: '14px' } }, 'Test this source'),
      h('p', { class: 'muted small mt-1 mb-4' }, 'Fetches page 1 live and shows what the parser reads — the fastest way to check your connection works.'),
      testForm,
      testBtn,
      h('hr'),
      h('div', { class: 'kv' }, h('span', {}, 'Campaigns'), h('strong', {}, num(src.stats.campaigns))),
      h('div', { class: 'kv' }, h('span', {}, 'Qualified leads'), h('strong', {}, num(src.stats.leads))),
      h('div', { class: 'kv' }, h('span', {}, 'With email'), h('strong', {}, num(src.stats.emails))),
      h('div', { class: 'kv' }, h('span', {}, 'Results per page'), h('strong', {}, `~${src.results_per_page}`)),
    ),
  );
}

function showTestResult(src, res) {
  const tones = { ok: 'success', empty: 'info', blocked: 'danger', error: 'danger', unrecognized: 'warning' };
  const titles = { ok: 'It works!', empty: 'No results', blocked: 'Blocked', error: 'Request failed', unrecognized: 'Page not understood' };
  const table = res.listings.length
    ? h('div', { class: 'table-wrap card mt-4', style: { maxHeight: '360px' } }, h('table', { class: 'table' },
      h('thead', {}, h('tr', {}, h('th', {}, 'Business'), h('th', {}, 'Phone'), h('th', {}, 'Email'), h('th', {}, 'Website'))),
      h('tbody', {}, res.listings.map((l) => h('tr', {},
        h('td', {}, h('span', { class: 'cell-main' }, l.name), h('span', { class: 'cell-sub' }, l.address || (l.needs_detail ? 'Details fetched from business page during campaigns' : ''))),
        h('td', { class: 'nowrap' }, l.phone || h('span', { class: 'missing' }, '—')),
        h('td', {}, l.email || h('span', { class: 'missing' }, src.provides.includes('email') ? '—' : 'via website')),
        h('td', {}, l.website ? h('span', { class: 'cell-sub', style: { color: 'var(--text-2)' } }, l.website.replace(/^https?:\/\/(www\.)?/, '')) : h('span', { class: 'missing' }, '—')),
      )))))
    : null;
  modal({
    title: `${src.name} — ${titles[res.status] || res.status}`,
    icon: res.status === 'ok' ? 'check-circle' : res.status === 'empty' ? 'info' : 'alert',
    tone: ['blocked', 'error'].includes(res.status) ? 'danger' : '',
    size: 'lg',
    body: h('div', {},
      alertBox(tones[res.status] || 'info', '', res.message),
      h('div', { class: 'mini-stats mt-4' },
        h('div', { class: 'mini-stat' }, h('small', {}, 'HTTP status'), h('strong', {}, res.http_status || '—')),
        h('div', { class: 'mini-stat' }, h('small', {}, 'Response time'), h('strong', {}, `${(res.time_ms / 1000).toFixed(1)}s`)),
        h('div', { class: 'mini-stat' }, h('small', {}, 'Page size'), h('strong', {}, bytes(res.bytes))),
        h('div', { class: 'mini-stat' }, h('small', {}, 'Listings'), h('strong', {}, num(res.listings.length))),
      ),
      h('p', { class: 'muted small mt-3' }, `Via ${res.via} · ${res.url}`),
      table,
    ),
    footer: (m) => [
      ['blocked', 'unrecognized', 'error'].includes(res.status) ? button('Connection settings', { icon: 'plug', onClick: () => { m.close(); window.location.hash = '#/settings/connection'; } }) : null,
      button('Close', { variant: 'primary', onClick: () => m.close() }),
    ].filter(Boolean),
  });
}
