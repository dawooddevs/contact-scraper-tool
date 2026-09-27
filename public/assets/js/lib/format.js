// Formatting helpers (numbers, dates, durations).

const nf = new Intl.NumberFormat();
const cf = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 });

export const num = (n) => nf.format(Number(n) || 0);

export const compact = (n) => {
  const v = Number(n) || 0;
  return Math.abs(v) < 10000 ? nf.format(v) : cf.format(v);
};

export const pct = (v, digits = 0) => `${((Number(v) || 0) * 100).toFixed(digits)}%`;

export const plural = (n, one, many = one + 's') => `${num(n)} ${Number(n) === 1 ? one : many}`;

export function parseDate(value) {
  if (!value) return null;
  const d = value instanceof Date ? value : new Date(value);
  return Number.isNaN(d.getTime()) ? null : d;
}

const rtf = typeof Intl.RelativeTimeFormat === 'function' ? new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }) : null;

export function ago(value) {
  const d = parseDate(value);
  if (!d) return '—';
  const diff = (d.getTime() - Date.now()) / 1000;
  const abs = Math.abs(diff);
  if (abs < 45) return diff <= 0 ? 'just now' : 'in a moment';
  const units = [
    ['year', 31536000],
    ['month', 2592000],
    ['week', 604800],
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
  ];
  for (const [unit, secs] of units) {
    if (abs >= secs || unit === 'minute') {
      const v = Math.round(diff / secs);
      return rtf ? rtf.format(v, unit) : `${Math.abs(v)} ${unit}${Math.abs(v) === 1 ? '' : 's'} ${v < 0 ? 'ago' : 'from now'}`;
    }
  }
  return d.toLocaleString();
}

export function dateTime(value) {
  const d = parseDate(value);
  if (!d) return '—';
  return d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export function dateShort(value) {
  const d = parseDate(value);
  if (!d) return '—';
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

export function time(value) {
  const d = parseDate(value);
  if (!d) return '';
  return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
}

export function duration(from, to) {
  const a = parseDate(from);
  if (!a) return '—';
  const b = parseDate(to) || new Date();
  let s = Math.max(0, Math.round((b - a) / 1000));
  const h = Math.floor(s / 3600);
  s -= h * 3600;
  const m = Math.floor(s / 60);
  s -= m * 60;
  if (h) return `${h}h ${m}m`;
  if (m) return `${m}m ${s}s`;
  return s ? `${s}s` : 'under a second';
}

export function hostOf(url) {
  try {
    return new URL(url).hostname.replace(/^www\./, '');
  } catch {
    return url || '';
  }
}

export function bytes(n) {
  const v = Number(n) || 0;
  if (v < 1024) return `${v} B`;
  if (v < 1048576) return `${(v / 1024).toFixed(1)} KB`;
  if (v < 1073741824) return `${(v / 1048576).toFixed(1)} MB`;
  return `${(v / 1073741824).toFixed(2)} GB`;
}

export const STATUS_LABELS = {
  draft: 'Draft',
  running: 'Running',
  paused: 'Paused',
  completed: 'Completed',
  stopped: 'Stopped',
  failed: 'Failed',
};
