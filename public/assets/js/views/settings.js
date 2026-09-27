// Settings: connection, scraping, worker & cron, account, system.

import { h, ic, clear } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { state, emit } from '../lib/state.js';
import { button, withLoading, tabs, field, input, settingSwitch, segmented, notify, showError, alertBox, confirmDialog, showFieldErrors, clearFieldErrors, emptyState, skeletonRows } from '../lib/ui.js';
import { num, ago, bytes } from '../lib/format.js';

const TABS = [
  { id: 'connection', label: 'Connection', icon: 'plug' },
  { id: 'scraping', label: 'Scraping', icon: 'sliders' },
  { id: 'worker', label: 'Worker & cron', icon: 'clock' },
  { id: 'account', label: 'Account', icon: 'user' },
  { id: 'system', label: 'System', icon: 'server' },
];

const MODE_INFO = {
  direct: { icon: 'server', desc: 'Requests leave from your hosting server’s IP. Free, but directories often block shared-hosting IPs.' },
  proxy: { icon: 'shield', desc: 'Rotate through your own HTTP/SOCKS proxies (e.g. residential proxies).' },
  scraperapi: { icon: 'cloud', desc: 'scraperapi.com — handles proxies, CAPTCHAs and retries. Free trial credits.', recommended: true, key: 'connection.scraperapi_key', url: 'https://www.scraperapi.com/' },
  scrapingbee: { icon: 'cloud', desc: 'scrapingbee.com — premium/stealth proxies and JS rendering.', recommended: true, key: 'connection.scrapingbee_key', url: 'https://www.scrapingbee.com/' },
  zenrows: { icon: 'cloud', desc: 'zenrows.com — anti-bot bypass with premium proxies.', key: 'connection.zenrows_key', url: 'https://www.zenrows.com/' },
  scrapedo: { icon: 'cloud', desc: 'scrape.do — rotating proxies with a “super” residential mode.', key: 'connection.scrapedo_key', url: 'https://scrape.do/' },
  custom: { icon: 'link', desc: 'Any other scraping API: provide a URL template containing {url}.' },
};

export async function mount(ctx) {
  const tab = TABS.some((t) => t.id === ctx.params.tab) ? ctx.params.tab : 'connection';
  ctx.setTitle('Settings', [{ label: 'Configuration' }]);

  const bar = tabs(TABS, tab, (id) => ctx.navigate(`settings/${id}`));
  const panel = h('div', { class: 'tab-panel' }, skeletonRows(6));
  ctx.el.append(bar, panel);

  let data;
  try {
    data = await api.get('settings.get');
  } catch (e) {
    clear(panel).append(h('div', { class: 'card' }, emptyState({ title: 'Could not load settings', text: e.message })));
    return;
  }
  if (!ctx.isActive()) return;
  clear(panel);
  const v = { ...data.values };

  const save = async (btn, keys, root) => withLoading(btn, async () => {
    clearFieldErrors(root);
    const values = {};
    keys.forEach((k) => { values[k] = v[k]; });
    try {
      const res = await api.post('settings.update', { values });
      Object.assign(v, res.values);
      data = res;
      notify.success('Settings saved');
      emit('settings:changed', res.values);
      state.catalogue = null;
      return true;
    } catch (e) {
      if (e.fields && showFieldErrors(root, e.fields)) return false;
      showError(e, 'Could not save settings');
      return false;
    }
  });

  ({ connection: renderConnection, scraping: renderScraping, worker: renderWorker, account: renderAccount, system: renderSystem })[tab](panel, { v, data, save, ctx });
}

/* ----------------------------------------------------------- connection */

