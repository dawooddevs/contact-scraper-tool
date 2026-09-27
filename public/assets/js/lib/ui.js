// UI components: buttons, toasts, modals, drawers, menus, tooltips, tabs,
// segmented controls, switches, tag inputs, skeletons, empty states.

import { h, ic, clear, append, uid } from './dom.js';
import { EMPTY_ART } from './icons.js';
import { STATUS_LABELS } from './format.js';

/* ------------------------------------------------------------ buttons */

export function button(label, { icon, variant = '', size = '', onClick, type = 'button', title, cls = '', iconRight } = {}) {
  const b = h('button', {
    type,
    class: ['btn', variant && `btn-${variant}`, size && `btn-${size}`, !label && 'btn-icon', cls],
    title: title || undefined,
    'aria-label': !label ? title : undefined,
  });
  if (icon) b.append(ic(icon));
  if (label) b.append(h('span', { class: 'btn-label' }, label));
  if (iconRight) b.append(ic(iconRight));
  if (onClick) b.addEventListener('click', onClick);
  return b;
}

/** Toggle a spinner on a button while a promise runs. */
export async function withLoading(btn, fn) {
  if (!btn || btn.classList.contains('loading')) return undefined;
  const spinner = h('span', { class: 'spinner' });
  btn.classList.add('loading');
  btn.disabled = true;
  btn.append(spinner);
  try {
    return await fn();
  } finally {
    btn.classList.remove('loading');
    btn.disabled = false;
    spinner.remove();
  }
}

// Material-style ripple on every .btn
document.addEventListener('pointerdown', (e) => {
  const btn = e.target.closest?.('.btn');
  if (!btn || btn.disabled || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const rect = btn.getBoundingClientRect();
  const size = Math.max(rect.width, rect.height);
  const r = h('span', { class: 'ripple', style: { width: `${size}px`, height: `${size}px`, left: `${e.clientX - rect.left - size / 2}px`, top: `${e.clientY - rect.top - size / 2}px` } });
  btn.append(r);
  setTimeout(() => r.remove(), 650);
});

/* -------------------------------------------------------------- toasts */

let toastRoot = null;
const TOAST_ICONS = { success: 'check-circle', error: 'x-circle', info: 'info', warning: 'alert' };

export function toast(type, title, message = '', { duration } = {}) {
  if (!toastRoot) {
    toastRoot = h('div', { class: 'toasts', role: 'status', 'aria-live': 'polite' });
    document.body.append(toastRoot);
  }
  const ms = duration ?? (type === 'error' ? 7000 : 4200);
  const el = h('div', { class: ['toast', type] },
    h('div', { class: 't-icon' }, ic(TOAST_ICONS[type] || 'info')),
    h('div', { class: 't-body' }, h('strong', {}, title), message ? h('p', {}, message) : null),
    h('button', { class: 't-close', 'aria-label': 'Dismiss', onClick: () => dismiss() }, ic('x')),
    h('div', { class: 't-bar', style: { animationDuration: `${ms}ms` } }),
  );
  let timer = setTimeout(() => dismiss(), ms);
  el.addEventListener('mouseenter', () => {
    clearTimeout(timer);
    el.querySelector('.t-bar').style.animationPlayState = 'paused';
  });
  el.addEventListener('mouseleave', () => {
    el.querySelector('.t-bar').style.animationPlayState = 'running';
    timer = setTimeout(() => dismiss(), 1800);
  });
  function dismiss() {
    clearTimeout(timer);
    if (!el.isConnected || el.classList.contains('leaving')) return;
    el.classList.add('leaving');
    setTimeout(() => el.remove(), 260);
  }
  toastRoot.append(el);
  while (toastRoot.children.length > 5) toastRoot.firstElementChild.remove();
  return dismiss;
}

export const notify = {
  success: (t, m, o) => toast('success', t, m, o),
  error: (t, m, o) => toast('error', t, m, o),
  info: (t, m, o) => toast('info', t, m, o),
  warning: (t, m, o) => toast('warning', t, m, o),
};

/** Standard handler for failed API calls. */
export function showError(err, title = 'Something went wrong') {
  if (err?.name === 'AbortError') return;
  console.error(err);
  notify.error(title, err?.message || String(err));
}

/* ------------------------------------------------------------ overlays */

const overlays = [];

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && overlays.length) {
    const top = overlays[overlays.length - 1];
    if (top.dismissible !== false) {
      e.preventDefault();
      top.close();
    }
  }
  if (e.key === 'Tab' && overlays.length) trapFocus(e, overlays[overlays.length - 1].panel);
});

