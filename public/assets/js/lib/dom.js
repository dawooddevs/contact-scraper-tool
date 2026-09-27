// Tiny DOM toolkit. All text goes through textContent, so API data is never
// interpreted as HTML (no XSS through scraped business names, etc.).

import { icon } from './icons.js';

/**
 * h('div', { class: 'x', onClick: fn }, 'text', child, [children])
 * Special props: class, style (object|string), dataset, html (trusted only),
 * icon (name), on<Event>, ref (callback), and any attribute.
 */
export function h(tag, props = {}, ...children) {
  const el = tag === 'svg' || tag === 'path' ? document.createElementNS('http://www.w3.org/2000/svg', tag) : document.createElement(tag);
  if (props && typeof props === 'object' && !(props instanceof Node) && !Array.isArray(props)) {
    for (const [key, value] of Object.entries(props)) {
      if (value === undefined || value === null || value === false) continue;
      if (key === 'class') el.className = Array.isArray(value) ? value.filter(Boolean).join(' ') : value;
      else if (key === 'style') {
        if (typeof value === 'string') el.style.cssText = value;
        else Object.assign(el.style, value);
      } else if (key === 'dataset') Object.assign(el.dataset, value);
      else if (key === 'html') el.innerHTML = value;
      else if (key === 'ref') value(el);
      else if (key.startsWith('on') && typeof value === 'function') el.addEventListener(key.slice(2).toLowerCase(), value);
      else if (key === 'value' && 'value' in el) el.value = value;
      else if (key === 'checked' || key === 'disabled' || key === 'selected' || key === 'indeterminate') el[key] = !!value;
      else if (value === true) el.setAttribute(key, '');
      else el.setAttribute(key, String(value));
    }
  } else if (props !== undefined && props !== null) {
    children.unshift(props);
  }
  append(el, children);
  return el;
}

export function append(el, children) {
  for (const child of children.flat(Infinity)) {
    if (child === null || child === undefined || child === false || child === '') continue;
    el.appendChild(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return el;
}

export function clear(el) {
  while (el.firstChild) el.removeChild(el.firstChild);
  return el;
}

export function replace(el, ...children) {
  clear(el);
  return append(el, children);
}

export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

/** Icon element: ic('play') */
export function ic(name, cls = '') {
  const span = document.createElement('span');
  span.innerHTML = icon(name);
  const svg = span.firstElementChild;
  if (cls && svg) svg.setAttribute('class', cls);
  return svg || span;
}

/** Stagger children entrance animation. */
export function stagger(el) {
  el.classList.add('stagger');
  Array.from(el.children).forEach((c, i) => c.style.setProperty('--i', Math.min(i, 14)));
  return el;
}

export function debounce(fn, wait = 250) {
  let t;
  const d = (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), wait);
  };
  d.cancel = () => clearTimeout(t);
  return d;
}

export function initials(name = '') {
  const parts = String(name).trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

export function uid(prefix = 'id') {
  return prefix + '-' + Math.random().toString(36).slice(2, 9);
}