function renderConnection(panel, { v, data, save }) {
  const root = h('div', { class: 'stack' });
  const modeGrid = h('div', { class: 'option-grid', style: { gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))' } });
  Object.entries(data.connection_modes).forEach(([mode, label]) => {
    const info = MODE_INFO[mode] || { icon: 'plug', desc: '' };
    const radio = h('input', { type: 'radio', name: 'conn-mode', value: mode, checked: v['connection.mode'] === mode });
    radio.addEventListener('change', () => {
      v['connection.mode'] = mode;
      renderFields();
    });
    modeGrid.append(h('label', { class: 'option-card' }, radio, h('span', { class: 'oc-icon' }, ic(info.icon)),
      h('span', {}, h('strong', {}, label, info.recommended ? h('span', { class: 'badge accent', style: { marginLeft: '6px', height: '19px', fontSize: '10.5px' } }, 'Recommended') : null), h('small', {}, info.desc)),
      h('span', { class: 'oc-check' }, ic('check'))));
  });

  const fieldsSlot = h('div');
  const testResult = h('div');

  function secretInput(key) {
    const el = input({ type: 'password', value: v[key] || '', placeholder: 'Paste your API key', autocomplete: 'off', spellcheck: 'false' });
    el.addEventListener('input', () => { v[key] = el.value.trim(); });
    el.addEventListener('focus', () => { if (el.value === '********') el.select(); });
    return el;
  }

  function renderFields() {
    clear(fieldsSlot);
    const mode = v['connection.mode'];
    const info = MODE_INFO[mode] || {};
    const card = h('div', { class: 'card card-pad stack' });
    if (info.key) {
      card.append(field(`${data.connection_modes[mode]} API key`, secretInput(info.key), { name: info.key, hint: h('span', {}, 'Get a key at ', h('a', { href: info.url, target: '_blank', rel: 'noopener noreferrer' }, info.url.replace(/^https:\/\/(www\.)?/, '').replace(/\/$/, '')), '. It is stored in your database and never shown again in full.') }));
    }
    if (mode === 'proxy') {
      const ta = h('textarea', { class: 'textarea mono', rows: 6, placeholder: 'http://user:pass@1.2.3.4:8080\nsocks5://5.6.7.8:1080\n9.9.9.9:3128:user:pass' }, v['connection.proxies'] || '');
      ta.addEventListener('input', () => { v['connection.proxies'] = ta.value; });
      card.append(field('Proxies (one per line)', ta, { name: 'connection.proxies', hint: 'A random proxy is used for each request. Formats: scheme://user:pass@host:port, host:port or host:port:user:pass.' }));
      card.append(settingSwitch({ label: 'Verify SSL through proxies', description: 'Turn off for proxy providers that re-sign HTTPS traffic (e.g. Bright Data Web Unlocker).', checked: !!v['connection.proxy_verify_ssl'], onChange: (x) => { v['connection.proxy_verify_ssl'] = x; } }));
    }
    if (mode === 'custom') {
      const tpl = input({ value: v['connection.custom_template'] || '', placeholder: 'https://api.example.com/?key=XXX&url={url}&country={country}', spellcheck: 'false' });
      tpl.addEventListener('input', () => { v['connection.custom_template'] = tpl.value.trim(); });
      card.append(field('API URL template', tpl, { name: 'connection.custom_template', hint: '{url} = URL-encoded target, {url_raw} = raw target, {country} = two-letter country code of the source.' }));
    }
    if (!['direct', 'proxy'].includes(mode)) {
      card.append(
        settingSwitch({ label: 'Render JavaScript', description: 'Use a real browser on the API side. Slower and costs more credits; enable if pages come back without listings.', checked: !!v['connection.render_js'], onChange: (x) => { v['connection.render_js'] = x; } }),
        settingSwitch({ label: 'Premium / residential proxies', description: 'Needed for heavily protected sites such as Yelp (DataDome) and often Yellow Pages.', checked: !!v['connection.premium'], onChange: (x) => { v['connection.premium'] = x; } }),
        settingSwitch({ label: 'Geo-target by source country', description: 'Requests appear to come from the directory’s country (AU for Yellow Pages, US for Yelp).', checked: !!v['connection.geo_target'], onChange: (x) => { v['connection.geo_target'] = x; } }),
      );
    }
    if (mode === 'direct') {
      card.append(alertBox('warning', 'Direct mode on shared hosting:', 'Yellow Pages and Yelp use bot protection and frequently block data-center IPs. If a source test comes back “Blocked”, switch to a scraping API — most offer free trial credits.'));
    }
    fieldsSlot.append(card);
  }
  renderFields();

  const siteSeg = segmented([{ value: 'direct', label: 'Direct (free)' }, { value: 'same', label: 'Same as directories' }], v['connection.website_mode'], (x) => { v['connection.website_mode'] = x; });

  const testBtns = [['ip', 'Check IP', 'wifi'], ['yellowpages_au', 'Test Yellow Pages', 'zap'], ['yelp', 'Test Yelp', 'zap']].map(([target, label, icon]) =>
    button(label, { icon, onClick: (e) => withLoading(e.currentTarget, async () => {
      clear(testResult);
      try {
        const payload = { target, mode: v['connection.mode'] };
        ['scraperapi_key', 'scrapingbee_key', 'zenrows_key', 'scrapedo_key', 'custom_template', 'proxies'].forEach((k) => { payload[k] = v[`connection.${k}`]; });
        ['render_js', 'premium', 'geo_target'].forEach((k) => { payload[k] = !!v[`connection.${k}`]; });
        const res = await api.post('settings.test_connection', payload);
        testResult.append(h('div', { class: 'mt-3' }, alertBox(res.ok ? 'success' : 'danger', res.ok ? 'Success.' : 'Failed.', `${res.message} (HTTP ${res.http_status || '—'}, ${(res.time_ms / 1000).toFixed(1)}s via ${res.via})`)));
      } catch (err) {
        testResult.append(h('div', { class: 'mt-3' }, alertBox('danger', 'Failed.', err.message)));
      }
    }) }));

  const saveBtn = button('Save connection', { variant: 'primary', icon: 'check' });
  saveBtn.addEventListener('click', () => save(saveBtn, [
    'connection.mode', 'connection.proxies', 'connection.proxy_verify_ssl', 'connection.scraperapi_key', 'connection.scrapingbee_key',
    'connection.zenrows_key', 'connection.scrapedo_key', 'connection.custom_template', 'connection.render_js', 'connection.premium',
    'connection.geo_target', 'connection.website_mode',
  ], root));

  root.append(
    h('div', { class: 'card' },
      h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'How Scrap reaches directories'), h('div', { class: 'sub' }, 'Applies to Yellow Pages, Yelp and future sources (each source can override it on the Sources page).'))),
      h('div', { class: 'card-body' }, modeGrid)),
    fieldsSlot,
    h('div', { class: 'card card-pad' },
      h('div', { class: 'flex justify-between items-center wrap gap-3' },
        h('div', {}, h('strong', {}, 'Business websites'), h('p', { class: 'muted small mt-1' }, 'Email discovery visits each business’s own site. These rarely block, so direct is usually fine and saves API credits.')),
        siteSeg)),
    h('div', { class: 'card card-pad' },
      h('strong', {}, 'Test before you save'),
      h('p', { class: 'muted small mt-1 mb-4' }, 'Runs a live request with the values above (saved or not).'),
      h('div', { class: 'btn-group' }, testBtns),
      testResult),
    h('div', { class: 'flex', style: { justifyContent: 'flex-end' } }, saveBtn),
  );
  panel.append(root);
}