function trapFocus(e, panel) {
  const focusables = Array.from(panel.querySelectorAll('button:not([disabled]), [href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
    .filter((el) => el.offsetParent !== null || el === document.activeElement);
  if (!focusables.length) return;
  const first = focusables[0];
  const last = focusables[focusables.length - 1];
  if (e.shiftKey && document.activeElement === first) {
    e.preventDefault();
    last.focus();
  } else if (!e.shiftKey && document.activeElement === last) {
    e.preventDefault();
    first.focus();
  } else if (!panel.contains(document.activeElement)) {
    e.preventDefault();
    first.focus();
  }
}

function openOverlay({ panel, cls = '', dismissible = true, onClose }) {
  const previous = document.activeElement;
  const overlay = h('div', { class: ['overlay', cls] }, panel);
  const entry = { overlay, panel, dismissible, close };
  let closed = false;
  overlay.addEventListener('mousedown', (e) => {
    if (e.target === overlay && dismissible) close();
  });
  function close(result) {
    if (closed) return;
    closed = true;
    const i = overlays.indexOf(entry);
    if (i >= 0) overlays.splice(i, 1);
    overlay.classList.add('closing');
    setTimeout(() => overlay.remove(), 230);
    if (!overlays.length) document.body.style.overflow = '';
    onClose?.(result);
    if (previous && previous.focus && document.contains(previous)) previous.focus({ preventScroll: true });
  }
  overlays.push(entry);
  document.body.append(overlay);
  document.body.style.overflow = 'hidden';
  requestAnimationFrame(() => {
    const target = panel.querySelector('[autofocus]') || panel.querySelector('input:not([type=checkbox]):not([type=hidden]), textarea, select') || panel.querySelector('button:not(.modal-close)');
    target?.focus({ preventScroll: true });
  });
  return entry;
}

export function closeAllOverlays() {
  [...overlays].reverse().forEach((o) => o.close());
}

/**
 * modal({ title, subtitle, icon, tone, body, footer, size, dismissible, onClose })
 * footer: array of nodes, or (api) => nodes
 */
export function modal({ title, subtitle, icon, tone = '', body, footer, size = '', dismissible = true, onClose, cls = '' } = {}) {
  const panel = h('div', { class: ['modal', size && `modal-${size}`, cls], role: 'dialog', 'aria-modal': 'true', 'aria-label': title || 'Dialog' });
  const api = {};
  if (title) {
    panel.append(h('div', { class: 'modal-header' },
      icon ? h('div', { class: ['mh-icon', tone] }, ic(icon)) : null,
      h('div', {}, h('h2', {}, title), subtitle ? h('p', {}, subtitle) : null),
    ));
  }
  if (dismissible) panel.append(button('', { icon: 'x', variant: 'ghost', size: 'sm', cls: 'modal-close', title: 'Close', onClick: () => api.close() }));
  const bodyEl = h('div', { class: 'modal-body' });
  if (body) append(bodyEl, [body]);
  panel.append(bodyEl);
  const footerEl = h('div', { class: 'modal-footer' });
  const entry = openOverlay({ panel, dismissible, onClose });
  api.close = entry.close;
  api.el = panel;
  api.body = bodyEl;
  api.setBody = (node) => replaceChildren(bodyEl, node);
  api.footer = footerEl;
  const f = typeof footer === 'function' ? footer(api) : footer;
  if (f && (!Array.isArray(f) || f.length)) {
    append(footerEl, [f]);
    panel.append(footerEl);
  }
  return api;
}

function replaceChildren(el, node) {
  clear(el);
  if (node) append(el, [node]);
}

export function confirmDialog({ title = 'Are you sure?', message = '', confirmText = 'Confirm', cancelText = 'Cancel', danger = false, icon } = {}) {
  return new Promise((resolve) => {
    let result = false;
    const m = modal({
      title,
      icon: icon || (danger ? 'alert' : 'info'),
      tone: danger ? 'danger' : '',
      body: message ? h('p', { class: 'text-2' }, message) : null,
      onClose: () => resolve(result),
      footer: (api) => [
        button(cancelText, { onClick: () => api.close() }),
        button(confirmText, {
          variant: danger ? 'danger' : 'primary',
          onClick: () => {
            result = true;
            api.close();
          },
        }),
      ],
    });
    m.footer.lastElementChild?.focus();
  });
}

export function drawer({ title, subtitle, header, body, footer, onClose, width } = {}) {
  const panel = h('aside', { class: 'drawer', role: 'dialog', 'aria-modal': 'true', 'aria-label': title || 'Details', style: width ? { width } : undefined });
  const api = {};
  const headerEl = h('div', { class: 'drawer-header' },
    header || h('div', { style: { flex: '1', minWidth: '0' } }, h('h2', {}, title || ''), subtitle ? h('p', { class: 'muted small mt-1' }, subtitle) : null),
    button('', { icon: 'x', variant: 'ghost', size: 'sm', title: 'Close', onClick: () => api.close() }),
  );
  const bodyEl = h('div', { class: 'drawer-body' }, body || null);
  panel.append(headerEl, bodyEl);
  const footerEl = h('div', { class: 'drawer-footer' });
  const entry = openOverlay({ panel, cls: 'drawer-overlay', onClose });
  api.close = entry.close;
  api.el = panel;
  api.body = bodyEl;
  api.header = headerEl;
  api.setBody = (node) => replaceChildren(bodyEl, node);
  api.setHeader = (node) => {
    headerEl.firstElementChild.replaceWith(node);
  };
  api.setFooter = (nodes) => {
    clear(footerEl);
    if (nodes && nodes.length) {
      append(footerEl, [nodes]);
      if (!footerEl.isConnected) panel.append(footerEl);
    } else {
      footerEl.remove();
    }
  };
  if (footer) api.setFooter(footer);
  return api;
}

/* ---------------------------------------------------------------- menus */

let openMenu = null;

export function menu(anchor, items) {
  closeMenu();
  const el = h('div', { class: 'menu', role: 'menu' });
  for (const item of items) {
    if (!item) continue;
    if (item.sep) {
      el.append(h('div', { class: 'sep' }));
      continue;
    }
    el.append(h('button', {
      role: 'menuitem',
      class: item.danger ? 'danger' : '',
      onClick: (e) => {
        e.stopPropagation();
        closeMenu();
        item.onClick?.();
      },
    }, item.icon ? ic(item.icon) : null, item.label));
  }
  document.body.append(el);
  const r = anchor.getBoundingClientRect();
  const mw = el.offsetWidth;
  const mh = el.offsetHeight;
  let left = r.right - mw;
  if (left < 8) left = Math.min(r.left, window.innerWidth - mw - 8);
  let top = r.bottom + 6;
  if (top + mh > window.innerHeight - 8) top = Math.max(8, r.top - mh - 6);
  el.style.left = `${Math.max(8, left)}px`;
  el.style.top = `${top}px`;
  openMenu = el;
  el.querySelector('button')?.focus({ preventScroll: true });
  return el;
}

export function closeMenu() {
  if (!openMenu) return;
  const m = openMenu;
  openMenu = null;
  m.classList.add('closing');
  setTimeout(() => m.remove(), 130);
}

document.addEventListener('mousedown', (e) => {
  if (openMenu && !openMenu.contains(e.target)) closeMenu();
});
document.addEventListener('keydown', (e) => {
  if (!openMenu) return;
  if (e.key === 'Escape') {
    e.stopPropagation();
    closeMenu();
  } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault();
    const items = Array.from(openMenu.querySelectorAll('button'));
    const i = items.indexOf(document.activeElement);
    const next = e.key === 'ArrowDown' ? (i + 1) % items.length : (i - 1 + items.length) % items.length;
    items[next]?.focus();
  }
}, true);
window.addEventListener('resize', closeMenu);
window.addEventListener('scroll', closeMenu, true);

/* -------------------------------------------------------------- tooltips */

let tipEl = null;
let tipTarget = null;
document.addEventListener('mouseover', (e) => {
  const t = e.target.closest?.('[data-tip]');
  if (t === tipTarget) return;
  hideTip();
  if (!t || !t.dataset.tip) return;
  tipTarget = t;
  tipEl = h('div', { class: 'tooltip', role: 'tooltip' }, t.dataset.tip);
  document.body.append(tipEl);
  const r = t.getBoundingClientRect();
  const w = tipEl.offsetWidth;
  const hh = tipEl.offsetHeight;
  let top = r.top - hh - 8;
  if (top < 6) top = r.bottom + 8;
  tipEl.style.top = `${top}px`;
  tipEl.style.left = `${Math.min(window.innerWidth - w - 6, Math.max(6, r.left + r.width / 2 - w / 2))}px`;
});
document.addEventListener('mouseout', (e) => {
  if (tipTarget && !tipTarget.contains(e.relatedTarget)) hideTip();
});
document.addEventListener('scroll', hideTip, true);
function hideTip() {
  tipEl?.remove();
  tipEl = null;
  tipTarget = null;
}

/* ------------------------------------------------------ form controls */

export function switchInput({ checked = false, onChange, label, description, name } = {}) {
  const input = h('input', { type: 'checkbox', role: 'switch', checked, name });
  input.addEventListener('change', () => onChange?.(input.checked));
  const el = h('label', { class: 'switch' }, input, h('span', { class: 'track' }),
    label || description ? h('span', { class: 'switch-text' }, label ? h('strong', {}, label) : null, description ? h('small', {}, description) : null) : null);
  el.input = input;
  return el;
}

/** A labelled row with a switch on the right (settings style). */
export function settingSwitch({ label, description, checked, onChange }) {
  const sw = switchInput({ checked, onChange });
  const row = h('div', { class: 'setting-row' }, h('div', { class: 'switch-text' }, h('strong', {}, label), description ? h('small', {}, description) : null), sw);
  row.input = sw.input;
  return row;
}

export function field(label, control, { hint, name, optional, id } = {}) {
  const fid = id || control.id || uid('f');
  if (!control.id && (control.tagName === 'INPUT' || control.tagName === 'SELECT' || control.tagName === 'TEXTAREA')) control.id = fid;
  return h('div', { class: 'field', dataset: name ? { field: name } : undefined },
    label ? h('label', { for: control.id || undefined }, label, optional ? h('span', { class: 'label-optional' }, '(optional)') : null) : null,
    control,
    hint ? h('div', { class: 'hint' }, hint) : null,
    h('div', { class: 'error-text' }),
  );
}

export function input(props = {}) {
  return h('input', { class: 'input', type: 'text', ...props });
}

export function select(options, value, props = {}) {
  const el = h('select', { class: 'select', ...props });
  for (const opt of options) {
    const o = typeof opt === 'string' ? { value: opt, label: opt } : opt;
    el.append(h('option', { value: o.value, selected: String(o.value) === String(value) }, o.label));
  }
  return el;
}

/** Put API validation errors next to their fields (data-field="name"). */
export function showFieldErrors(root, fields = {}) {
  clearFieldErrors(root);
  let first = null;
  for (const [name, message] of Object.entries(fields)) {
    const f = root.querySelector(`[data-field="${CSS.escape(name)}"]`);
    if (!f) continue;
    f.classList.add('invalid');
    const t = f.querySelector('.error-text');
    if (t) t.textContent = message;
    first = first || f;
  }
  first?.scrollIntoView({ block: 'center', behavior: 'smooth' });
  return !!first;
}

export function clearFieldErrors(root) {
  root.querySelectorAll('.field.invalid').forEach((f) => {
    f.classList.remove('invalid');
    const t = f.querySelector('.error-text');
    if (t) t.textContent = '';
  });
}

export function segmented(options, value, onChange) {
  const el = h('div', { class: 'segmented', role: 'tablist' });
  const indicator = h('span', { class: 'seg-indicator' });
  el.append(indicator);
  const buttons = options.map((o) => {
    const b = h('button', { type: 'button', role: 'tab', class: String(o.value) === String(value) ? 'active' : '' }, o.label);
    b.addEventListener('click', () => {
      set(o.value);
      onChange?.(o.value);
    });
    el.append(b);
    return b;
  });
  function place() {
    const active = buttons.find((b) => b.classList.contains('active'));
    if (!active || !active.offsetWidth) return;
    indicator.style.left = `${active.offsetLeft}px`;
    indicator.style.width = `${active.offsetWidth}px`;
  }
  function set(v) {
    options.forEach((o, i) => buttons[i].classList.toggle('active', String(o.value) === String(v)));
    place();
  }
  requestAnimationFrame(place);
  new ResizeObserver(place).observe(el);
  el.set = set;
  return el;
}

export function tabs(items, active, onChange) {
  const el = h('div', { class: 'tabs', role: 'tablist' });
  const indicator = h('span', { class: 'tab-indicator' });
  const buttons = items.map((it) => {
    const countEl = it.count !== undefined ? h('span', { class: 'count' }, String(it.count)) : null;
    const b = h('button', { type: 'button', role: 'tab', class: ['tab', it.id === active && 'active'] }, it.icon ? ic(it.icon) : null, it.label, countEl);
    b.addEventListener('click', () => {
      set(it.id);
      onChange?.(it.id);
    });
    b.countEl = countEl;
    el.append(b);
    return b;
  });
  el.append(indicator);
  function place() {
    const b = buttons.find((x) => x.classList.contains('active'));
    if (!b) return;
    indicator.style.left = `${b.offsetLeft}px`;
    indicator.style.width = `${b.offsetWidth}px`;
  }
  function set(id) {
    items.forEach((it, i) => buttons[i].classList.toggle('active', it.id === id));
    place();
  }
  el.set = set;
  el.setCount = (id, n) => {
    const i = items.findIndex((it) => it.id === id);
    if (i >= 0 && buttons[i].countEl) buttons[i].countEl.textContent = String(n);
  };
  requestAnimationFrame(place);
  new ResizeObserver(place).observe(el);
  return el;
}

/**
 * Tag input with autocomplete.
 * tagsInput({ values, suggestions, placeholder, onChange, max })
 */
export function tagsInput({ values = [], suggestions = [], placeholder = 'Type and press Enter', onChange, max = 30 } = {}) {
  let tags = [...values];
  const inputEl = h('input', { type: 'text', placeholder, autocomplete: 'off', 'aria-autocomplete': 'list' });
  const wrap = h('div', { class: 'tags-input' });
  let list = null;
  let hl = -1;

  function render() {
    wrap.querySelectorAll('.tag').forEach((t) => t.remove());
    tags.forEach((t, i) => {
      wrap.insertBefore(h('span', { class: 'tag' }, t, h('button', { type: 'button', 'aria-label': `Remove ${t}`, onClick: (e) => { e.stopPropagation(); remove(i); } }, ic('x'))), inputEl);
    });
    inputEl.placeholder = tags.length ? '' : placeholder;
  }
  function add(value) {
    const parts = String(value).split(/[;\n]+/).map((s) => s.trim().replace(/\s+/g, ' ')).filter(Boolean);
    let changed = false;
    for (const v of parts) {
      if (tags.length >= max) break;
      if (v.length > 120) continue;
      if (tags.some((t) => t.toLowerCase() === v.toLowerCase())) continue;
      tags.push(v);
      changed = true;
    }
    inputEl.value = '';
    hideList();
    if (changed) {
      render();
      onChange?.([...tags]);
    }
  }
  function remove(i) {
    tags.splice(i, 1);
    render();
    onChange?.([...tags]);
    inputEl.focus();
  }
  function showList() {
    const q = inputEl.value.trim().toLowerCase();
    const matches = suggestions.filter((s) => !tags.some((t) => t.toLowerCase() === s.toLowerCase()) && (!q || s.toLowerCase().includes(q))).slice(0, 8);
    hideList();
    if (!matches.length || document.activeElement !== inputEl) return;
    hl = q ? 0 : -1;
    list = h('div', { class: 'suggest', role: 'listbox' });
    matches.forEach((m, i) => {
      const idx = q ? m.toLowerCase().indexOf(q) : -1;
      const label = idx >= 0 ? [m.slice(0, idx), h('mark', {}, m.slice(idx, idx + q.length)), m.slice(idx + q.length)] : [m];
      list.append(h('button', { type: 'button', role: 'option', class: i === hl ? 'hl' : '', onMousedown: (e) => { e.preventDefault(); add(m); inputEl.focus(); } }, ...label));
    });
    wrap.append(list);
  }
  function hideList() {
    list?.remove();
    list = null;
  }
  inputEl.addEventListener('input', showList);
  inputEl.addEventListener('focus', showList);
  inputEl.addEventListener('blur', () => {
    setTimeout(hideList, 120);
    if (inputEl.value.trim()) add(inputEl.value);
  });
  inputEl.addEventListener('keydown', (e) => {
    const items = list ? Array.from(list.querySelectorAll('button')) : [];
    if (e.key === 'ArrowDown' && items.length) {
      e.preventDefault();
      hl = (hl + 1) % items.length;
    } else if (e.key === 'ArrowUp' && items.length) {
      e.preventDefault();
      hl = (hl - 1 + items.length) % items.length;
    } else if (e.key === 'Enter' || e.key === ',' || e.key === 'Tab') {
      if (e.key === 'Tab' && !inputEl.value.trim() && hl < 0) return;
      if (items[hl]) {
        e.preventDefault();
        add(items[hl].textContent);
        return;
      }
      if (inputEl.value.trim()) {
        e.preventDefault();
        add(inputEl.value);
      }
      return;
    } else if (e.key === 'Backspace' && !inputEl.value && tags.length) {
      remove(tags.length - 1);
      return;
    } else if (e.key === 'Escape' && list) {
      e.stopPropagation();
      hideList();
      return;
    } else {
      return;
    }
    items.forEach((b, i) => b.classList.toggle('hl', i === hl));
    items[hl]?.scrollIntoView({ block: 'nearest' });
  });
  inputEl.addEventListener('paste', (e) => {
    const text = e.clipboardData?.getData('text') || '';
    if (/[;\n]/.test(text)) {
      e.preventDefault();
      add(text);
    }
  });
  wrap.addEventListener('click', () => inputEl.focus());
  wrap.append(inputEl);
  render();
  return {
    el: wrap,
    input: inputEl,
    get values() {
      const pending = inputEl.value.trim();
      return pending && !tags.some((t) => t.toLowerCase() === pending.toLowerCase()) ? [...tags, pending] : [...tags];
    },
    set(values) {
      tags = [...values];
      render();
    },
    add,
  };
}

/* --------------------------------------------------------------- misc */

export function badge(status, label) {
  return h('span', { class: ['badge', status] }, h('span', { class: 'dot' }), label || STATUS_LABELS[status] || status);
}

export function progressBar(value, status = '', size = '') {
  const fill = h('span', { style: { width: `${Math.max(0, Math.min(100, value * 100))}%` } });
  const el = h('div', { class: ['progress', status, size], role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(Math.round(value * 100)) }, fill);
  el.set = (v, s) => {
    fill.style.width = `${Math.max(0, Math.min(100, v * 100))}%`;
    el.setAttribute('aria-valuenow', String(Math.round(v * 100)));
    if (s !== undefined) el.className = ['progress', s, size].filter(Boolean).join(' ');
  };
  return el;
}

export function flag(code, size = '') {
  const c = String(code || '').toLowerCase();
  const el = h('span', { class: ['flag', size], title: String(code || '').toUpperCase() });
  if (/^[a-z]{2}$/.test(c)) {
    const img = h('img', { src: `https://flagcdn.com/${c}.svg`, alt: c.toUpperCase(), loading: 'lazy', decoding: 'async' });
    img.addEventListener('error', () => {
      img.remove();
      el.textContent = c.toUpperCase();
    });
    el.append(img);
  } else {
    el.textContent = c.toUpperCase() || '—';
  }
  return el;
}

export function emptyState({ title, text, action, art = true, icon } = {}) {
  return h('div', { class: 'empty' },
    art ? h('div', { class: 'empty-art', html: EMPTY_ART }) : icon ? h('div', { class: 'icon-circle' }, ic(icon)) : null,
    title ? h('h3', {}, title) : null,
    text ? h('p', {}, text) : null,
    action || null,
  );
}

export function skeletonRows(n = 5) {
  return h('div', { class: 'card-body' }, Array.from({ length: n }, (_, i) => h('div', { class: 'skeleton sk-line', style: { width: `${90 - (i % 3) * 18}%` } })));
}

export function skeletonCards(n = 4, height = 110) {
  return Array.from({ length: n }, () => h('div', { class: 'card card-pad' }, h('div', { class: 'skeleton sk-title' }), h('div', { class: 'skeleton', style: { height: `${height - 50}px`, marginTop: '16px' } })));
}

/** Animate a number from its current value to `to`. */
export function countUp(el, to, { duration = 900, format = (n) => new Intl.NumberFormat().format(n) } = {}) {
  const from = Number(el.dataset.value || 0);
  to = Number(to) || 0;
  el.dataset.value = String(to);
  if (from === to || matchMedia('(prefers-reduced-motion: reduce)').matches) {
    el.textContent = format(to);
    return;
  }
  const start = performance.now();
  const step = (now) => {
    const t = Math.min(1, (now - start) / duration);
    const eased = 1 - Math.pow(1 - t, 3);
    el.textContent = format(Math.round(from + (to - from) * eased));
    if (t < 1 && el.isConnected) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
}

export async function copyText(text, label = 'Copied to clipboard') {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
    } else {
      const ta = h('textarea', { style: { position: 'fixed', opacity: '0' } }, text);
      document.body.append(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
    }
    notify.success(label, text.length > 80 ? `${text.slice(0, 80)}…` : text, { duration: 2200 });
  } catch {
    notify.error('Could not copy', 'Your browser blocked clipboard access.');
  }
}

export function copyable(text, display) {
  return h('span', { class: 'copyable' },
    display || h('span', { class: 'truncate' }, text),
    h('button', { type: 'button', class: 'copy-btn', title: 'Copy', 'aria-label': 'Copy', onClick: (e) => { e.stopPropagation(); e.preventDefault(); copyText(text); } }, ic('copy')),
  );
}

export function alertBox(type, title, text, actions) {
  const icons = { info: 'info', warning: 'alert', danger: 'x-circle', success: 'check-circle' };
  return h('div', { class: ['alert', type] }, ic(icons[type] || 'info'),
    h('div', { class: 'alert-body' }, title ? h('strong', {}, title, ' ') : null, text ? h('span', {}, text) : null, actions ? h('div', { class: 'alert-actions' }, actions) : null));
}

/** Trigger a file download from a fetch Response without navigating. */
export async function saveResponse(res, fallbackName = 'download') {
  const blob = await res.blob();
  const cd = res.headers.get('Content-Disposition') || '';
  const m = /filename\*=UTF-8''([^;]+)/i.exec(cd) || /filename="?([^";]+)"?/i.exec(cd);
  const name = m ? decodeURIComponent(m[1]) : fallbackName;
  const url = URL.createObjectURL(blob);
  const a = h('a', { href: url, download: name, style: { display: 'none' } });
  document.body.append(a);
  a.click();
  setTimeout(() => {
    URL.revokeObjectURL(url);
    a.remove();
  }, 1500);
  return name;
}
