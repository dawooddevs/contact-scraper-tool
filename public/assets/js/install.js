// Installer UI (public/install.php).

import { h, ic, clear } from './lib/dom.js';
import { field, input, button, withLoading, alertBox, showFieldErrors, clearFieldErrors, copyText } from './lib/ui.js';

const root = document.getElementById('install-root');

async function post(action, body = {}) {
  const res = await fetch('install.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ action, ...body }),
  });
  let data;
  try {
    data = await res.json();
  } catch {
    throw new Error(`Unexpected server response (HTTP ${res.status}).`);
  }
  return data;
}

const steps = ['Requirements', 'Database', 'Admin account'];
let current = 0;
const values = { db_host: 'localhost', db_port: '3306', db_name: '', db_user: '', db_pass: '', admin_name: '', admin_email: '', admin_password: '' };

function stepper() {
  return h('div', { class: 'flex items-center gap-2 mb-4', style: { justifyContent: 'center' } }, steps.map((label, i) => h('div', { class: ['wstep', i === current && 'active', i < current && 'done'], style: { padding: '6px 8px' } },
    h('span', { class: 'num' }, i < current ? ic('check') : String(i + 1)), h('span', { class: 'label', style: { fontSize: '12.5px' } }, label))));
}

function bind(el, key) {
  el.value = values[key];
  el.addEventListener('input', () => { values[key] = el.value; });
  return el;
}

async function renderRequirements() {
  current = 0;
  clear(root).append(stepper(), h('div', { class: 'skeleton sk-block' }));
  let data;
  try {
    data = await post('requirements');
  } catch (e) {
    clear(root).append(stepper(), alertBox('danger', 'Error:', e.message));
    return;
  }
  const list = h('div', { class: 'stack' }, data.checks.map((c) => h('div', { class: 'flex items-center gap-3' },
    h('span', { class: ['badge', c.ok ? 'completed' : 'failed'] }, ic(c.ok ? 'check' : 'x')),
    h('div', {}, h('strong', { style: { fontSize: '13.5px' } }, c.label), c.ok ? null : h('div', { class: 'muted small' }, c.detail)))));
  clear(root).append(stepper(), h('div', { class: 'card card-pad' }, list),
    data.ok
      ? button('Continue', { variant: 'primary', cls: 'btn-block btn-lg mt-4', iconRight: 'arrow-right', onClick: renderDatabase })
      : h('div', {}, h('div', { class: 'mt-4' }, alertBox('warning', 'Fix the items above,', 'then re-check.')), button('Re-check', { cls: 'btn-block mt-4', icon: 'refresh', onClick: renderRequirements })));
}

function renderDatabase() {
  current = 1;
  const form = h('div', {},
    h('p', { class: 'text-2 small mb-4' }, 'In cPanel open MySQL® Databases: create a database and a user, then add the user to the database with ALL PRIVILEGES. Enter those details here.'),
    h('div', { class: 'row', style: { gridTemplateColumns: '2fr 1fr' } },
      field('Host', bind(input({ autocomplete: 'off' }), 'db_host'), { name: 'db_host' }),
      field('Port', bind(input({ type: 'number' }), 'db_port'), { name: 'db_port' })),
    h('div', { class: 'mt-4' }, field('Database name', bind(input({ placeholder: 'cpaneluser_scrap', autocomplete: 'off' }), 'db_name'), { name: 'db_name' })),
    h('div', { class: 'row mt-4' },
      field('Username', bind(input({ placeholder: 'cpaneluser_scrap', autocomplete: 'off' }), 'db_user'), { name: 'db_user' }),
      field('Password', bind(input({ type: 'password', autocomplete: 'new-password' }), 'db_pass'), { name: 'db_pass' })),
  );
  const result = h('div', { class: 'mt-4' });
  const next = button('Test & continue', { variant: 'primary', cls: 'btn-block btn-lg', iconRight: 'arrow-right', onClick: (e) => withLoading(e.currentTarget, async () => {
    clear(result);
    try {
      const res = await post('test_db', values);
      if (res.ok) renderAdmin();
      else result.append(alertBox('danger', 'Could not connect.', res.message));
    } catch (err) {
      result.append(alertBox('danger', 'Error:', err.message));
    }
  }) });
  clear(root).append(stepper(), form, result, h('div', { class: 'mt-4' }, next),
    button('Back', { variant: 'ghost', cls: 'btn-block mt-2', icon: 'arrow-left', onClick: renderRequirements }));
  form.querySelector('input[autocomplete="off"]')?.focus();
}

function renderAdmin() {
  current = 2;
  const form = h('div', {},
    h('p', { class: 'text-2 small mb-4' }, 'This is the account you will use to sign in.'),
    field('Your name', bind(input({ autocomplete: 'name' }), 'admin_name'), { name: 'admin_name' }),
    field('Email', bind(input({ type: 'email', autocomplete: 'email' }), 'admin_email'), { name: 'admin_email' }),
    field('Password', bind(input({ type: 'password', autocomplete: 'new-password' }), 'admin_password'), { name: 'admin_password', hint: 'At least 8 characters.' }),
  );
  const result = h('div', { class: 'mt-4' });
  const go = button('Install Scrap', { variant: 'primary', cls: 'btn-block btn-lg', icon: 'sparkles', onClick: (e) => withLoading(e.currentTarget, async () => {
    clear(result);
    clearFieldErrors(form);
    try {
      const res = await post('install', values);
      if (res.ok) {
        renderDone(res);
        return;
      }
      if (res.step === 'database') {
        renderDatabase();
        return;
      }
      if (res.fields) showFieldErrors(form, res.fields);
      result.append(alertBox('danger', '', res.message));
    } catch (err) {
      result.append(alertBox('danger', 'Error:', err.message));
    }
  }) });
  clear(root).append(stepper(), form, result, h('div', { class: 'mt-4' }, go),
    button('Back', { variant: 'ghost', cls: 'btn-block mt-2', icon: 'arrow-left', onClick: renderDatabase }));
  form.querySelector('input')?.focus();
}

function renderDone(res) {
  current = 3;
  clear(root).append(
    h('div', { class: 'empty', style: { padding: '10px 0 20px' } },
      h('div', { class: 'icon-circle', style: { width: '56px', height: '56px', borderRadius: '18px', background: 'var(--success-soft)', color: 'var(--success)' } }, ic('check-circle')),
      h('h3', {}, 'All set!'),
      h('p', {}, 'Scrap is installed. One more (optional but recommended) step: add a cron job in cPanel → Cron Jobs, running once per minute:')),
    h('div', { class: 'flex items-center gap-2', style: { background: 'var(--surface-2)', border: '1px solid var(--border)', borderRadius: '10px', padding: '10px 12px' } },
      h('code', { style: { flex: '1', wordBreak: 'break-all', fontSize: '12px' } }, res.cron),
      button('Copy', { size: 'sm', icon: 'copy', onClick: () => copyText(res.cron, 'Cron command copied') })),
    h('a', { class: 'btn btn-primary btn-lg btn-block mt-5', href: './' }, 'Open Scrap', ic('arrow-right')),
  );
}

renderRequirements();