/* ------------------------------------------------------------- scraping */

function numberField(v, key, label, { min, max, step = 1, hint, suffix } = {}) {
  const el = input({ type: 'number', min, max, step, value: v[key], inputmode: 'numeric' });
  el.addEventListener('input', () => { v[key] = el.value === '' ? '' : Number(el.value); });
  return field(suffix ? `${label} (${suffix})` : label, el, { name: key, hint });
}

function renderScraping(panel, { v, save }) {
  const root = h('div', { class: 'stack' });
  const saveBtn = button('Save scraping settings', { variant: 'primary', icon: 'check' });
  saveBtn.addEventListener('click', () => save(saveBtn, [
    'scrape.request_delay_ms', 'scrape.timeout', 'scrape.api_timeout', 'scrape.block_pause_threshold', 'scrape.directory_concurrency',
    'enrich.pages_per_site', 'enrich.concurrency', 'enrich.timeout', 'enrich.stop_when_found', 'data.keep_rejected', 'data.log_retention_days',
  ], root));

  root.append(
    h('div', { class: 'card' },
      h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Directory requests'), h('div', { class: 'sub' }, 'Politeness and resilience when reading Yellow Pages, Yelp, …'))),
      h('div', { class: 'card-body' },
        h('div', { class: 'row' },
          numberField(v, 'scrape.request_delay_ms', 'Delay between requests', { min: 0, max: 60000, step: 100, suffix: 'ms', hint: 'Direct/proxy mode only. Randomised ±25%.' }),
          numberField(v, 'scrape.timeout', 'Timeout', { min: 5, max: 120, suffix: 'seconds' }),
          numberField(v, 'scrape.api_timeout', 'Scraping API timeout', { min: 20, max: 180, suffix: 'seconds', hint: 'APIs retry internally, so allow them longer.' }),
        ),
        h('div', { class: 'row' },
          numberField(v, 'scrape.block_pause_threshold', 'Pause after blocked requests in a row', { min: 1, max: 50, hint: 'Stops wasting requests when a site blocks you; resume after fixing the connection.' }),
          numberField(v, 'scrape.directory_concurrency', 'Parallel business-page requests', { min: 1, max: 10, hint: 'Only used with scraping APIs. Keep within your plan’s concurrency.' }),
        ),
      )),
    h('div', { class: 'card' },
      h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Email discovery'), h('div', { class: 'sub' }, 'Crawling business websites for emails and social profiles'))),
      h('div', { class: 'card-body' },
        h('div', { class: 'row' },
          numberField(v, 'enrich.pages_per_site', 'Pages per website', { min: 1, max: 10, hint: 'Home page plus the best contact/about pages.' }),
          numberField(v, 'enrich.concurrency', 'Websites in parallel', { min: 1, max: 20 }),
          numberField(v, 'enrich.timeout', 'Website timeout', { min: 5, max: 60, suffix: 'seconds' }),
        ),
        h('div', { class: 'mt-3' }, settingSwitch({ label: 'Stop when an email is found', description: 'Skips remaining pages once the home page reveals an email. Faster; turn off to collect every address.', checked: !!v['enrich.stop_when_found'], onChange: (x) => { v['enrich.stop_when_found'] = x; } })),
      )),
    h('div', { class: 'card' },
      h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Data'))),
      h('div', { class: 'card-body' },
        settingSwitch({ label: 'Keep rejected leads', description: 'Store leads that failed your conditions (with the reason) so you can review them. Turn off to save space.', checked: !!v['data.keep_rejected'], onChange: (x) => { v['data.keep_rejected'] = x; } }),
        h('div', { class: 'row mt-3' }, numberField(v, 'data.log_retention_days', 'Keep activity logs for', { min: 1, max: 3650, suffix: 'days' })),
      )),
    h('div', { class: 'flex', style: { justifyContent: 'flex-end' } }, saveBtn),
  );
  panel.append(root);
}

