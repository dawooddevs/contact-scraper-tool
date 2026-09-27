// Scrap — single-page app shell: auth gate, layout, hash router, theme,
// keyboard shortcuts and the background worker pump.

import { h, ic, clear, initials } from './lib/dom.js';
import { LOGO } from './lib/icons.js';
import { api, setCsrf, onAuthLost } from './lib/api.js';
import { state, emit, on, prefs } from './lib/state.js';
import { workerPump } from './lib/worker.js';
import { menu, notify, showError, closeAllOverlays, button, modal } from './lib/ui.js';
import { ago } from './lib/format.js';
import { renderLogin } from './views/login.js';
import * as dashboardView from './views/dashboard.js';
import * as campaignsView from './views/campaigns.js';
import * as campaignView from './views/campaign.js';
import * as leadsView from './views/leads.js';
import * as sourcesView from './views/sources.js';
import * as settingsView from './views/settings.js';
import { openWizard } from './views/wizard.js';
import { openPalette } from './views/palette.js';

const root = document.getElementById('app');

const NAV = [
  { id: 'dashboard', label: 'Dashboard', icon: 'dashboard', path: 'dashboard' },
  { id: 'campaigns', label: 'Campaigns', icon: 'rocket', path: 'campaigns' },
  { id: 'leads', label: 'Leads', icon: 'users', path: 'leads' },
  { id: 'sources', label: 'Sources', icon: 'globe', path: 'sources' },
  { id: 'settings', label: 'Settings', icon: 'settings', path: 'settings' },
];

const ROUTES = [
  { re: /^(dashboard)?$/, view: dashboardView, nav: 'dashboard' },
  { re: /^campaigns$/, view: campaignsView, nav: 'campaigns' },
  { re: /^campaigns\/new$/, view: campaignsView, nav: 'campaigns', wizard: true },
  { re: /^campaigns\/(\d+)$/, view: campaignView, nav: 'campaigns', keys: ['id'] },
  { re: /^leads$/, view: leadsView, nav: 'leads' },
  { re: /^sources$/, view: sourcesView, nav: 'sources' },
  { re: /^settings(?:\/([a-z-]+))?$/, view: settingsView, nav: 'settings', keys: ['tab'] },
];

/* ---------------------------------------------------------------- theme */

export function applyTheme(mode) {
  const m = ['light', 'dark'].includes(mode) ? mode : 'system';
  if (m === 'system') delete document.documentElement.dataset.theme;
  else document.documentElement.dataset.theme = m;
  prefs.set('theme', m);
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', getComputedStyle(document.body).backgroundColor);
  emit('theme', m);
}

/* ----------------------------------------------------------------- boot */

async function boot() {
  applyTheme(prefs.get('theme', 'system'));
  try {
    const me = await api.get('auth.me');
    setCsrf(me.csrf);
    state.app = me.app || state.app;
    state.user = me.user;
    document.title = state.app.name;
    if (state.user) renderShell();
    else showLogin();
  } catch (e) {
    if (e.code === 'not_installed') {
      window.location.replace('install.php');
      return;
    }
    renderFatal(e);
  } finally {
    document.querySelector('.boot')?.classList.add('done');
    setTimeout(() => document.querySelector('.boot')?.remove(), 600);
  }
}

function renderFatal(err) {
  clear(root).append(h('div', { class: 'auth' },
    h('div', { class: 'auth-card' },
      h('div', { class: 'brand', html: LOGO.replace('<svg', '<svg class="brand-mark"') }),
      h('h1', {}, 'Cannot reach the server'),
      h('p', { class: 'sub' }, err?.message || 'Unknown error'),
      button('Try again', { variant: 'primary', icon: 'refresh', cls: 'btn-block', onClick: () => window.location.reload() }),
    )));
}

function showLogin() {
  workerPump.stop();
  closeAllOverlays();
  renderLogin(root, {
    appName: state.app.name,
    onSuccess: (data) => {
      setCsrf(data.csrf);
      state.user = data.user;
      renderShell();
      notify.success(`Welcome back, ${data.user.name.split(' ')[0]}`, 'You are signed in.');
    },
  });
}

