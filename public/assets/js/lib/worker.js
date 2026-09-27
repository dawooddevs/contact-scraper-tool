// Browser-driven worker pump. While campaigns are running and this tab is
// open, it keeps asking the server to process the next batch of work
// (worker.tick). Shared hosting has no daemons, so this — together with the
// optional cron job — is what moves campaigns forward.

import { api } from './api.js';
import { state, emit } from './state.js';

let timer = null;
let inFlight = false;
let stopped = true;
let failures = 0;

function schedule(ms) {
  clearTimeout(timer);
  if (!stopped) timer = setTimeout(loop, ms);
}

async function loop() {
  if (!state.user) {
    workerPump.stop();
    return;
  }
  if (stopped || inFlight) return;
  inFlight = true;
  let delay = 15000;
  try {
    if (state.worker.running > 0 && state.worker.browserEnabled) {
      emit('worker:busy', true);
      const res = await api.post('worker.tick', {});
      failures = 0;
      state.worker.running = res.running ?? 0;
      state.worker.busy = !!res.busy;
      state.worker.lastTick = new Date();
      state.worker.error = null;
      if (res.disabled) state.worker.browserEnabled = false;
      emit('campaigns:live', res.campaigns || []);
      if (res.qualified) emit('leads:new', res.qualified);
      if (res.busy) delay = 4000; // cron (or another tab) is working right now
      else if (res.next_in !== null && res.next_in !== undefined && res.next_in > 1) delay = Math.min(res.next_in, 20) * 1000;
      else delay = res.running > 0 ? 600 : 8000;
    } else {
      const res = await api.get('worker.status');
      failures = 0;
      state.worker.running = res.running ?? 0;
      state.worker.browserEnabled = !!res.browser_enabled;
      state.worker.cronActive = !!res.cron_active;
      state.worker.error = null;
      emit('campaigns:live', res.campaigns || []);
      delay = state.worker.running > 0 ? (state.worker.browserEnabled ? 300 : 5000) : 20000;
    }
  } catch (e) {
    failures++;
    state.worker.error = e?.message || 'Worker error';
    delay = Math.min(60000, 5000 * failures);
  } finally {
    inFlight = false;
    emit('worker:state', { ...state.worker });
    emit('worker:busy', false);
    schedule(delay);
  }
}

export const workerPump = {
  start() {
    if (!stopped) return;
    stopped = false;
    schedule(300);
  },
  stop() {
    stopped = true;
    clearTimeout(timer);
  },
  /** Something changed (campaign started/resumed): run a tick right away. */
  kick(runningDelta = 0) {
    state.worker.running = Math.max(0, state.worker.running + runningDelta);
    if (!inFlight) schedule(150);
  },
  setRunning(n) {
    state.worker.running = n;
  },
};

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && !stopped && !inFlight) schedule(200);
});