/* --------------------------------------------------------------- worker */

function renderWorker(panel, { v, data, save }) {
  const root = h('div', { class: 'stack' });
  const w = data.worker;
  const cron = data.cron;

  const statusCard = h('div', { class: 'grid grid-3' },
    h('div', { class: 'card stat' }, h('div', { class: 'stat-label' }, h('span', { class: 'stat-icon' }, ic('clock')), 'Cron job'),
      h('div', { class: 'stat-value', style: { fontSize: '20px' } }, w.cron_active ? 'Active' : 'Not detected'),
      h('div', { class: 'stat-foot' }, w.last_cron_at ? `Last run ${ago(w.last_cron_at)}` : 'Never ran')),
    h('div', { class: 'card stat' }, h('div', { class: 'stat-label' }, h('span', { class: 'stat-icon' }, ic('monitor')), 'Browser worker'),
      h('div', { class: 'stat-value', style: { fontSize: '20px' } }, w.browser_enabled ? 'On' : 'Off'),
      h('div', { class: 'stat-foot' }, w.last_tick_at ? `Last batch ${ago(w.last_tick_at)}` : 'No batches yet')),
    h('div', { class: 'card stat' }, h('div', { class: 'stat-label' }, h('span', { class: 'stat-icon' }, ic('layers')), 'Queue'),
      h('div', { class: 'stat-value', style: { fontSize: '20px' } }, `${num(w.queued_tasks)} tasks`),
      h('div', { class: 'stat-foot' }, `${num(w.running)} running campaign${w.running === 1 ? '' : 's'}`)),
  );

  const steps = h('ol', { class: 'text-2', style: { paddingLeft: '18px', margin: '0', lineHeight: '1.9' } },
    h('li', {}, 'Log in to cPanel and open ', h('strong', {}, 'Advanced → Cron Jobs'), '.'),
    h('li', {}, 'Under “Add New Cron Job”, choose ', h('strong', {}, 'Once Per Minute (* * * * *)'), '.'),
    h('li', {}, 'Paste the command below into “Command” and click ', h('strong', {}, 'Add New Cron Job'), '.'),
    h('li', {}, 'Come back here — “Cron job” turns ', h('strong', {}, 'Active'), ' within a minute.'),
  );
  const codeBox = (text) => h('div', { class: 'flex items-center gap-2', style: { background: 'var(--surface-2)', border: '1px solid var(--border)', borderRadius: '10px', padding: '10px 12px' } },
    h('code', { style: { flex: '1', wordBreak: 'break-all' } }, text),
    button('Copy', { size: 'sm', icon: 'copy', onClick: () => navigator.clipboard?.writeText(text).then(() => notify.success('Copied'), () => notify.error('Copy failed')) }));

  const browserSwitch = settingSwitch({ label: 'Run campaigns while the app is open', description: 'This tab processes work in short batches. Keep it on unless cron handles everything.', checked: !!v['worker.browser_enabled'], onChange: (x) => { v['worker.browser_enabled'] = x; } });
  const saveBtn = button('Save worker settings', { variant: 'primary', icon: 'check' });
  saveBtn.addEventListener('click', async () => {
    const ok = await save(saveBtn, ['worker.browser_enabled', 'worker.web_budget', 'worker.cron_budget'], root);
    if (ok) state.worker.browserEnabled = !!v['worker.browser_enabled'];
  });

  root.append(
    statusCard,
    !w.cron_active ? alertBox('info', 'Why add a cron job?', 'Shared hosting can’t run background processes, so campaigns only progress while this app is open in a browser. A cron job runs the worker every minute so campaigns keep going after you close the tab.') : alertBox('success', 'Background processing is active.', 'Campaigns keep running even when this app is closed.'),
    h('div', { class: 'card' },
      h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Set up the cron job (cPanel)'), h('div', { class: 'sub' }, 'Recommended — takes one minute'))),
      h('div', { class: 'card-body stack' },
        steps,
        field('Command', codeBox(cron.command), { hint: 'If your host uses a different PHP path, cPanel shows it at the top of the Cron Jobs page.' }),
        h('details', {}, h('summary', { class: 'strong', style: { cursor: 'pointer' } }, 'Alternative: URL-based cron (cron-job.org, EasyCron…)'),
          h('div', { class: 'stack mt-3' },
            h('p', { class: 'muted small' }, 'If you can’t use cPanel cron, have an external service call this secret URL every minute:'),
            codeBox(cron.web_url),
            h('p', { class: 'muted small' }, 'Or as a cPanel command:'),
            codeBox(cron.web_command))),
      )),
    h('div', { class: 'card' },
      h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Worker behaviour'))),
      h('div', { class: 'card-body' },
        browserSwitch,
        h('div', { class: 'row mt-3' },
          numberField(v, 'worker.web_budget', 'Browser batch length', { min: 5, max: 120, suffix: 'seconds', hint: 'Keep below your host’s PHP max_execution_time.' }),
          numberField(v, 'worker.cron_budget', 'Cron run length', { min: 10, max: 300, suffix: 'seconds', hint: '50 fits a once-per-minute cron.' }),
        ))),
    h('div', { class: 'flex', style: { justifyContent: 'flex-end' } }, saveBtn),
  );
  panel.append(root);
}