onAuthLost(() => {
  if (!state.user) return;
  state.user = null;
  notify.warning('Session expired', 'Please sign in again.');
  showLogin();
});

/* ---------------------------------------------------------------- shell */

let shell = null;
let current = null; // { cleanup: [], route }

function renderShell() {
  const navEls = {};
  const runningBadge = h('span', { class: 'nav-badge hidden' }, '0');

  const nav = h('nav', { class: 'nav', 'aria-label': 'Main' },
    NAV.map((item) => {
      const a = h('a', { href: `#/${item.path}`, class: 'nav-item', dataset: { nav: item.id } }, ic(item.icon), h('span', {}, item.label));
      if (item.id === 'campaigns') a.append(runningBadge);
      a.addEventListener('click', () => shell?.classList.remove('nav-open'));
      navEls[item.id] = a;
      return a;
    }),
  );

  const workerDot = h('span', { class: 'live-dot idle' });
  const workerTitle = h('strong', {}, 'Worker idle');
  const workerSub = h('small', {}, 'No campaigns running');
  const workerPill = h('button', { class: 'worker-pill', type: 'button', onClick: () => navigate('settings/worker') }, workerDot, h('span', {}, workerTitle, workerSub));

  const themeSeg = h('div', { class: 'segmented', style: { width: '100%' } });
  const themes = [['system', 'monitor', 'System'], ['light', 'sun', 'Light'], ['dark', 'moon', 'Dark']];
  const themeButtons = themes.map(([mode, icon, label]) => {
    const b = h('button', { type: 'button', 'data-tip': `${label} theme`, 'aria-label': `${label} theme`, style: { flex: '1', display: 'grid', placeItems: 'center' } }, ic(icon));
    b.querySelector('svg').style.cssText = 'width:15px;height:15px';
    b.addEventListener('click', () => applyTheme(mode));
    themeSeg.append(b);
    return [mode, b];
  });
  const syncTheme = (mode) => themeButtons.forEach(([m, b]) => {
    b.classList.toggle('active', m === mode);
    b.style.background = m === mode ? 'var(--surface)' : '';
    b.style.boxShadow = m === mode ? 'var(--shadow-sm)' : '';
  });
  syncTheme(prefs.get('theme', 'system'));

  const userCard = h('button', { class: 'user-card', type: 'button' },
    h('span', { class: 'avatar' }, initials(state.user.name)),
    h('span', { class: 'meta' }, h('strong', {}, state.user.name), h('small', {}, state.user.email)),
    ic('more-v'));
  userCard.querySelector('svg:last-child').style.cssText = 'width:16px;height:16px;color:var(--muted);flex:none';
  userCard.addEventListener('click', () => menu(userCard, [
    { label: 'Account settings', icon: 'user', onClick: () => navigate('settings/account') },
    { label: 'Keyboard shortcuts', icon: 'command', onClick: showShortcuts },
    { sep: true },
    { label: 'Sign out', icon: 'logout', danger: true, onClick: logout },
  ]));

  const sidebar = h('aside', { class: 'sidebar' },
    h('div', { class: 'brand' }, h('span', { html: LOGO.replace('<svg', '<svg class="brand-mark"') }), h('div', {}, h('div', { class: 'brand-name' }, state.app.name), h('div', { class: 'brand-sub' }, 'Lead generation'))),
    h('div', { class: 'nav-label' }, 'Workspace'),
    nav,
    h('div', { class: 'sidebar-footer' }, workerPill, themeSeg, userCard),
  );

  const titleEl = h('h1', {}, '');
  const crumbsEl = h('div', { class: 'crumbs' });
  const actionsEl = h('div', { class: 'topbar-actions' });
  const topbar = h('header', { class: 'topbar' },
    button('', { icon: 'menu', variant: 'ghost', cls: 'menu-btn', title: 'Menu', onClick: () => shell.classList.toggle('nav-open') }),
    h('div', { class: 'topbar-title' }, crumbsEl, titleEl),
    h('div', { class: 'topbar-actions' },
      actionsEl,
      h('button', { class: 'search-trigger', type: 'button', onClick: () => openPalette({ navigate, newCampaign: () => openWizard({ navigate }), setTheme: applyTheme }), 'aria-label': 'Search' },
        ic('search'), h('span', {}, 'Search or jump to…'), h('kbd', {}, navigator.platform.includes('Mac') ? '⌘K' : 'Ctrl K')),
      button('New campaign', { icon: 'plus', variant: 'primary', onClick: () => openWizard({ navigate }) }),
    ),
  );

  const viewEl = h('main', { class: 'view', id: 'view' });
  shell = h('div', { class: 'shell' }, sidebar, h('div', { class: 'backdrop-mobile', onClick: () => shell.classList.remove('nav-open') }), h('div', { class: 'main' }, topbar, viewEl));
  clear(root).append(shell);

  shell.refs = { navEls, titleEl, crumbsEl, actionsEl, viewEl, runningBadge, workerDot, workerTitle, workerSub };

  const offs = [
    on('worker:state', updateWorkerPill),
    on('theme', syncTheme),
    on('campaigns:live', (items) => {
      const running = items.filter((c) => c.status === 'running').length;
      runningBadge.textContent = String(running);
      runningBadge.classList.toggle('hidden', running === 0);
    }),
  ];
  shell.cleanup = () => offs.forEach((off) => off());

  window.removeEventListener('hashchange', route);
  window.addEventListener('hashchange', route);
  route();
  workerPump.start();
}

