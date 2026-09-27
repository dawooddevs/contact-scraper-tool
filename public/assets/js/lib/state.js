// Shared app state + a tiny event bus.

export const state = {
  user: null,
  app: { name: 'Scrap', version: '' },
  catalogue: null,
  worker: { running: 0, busy: false, lastTick: null, error: null, browserEnabled: true, cronActive: false },
};

const bus = new EventTarget();

export function emit(name, detail) {
  bus.dispatchEvent(new CustomEvent(name, { detail }));
}

/** Subscribe; returns an unsubscribe function. */
export function on(name, fn) {
  const handler = (e) => fn(e.detail);
  bus.addEventListener(name, handler);
  return () => bus.removeEventListener(name, handler);
}

/** Safe localStorage access (private mode / blocked storage). */
export const prefs = {
  get(key, fallback = null) {
    try {
      const v = localStorage.getItem('scrap:' + key);
      return v === null ? fallback : JSON.parse(v);
    } catch {
      return fallback;
    }
  },
  set(key, value) {
    try {
      localStorage.setItem('scrap:' + key, JSON.stringify(value));
    } catch {
      /* ignore */
    }
  },
};