/* -------------------------------------------------------------- account */

function renderAccount(panel) {
  const user = state.user;
  const name = input({ value: user.name, autocomplete: 'name' });
  const email = input({ type: 'email', value: user.email, autocomplete: 'email' });
  const current = input({ type: 'password', autocomplete: 'current-password', placeholder: 'Required to change email or password' });
  const next = input({ type: 'password', autocomplete: 'new-password', placeholder: 'Leave blank to keep your password' });
  const root = h('div', { class: 'card', style: { maxWidth: '720px' } },
    h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Your account'), h('div', { class: 'sub' }, 'Sign-in details for this app'))),
    h('div', { class: 'card-body' },
      h('div', { class: 'row' }, field('Name', name, { name: 'name' }), field('Email', email, { name: 'email' })),
      h('div', { class: 'row' }, field('Current password', current, { name: 'current_password' }), field('New password', next, { name: 'new_password', hint: 'At least 8 characters. Changing it signs out other devices.' })),
    ),
  );
  const saveBtn = button('Save account', { variant: 'primary', icon: 'check' });
  saveBtn.addEventListener('click', () => withLoading(saveBtn, async () => {
    clearFieldErrors(root);
    try {
      const res = await api.post('auth.profile', { name: name.value, email: email.value, current_password: current.value, new_password: next.value });
      state.user = res.user;
      current.value = '';
      next.value = '';
      document.querySelectorAll('.user-card strong').forEach((el) => { el.textContent = res.user.name; });
      document.querySelectorAll('.user-card small').forEach((el) => { el.textContent = res.user.email; });
      notify.success('Account updated');
    } catch (e) {
      if (e.fields && showFieldErrors(root, e.fields)) return;
      showError(e, 'Could not update account');
    }
  }));
  root.append(h('div', { class: 'card-footer' }, saveBtn));
  panel.append(root);
}