function updateWorkerPill(w) {
  if (!shell) return;
  const { workerDot, workerTitle, workerSub } = shell.refs;
  if (w.error) {
    workerDot.className = 'live-dot warn';
    workerTitle.textContent = 'Worker issue';
    workerSub.textContent = w.error.slice(0, 60);
  } else if (w.running > 0) {
    workerDot.className = w.browserEnabled || w.cronActive ? 'live-dot' : 'live-dot warn';
    workerTitle.textContent = w.running === 1 ? '1 campaign running' : `${w.running} campaigns running`;
    workerSub.textContent = w.busy ? 'Background worker busy…' : w.lastTick ? `Last batch ${ago(w.lastTick)}` : (w.browserEnabled ? 'Working in this tab' : 'Waiting for cron');
  } else {
    workerDot.className = 'live-dot idle';
    workerTitle.textContent = 'Worker idle';
    workerSub.textContent = 'No campaigns running';
  }
}

async function logout() {
  try {
    const res = await api.post('auth.logout', {});
    setCsrf(res.csrf);
  } catch (e) {
    showError(e, 'Sign out failed');
    return;
  }
  teardownView();
  shell?.cleanup?.();
  shell = null;
  state.user = null;
  showLogin();
  notify.info('Signed out', 'See you soon.');
}

/* --------------------------------------------------------------- router */

export function navigate(path, { replace = false } = {}) {
  const target = '#/' + String(path).replace(/^#?\/?/, '');
  if (replace) {
    history.replaceState(null, '', target);
    route();
  } else if (window.location.hash === target) {
    route();
  } else {
    window.location.hash = target;
  }
}

/** Update the query string of the current route without re-rendering. */
export function setQuery(params) {
  const [path] = window.location.hash.replace(/^#\/?/, '').split('?');
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== '' && v !== false) qs.set(k, String(v));
  }
  const str = qs.toString();
  history.replaceState(null, '', `#/${path}${str ? '?' + str : ''}`);
}

function teardownView() {
  if (!current) return;
  current.cleanup.forEach((fn) => {
    try {
      fn();
    } catch (e) {
      console.error(e);
    }
  });
  current = null;
}

