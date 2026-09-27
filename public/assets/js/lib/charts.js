// Lightweight SVG charts: line/area time series with crosshair tooltip,
// sparklines, and horizontal bars. Colors come from CSS roles (--series-n)
// so light/dark themes swap automatically.

import { h } from './dom.js';
import { num, dateShort } from './format.js';

const NS = 'http://www.w3.org/2000/svg';
const s = (tag, attrs = {}) => {
  const el = document.createElementNS(NS, tag);
  for (const [k, v] of Object.entries(attrs)) if (v !== undefined && v !== null) el.setAttribute(k, String(v));
  return el;
};

/** Round a tick step up to 1, 2 or 5 × 10^n so axis labels are clean integers. */
function niceStep(v) {
  if (v <= 1) return 1;
  const exp = Math.pow(10, Math.floor(Math.log10(v)));
  const f = v / exp;
  return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * exp;
}

/**
 * timeSeries(container, { labels: ['2026-01-01', ...], series: [{ label, color: 'var(--series-1)', values: [] }], height })
 * Returns { update(data), destroy() }.
 */
export function timeSeries(container, initial) {
  let data = initial;
  const root = h('div', { class: 'chart' });
  const tip = h('div', { class: 'chart-tip', role: 'presentation' });
  root.append(tip);
  container.append(root);
  let first = true;

  function render() {
    root.querySelector('svg')?.remove();
    const width = Math.max(280, root.clientWidth || container.clientWidth || 600);
    const height = data.height || 240;
    const pad = { top: 12, right: 12, bottom: 28, left: 40 };
    const iw = width - pad.left - pad.right;
    const ih = height - pad.top - pad.bottom;
    const n = data.labels.length;
    const maxVal = Math.max(0, ...data.series.flatMap((x) => x.values));
    const ticks = 4;
    const yMax = niceStep(maxVal / ticks) * ticks;
    const x = (i) => pad.left + (n <= 1 ? iw / 2 : (i / (n - 1)) * iw);
    const y = (v) => pad.top + ih - (v / yMax) * ih;

    const svg = s('svg', { viewBox: `0 0 ${width} ${height}`, width, height, role: 'img', 'aria-label': data.ariaLabel || 'Time series chart' });

    // Gridlines + y ticks
    for (let t = 0; t <= ticks; t++) {
      const v = (yMax / ticks) * t;
      const yy = Math.round(y(v)) + 0.5;
      svg.append(s('line', { class: t === 0 ? 'baseline' : 'gridline', x1: pad.left, x2: width - pad.right, y1: yy, y2: yy }));
      const label = s('text', { class: 'tick', x: pad.left - 8, y: yy + 3.5, 'text-anchor': 'end' });
      label.textContent = num(Math.round(v));
      svg.append(label);
    }
    // X labels (about 6)
    const every = Math.max(1, Math.ceil(n / 6));
    data.labels.forEach((lab, i) => {
      if (i % every !== 0 && i !== n - 1) return;
      if (i !== n - 1 && n - 1 - i < every / 2) return;
      const t = s('text', { class: 'tick', x: x(i), y: height - 8, 'text-anchor': i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle' });
      t.textContent = dateShort(lab + 'T12:00:00');
      svg.append(t);
    });

    // Area wash for the primary series, then lines on top.
    data.series.forEach((ser, si) => {
      const pts = ser.values.map((v, i) => [x(i), y(v)]);
      if (!pts.length) return;
      const d = pts.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)},${p[1].toFixed(1)}`).join('');
      if (si === 0) {
        svg.append(s('path', { class: 'area', d: `${d}L${pts[pts.length - 1][0].toFixed(1)},${y(0)}L${pts[0][0].toFixed(1)},${y(0)}Z`, style: `fill:${ser.color};fill-opacity:0.1` }));
      }
      svg.append(s('path', { class: 'line', d, style: `stroke:${ser.color}` }));
    });

    // Hover layer
    const cross = s('line', { class: 'crosshair', y1: pad.top, y2: pad.top + ih });
    svg.append(cross);
    const dots = data.series.map((ser) => {
      const c = s('circle', { class: 'hover-dot', r: 4.5, style: `fill:${ser.color}` });
      svg.append(c);
      return c;
    });
    const hit = s('rect', { x: pad.left - 10, y: 0, width: iw + 20, height, fill: 'transparent' });
    svg.append(hit);

    const move = (clientX) => {
      const rect = svg.getBoundingClientRect();
      const px = ((clientX - rect.left) / rect.width) * width;
      const i = Math.max(0, Math.min(n - 1, Math.round(((px - pad.left) / iw) * (n - 1))));
      const xx = x(i);
      cross.setAttribute('x1', xx);
      cross.setAttribute('x2', xx);
      data.series.forEach((ser, si) => {
        dots[si].setAttribute('cx', xx);
        dots[si].setAttribute('cy', y(ser.values[i] || 0));
      });
      tip.replaceChildren(
        h('div', { class: 'tt-title' }, new Date(data.labels[i] + 'T12:00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })),
        ...data.series.map((ser) => h('div', { class: 'tt-row' }, h('i', { style: { background: ser.color } }), ser.label, h('b', {}, num(ser.values[i] || 0)))),
      );
      const scale = rect.width / width;
      const tw = tip.offsetWidth;
      let left = xx * scale + 14;
      if (left + tw > rect.width) left = xx * scale - tw - 14;
      tip.style.left = `${Math.max(0, left)}px`;
      tip.style.top = `${pad.top * scale}px`;
      root.classList.add('hovering');
    };
    hit.addEventListener('mousemove', (e) => move(e.clientX));
    hit.addEventListener('touchstart', (e) => move(e.touches[0].clientX), { passive: true });
    hit.addEventListener('touchmove', (e) => move(e.touches[0].clientX), { passive: true });
    hit.addEventListener('mouseleave', () => root.classList.remove('hovering'));
    hit.addEventListener('touchend', () => setTimeout(() => root.classList.remove('hovering'), 1200));

    root.prepend(svg);
    if (first && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
      svg.querySelectorAll('.line').forEach((line) => {
        const len = Math.ceil(line.getTotalLength());
        line.style.setProperty('--len', String(len));
        line.classList.add('line-draw');
      });
    } else {
      svg.querySelectorAll('.area').forEach((a) => {
        a.style.animation = 'none';
        a.style.opacity = '1';
      });
    }
    first = false;
  }

  let raf = 0;
  const ro = new ResizeObserver(() => {
    cancelAnimationFrame(raf);
    raf = requestAnimationFrame(render);
  });
  ro.observe(root);
  render();

  return {
    update(next) {
      data = next;
      render();
    },
    destroy() {
      ro.disconnect();
      root.remove();
    },
  };
}

/** Accessible table alternative for a time series. */
export function seriesTable(data) {
  return h('div', { class: 'table-wrap', style: { maxHeight: '280px' } },
    h('table', { class: 'table' },
      h('thead', {}, h('tr', {}, h('th', {}, 'Date'), ...data.series.map((ser) => h('th', { class: 'num' }, ser.label)))),
      h('tbody', {}, [...data.labels].map((lab, i) => ({ lab, i })).reverse().map(({ lab, i }) =>
        h('tr', {}, h('td', {}, new Date(lab + 'T12:00:00').toLocaleDateString()), ...data.series.map((ser) => h('td', { class: 'num' }, num(ser.values[i] || 0)))))),
    ));
}

export function sparkline(values, color = 'var(--series-1)') {
  const w = 96;
  const hgt = 34;
  const max = Math.max(1, ...values);
  const svg = s('svg', { viewBox: `0 0 ${w} ${hgt}`, class: 'spark', 'aria-hidden': 'true' });
  if (values.length < 2) return svg;
  const pts = values.map((v, i) => [(i / (values.length - 1)) * (w - 4) + 2, hgt - 3 - (v / max) * (hgt - 6)]);
  const d = pts.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)},${p[1].toFixed(1)}`).join('');
  svg.append(s('path', { d: `${d}L${pts[pts.length - 1][0]},${hgt}L${pts[0][0]},${hgt}Z`, style: `fill:${color};fill-opacity:0.1` }));
  svg.append(s('path', { d, fill: 'none', style: `stroke:${color}`, 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }));
  const last = pts[pts.length - 1];
  svg.append(s('circle', { cx: last[0], cy: last[1], r: 3, style: `fill:${color};stroke:var(--surface)`, 'stroke-width': 2 }));
  return svg;
}

/** Horizontal bars: [{ label, value, prefix(Node) }] — value labelled at the tip. */
export function hbars(items, { color = 'var(--series-1)', format = num } = {}) {
  const max = Math.max(1, ...items.map((i) => i.value));
  return h('div', { class: 'hbars' }, items.map((it, i) =>
    h('div', { class: 'hbar' },
      h('div', { class: 'hb-top' }, h('span', {}, it.prefix || null, h('span', { class: 'truncate' }, it.label)), h('b', {}, format(it.value))),
      h('div', { class: 'hb-track' }, h('div', { class: 'hb-fill', style: { width: `${Math.max(1.5, (it.value / max) * 100)}%`, background: color, animationDelay: `${i * 80}ms` } })),
    )));
}
