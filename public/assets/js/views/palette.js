// Command palette (Ctrl/⌘+K): jump anywhere, run actions, search campaigns & leads.

import { h, ic, clear, debounce } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { prefs } from '../lib/state.js';

let open = false;

export function openPalette({ navigate, newCampaign, setTheme }) {
  if (open) return;
  open = true;
  const previous = document.activeElement;
  const inputEl = h('input', { type: 'text', placeholder: 'Search campaigns, leads or type a command…', 'aria-label': 'Command palette', autocomplete: 'off' });
  const list = h('div', { class: 'palette-list', role: 'listbox' });
  const panel = h('div', { class: 'palette', role: 'dialog', 'aria-modal': 'true' },
    h('div', { class: 'palette-input' }, ic('search'), inputEl, h('kbd', {}, 'Esc')),
    list,
    h('div', { class: 'palette-foot' }, h('span', {}, h('kbd', {}, '↑'), ' ', h('kbd', {}, '↓'), ' navigate'), h('span', {}, h('kbd', {}, '↵'), ' open')),
  );
  const overlay = h('div', { class: 'overlay palette-overlay' }, panel);
  overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(); });
  document.body.append(overlay);
  inputEl.focus();

  const commands = [
    { group: 'Actions', label: 'New campaign', icon: 'plus', hint: 'N', run: () => newCampaign() },
    { group: 'Actions', label: 'Export leads', icon: 'download', run: () => navigate('leads') },
    { group: 'Actions', label: 'Test a source', icon: 'zap', run: () => navigate('sources') },
    { group: 'Actions', label: 'Set up cron job', icon: 'clock', run: () => navigate('settings/worker') },
    { group: 'Actions', label: 'Configure scraping API / proxies', icon: 'plug', run: () => navigate('settings/connection') },
    { group: 'Actions', label: 'Switch to dark theme', icon: 'moon', run: () => setTheme('dark') },
    { group: 'Actions', label: 'Switch to light theme', icon: 'sun', run: () => setTheme('light') },
    { group: 'Go to', label: 'Dashboard', icon: 'dashboard', hint: 'G D', run: () => navigate('dashboard') },
    { group: 'Go to', label: 'Campaigns', icon: 'rocket', hint: 'G C', run: () => navigate('campaigns') },
    { group: 'Go to', label: 'Leads', icon: 'users', hint: 'G L', run: () => navigate('leads') },
    { group: 'Go to', label: 'Starred leads', icon: 'star', run: () => navigate('leads?starred=1') },
    { group: 'Go to', label: 'Sources', icon: 'globe', hint: 'G S', run: () => navigate('sources') },
    { group: 'Go to', label: 'Settings', icon: 'settings', hint: 'G T', run: () => navigate('settings') },
    { group: 'Go to', label: 'Account', icon: 'user', run: () => navigate('settings/account') },
  ];

  let items = [];
  let hl = 0;
  let remote = { campaigns: [], leads: [] };
  let seq = 0;

  function render() {
    const q = inputEl.value.trim().toLowerCase();
    const cmd = commands.filter((c) => !q || c.label.toLowerCase().includes(q));
    items = [
      ...remote.campaigns.map((c) => ({ group: 'Campaigns', label: c.name, icon: 'rocket', hint: c.status, run: () => navigate(`campaigns/${c.id}`) })),
      ...remote.leads.map((l) => ({ group: 'Leads', label: l.name, icon: 'user', hint: l.email || l.phone || l.city || '', run: () => navigate(`leads?open=${l.id}&q=${encodeURIComponent(q)}`) })),
      ...cmd,
    ];
    if (!q) {
      const recent = prefs.get('palette-recent', []);
      items.sort((a, b) => (recent.indexOf(b.label) >= 0 ? 1 : 0) - (recent.indexOf(a.label) >= 0 ? 1 : 0));
    }
    hl = Math.min(hl, Math.max(0, items.length - 1));
    clear(list);
    if (!items.length) {
      list.append(h('div', { class: 'muted', style: { padding: '18px 12px' } }, 'No results.'));
      return;
    }
    let group = '';
    items.forEach((it, i) => {
      if (it.group !== group) {
        group = it.group;
        list.append(h('div', { class: 'palette-group' }, group));
      }
      const b = h('button', { type: 'button', class: ['palette-item', i === hl && 'hl'], role: 'option' }, ic(it.icon), h('span', { class: 'truncate' }, it.label), it.hint ? h('small', {}, it.hint) : null);
      b.addEventListener('mousemove', () => {
        if (hl !== i) {
          hl = i;
          list.querySelectorAll('.palette-item').forEach((x, j) => x.classList.toggle('hl', j === hl));
        }
      });
      b.addEventListener('click', () => choose(i));
      list.append(b);
    });
  }

  const searchRemote = debounce(async () => {
    const q = inputEl.value.trim();
    const mine = ++seq;
    if (q.length < 2) {
      remote = { campaigns: [], leads: [] };
      render();
      return;
    }
    try {
      const [c, l] = await Promise.all([
        api.get('campaigns.list', { q, per_page: 5 }),
        api.get('leads.list', { q, per_page: 6, status: 'all' }),
      ]);
      if (mine !== seq || !open) return;
      remote = { campaigns: c.items, leads: l.items };
      render();
    } catch {
      /* palette search is best-effort */
    }
  }, 220);

  function choose(i) {
    const it = items[i];
    if (!it) return;
    const recent = [it.label, ...prefs.get('palette-recent', []).filter((x) => x !== it.label)].slice(0, 5);
    prefs.set('palette-recent', recent);
    close();
    it.run();
  }

  function close() {
    if (!open) return;
    open = false;
    overlay.classList.add('closing');
    setTimeout(() => overlay.remove(), 200);
    document.removeEventListener('keydown', onKey, true);
    if (previous?.focus && document.contains(previous)) previous.focus({ preventScroll: true });
  }

  function onKey(e) {
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      close();
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (!items.length) return;
      hl = e.key === 'ArrowDown' ? (hl + 1) % items.length : (hl - 1 + items.length) % items.length;
      const buttons = list.querySelectorAll('.palette-item');
      buttons.forEach((x, j) => x.classList.toggle('hl', j === hl));
      buttons[hl]?.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
      e.preventDefault();
      choose(hl);
    }
  }
  document.addEventListener('keydown', onKey, true);
  inputEl.addEventListener('input', () => {
    hl = 0;
    render();
    searchRemote();
  });
  render();
}