async function route() {
  if (!shell || !state.user) return;
  const raw = window.location.hash.replace(/^#\/?/, '');
  const [path, qs = ''] = raw.split('?');
  const query = new URLSearchParams(qs);
  let match = null;
  let def = null;
  for (const r of ROUTES) {
    match = r.re.exec(path);
    if (match) {
      def = r;
      break;
    }
  }

  // Wizard route: keep the underlying campaigns list and open the modal.
  if (def?.wizard) {
    if (current?.def?.view !== campaignsView) {
      await mountView(ROUTES[1], {}, query);
    }
    history.replaceState(null, '', '#/campaigns');
    openWizard({ navigate });
    return;
  }

  closeAllOverlays();
  if (!def) {
    renderNotFound();
    return;
  }
  const params = {};
  (def.keys || []).forEach((k, i) => (params[k] = match[i + 1]));
  await mountView(def, params, query);
}

async function mountView(def, params, query) {
  const { navEls, titleEl, crumbsEl, actionsEl, viewEl } = shell.refs;
  teardownView();
  const ctx = {
    def,
    params,
    query,
    cleanup: [],
    active: true,
    onCleanup(fn) {
      this.cleanup.push(fn);
    },
    isActive() {
      return current === this;
    },
    setTitle(title, crumbs = []) {
      titleEl.textContent = title;
      document.title = `${title} · ${state.app.name}`;
      clear(crumbsEl);
      crumbs.forEach((c, i) => {
        if (i) crumbsEl.append(ic('chevron-right'));
        crumbsEl.append(c.path ? h('a', { href: `#/${c.path}` }, c.label) : h('span', {}, c.label));
      });
    },
    setActions(nodes = []) {
      clear(actionsEl).append(...nodes.filter(Boolean));
    },
    navigate,
    setQuery,
  };
  current = ctx;

  Object.entries(navEls).forEach(([id, el]) => el.classList.toggle('active', id === def.nav));
  clear(actionsEl);
  const el = h('div', { class: 'view-enter' });
  clear(viewEl).append(el);
  ctx.el = el;
  window.scrollTo({ top: 0 });
  try {
    await def.view.mount(ctx);
  } catch (e) {
    if (ctx.isActive()) {
      showError(e, 'Could not load this page');
    }
  }
}

function renderNotFound() {
  const { viewEl, titleEl, crumbsEl } = shell.refs;
  teardownView();
  titleEl.textContent = 'Not found';
  clear(crumbsEl);
  clear(viewEl).append(h('div', { class: 'view-enter card', style: { marginTop: '20px' } },
    h('div', { class: 'empty' }, h('h3', {}, 'This page does not exist'), h('p', {}, 'The link may be outdated.'),
      button('Go to dashboard', { variant: 'primary', icon: 'dashboard', onClick: () => navigate('dashboard') }))));
}

/* ---------------------------------------------------------- shortcuts */

function isTyping(e) {
  const t = e.target;
  return t && (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName));
}

let gPressed = 0;
document.addEventListener('keydown', (e) => {
  if (!shell || !state.user) return;
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    openPalette({ navigate, newCampaign: () => openWizard({ navigate }), setTheme: applyTheme });
    return;
  }
  if (isTyping(e) || e.metaKey || e.ctrlKey || e.altKey || document.querySelector('.overlay')) return;
  const key = e.key.toLowerCase();
  if (key === 'n') {
    e.preventDefault();
    openWizard({ navigate });
  } else if (key === '/') {
    const search = document.querySelector('[data-search]');
    if (search) {
      e.preventDefault();
      search.focus();
    }
  } else if (key === 'g') {
    gPressed = Date.now();
  } else if (Date.now() - gPressed < 900) {
    const map = { d: 'dashboard', c: 'campaigns', l: 'leads', s: 'sources', t: 'settings' };
    if (map[key]) {
      e.preventDefault();
      navigate(map[key]);
    }
    gPressed = 0;
  }
});

function showShortcuts() {
  const rows = [
    ['Ctrl/⌘ + K', 'Search & command palette'],
    ['N', 'New campaign'],
    ['/', 'Focus the search box'],
    ['G then D', 'Go to dashboard'],
    ['G then C', 'Go to campaigns'],
    ['G then L', 'Go to leads'],
    ['G then S', 'Go to sources'],
    ['G then T', 'Go to settings'],
    ['Esc', 'Close dialogs'],
  ];
  modal({
    title: 'Keyboard shortcuts',
    icon: 'command',
    body: h('div', { class: 'stack' }, rows.map(([k, d]) => h('div', { class: 'flex justify-between items-center' }, h('span', { class: 'text-2' }, d), h('kbd', {}, k)))),
  });
}

window.addEventListener('scroll', () => {
  document.querySelector('.topbar')?.classList.toggle('scrolled', window.scrollY > 4);
}, { passive: true });

window.addEventListener('unhandledrejection', (e) => {
  if (e.reason?.name === 'AbortError') return;
  console.error(e.reason);
});

boot();