/* --------------------------------------------------------------- system */

async function renderSystem(panel, { v, save }) {
  panel.append(skeletonRows(6));
  let info;
  try {
    info = await api.get('system.info');
  } catch (e) {
    clear(panel).append(h('div', { class: 'card' }, emptyState({ title: 'Could not load system info', text: e.message })));
    return;
  }
  clear(panel);
  const ok = (b) => h('span', { class: ['badge', b ? 'completed' : 'failed'] }, ic(b ? 'check' : 'x'), b ? 'OK' : 'Missing');
  const kv = (k, val) => h('div', { class: 'kv' }, h('span', {}, k), h('strong', {}, val));

  const appName = input({ value: v['app.name'] || 'Scrap', maxlength: 40 });
  appName.addEventListener('input', () => { v['app.name'] = appName.value; });
  const brandForm = h('div', {}, field('App name', appName, { name: 'app.name', hint: 'Shown in the sidebar, browser tab and sign-in page.' }));
  const brandSave = button('Save', { variant: 'primary', icon: 'check' });
  brandSave.addEventListener('click', async () => {
    if (await save(brandSave, ['app.name'], brandForm)) {
      state.app.name = v['app.name'];
      document.querySelectorAll('.brand-name').forEach((el) => { el.textContent = v['app.name']; });
    }
  });

  const maint = (action, label, icon, message) => button(label, { icon, onClick: async (e) => {
    const btn = e.currentTarget;
    if (!(await confirmDialog({ title: `${label}?`, message, confirmText: label, danger: true }))) return;
    await withLoading(btn, async () => {
      try {
        const res = await api.post('settings.maintenance', { action });
        notify.success(res.message);
      } catch (err) {
        showError(err);
      }
    });
  } });

  panel.append(h('div', { class: 'grid grid-2' },
    h('div', { class: 'card' },
      h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Server'), h('div', { class: 'sub' }, info.server))),
      h('div', { class: 'card-body' },
        kv('Scrap version', info.app_version),
        kv('PHP', info.php_version),
        kv('MySQL / MariaDB', info.mysql_version),
        kv('cURL', `${info.curl_version}${info.curl_http2 ? ' · HTTP/2' : ''}`),
        kv('max_execution_time', info.max_execution_time ? `${info.max_execution_time}s` : 'unlimited'),
        kv('set_time_limit()', info.set_time_limit ? 'allowed' : 'disabled'),
        kv('memory_limit', info.memory_limit),
        kv('Storage writable', info.storage_writable ? 'yes' : 'NO — fix permissions on /storage'),
        h('hr'),
        h('div', { class: 'flex wrap gap-2' }, Object.entries(info.extensions).map(([ext, loaded]) => h('span', { class: 'flex items-center gap-2 small', style: { marginRight: '10px' } }, ext, ok(loaded)))),
      )),
    h('div', { class: 'stack' },
      h('div', { class: 'card' },
        h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Database'), h('div', { class: 'sub' }, bytes(info.db_size_bytes)))),
        h('div', { class: 'card-body' },
          kv('Campaigns', num(info.counts.campaigns)),
          kv('Leads (all statuses)', num(info.counts.leads)),
          kv('Activity log entries', num(info.counts.logs)),
          kv('Queued tasks', num(info.counts.tasks)),
          h('div', { class: 'btn-group mt-4' },
            maint('clear_logs', 'Clear activity logs', 'trash', 'Deletes every activity log entry. Leads and campaigns are not affected.'),
            maint('purge_rejected', 'Delete rejected leads', 'ban', 'Permanently deletes all leads that failed their campaign conditions.'),
            maint('reset_cookies', 'Reset cookies', 'refresh', 'Clears the cookie jars used for directory sessions. Can help after repeated blocks.'),
          ))),
      h('div', { class: 'card' },
        h('div', { class: 'card-header' }, h('div', {}, h('h3', {}, 'Branding'))),
        h('div', { class: 'card-body' }, brandForm, h('div', { class: 'flex mt-4', style: { justifyContent: 'flex-end' } }, brandSave))),
    ),
  ));
}
