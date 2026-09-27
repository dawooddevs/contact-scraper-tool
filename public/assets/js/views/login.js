import { h, ic, clear } from '../lib/dom.js';
import { LOGO } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { field, input, withLoading, showFieldErrors, clearFieldErrors, alertBox } from '../lib/ui.js';

export function renderLogin(root, { appName = 'Scrap', onSuccess }) {
  const email = input({ type: 'email', name: 'email', autocomplete: 'username', placeholder: 'you@company.com', autofocus: true, required: true });
  const password = input({ type: 'password', name: 'password', autocomplete: 'current-password', placeholder: '••••••••', required: true });
  const reveal = h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-icon input-action', 'aria-label': 'Show password', 'data-tip': 'Show password' }, ic('eye'));
  reveal.addEventListener('click', () => {
    const show = password.type === 'password';
    password.type = show ? 'text' : 'password';
    reveal.replaceChildren(ic(show ? 'eye-off' : 'eye'));
  });
  const remember = h('input', { type: 'checkbox', checked: true });
  const errorSlot = h('div', { class: 'form-error' });
  const submit = h('button', { type: 'submit', class: 'btn btn-primary btn-lg btn-block' }, h('span', {}, 'Sign in'), ic('arrow-right'));

  const form = h('form', { novalidate: true },
    field('Email', email, { name: 'email' }),
    field('Password', h('div', { class: 'input-group' }, password, reveal), { name: 'password' }),
    h('div', { class: 'auth-foot' }, h('label', { class: 'check' }, remember, 'Keep me signed in')),
    submit,
    errorSlot,
  );

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    clearFieldErrors(form);
    clear(errorSlot);
    withLoading(submit, async () => {
      try {
        const data = await api.post('auth.login', { email: email.value.trim(), password: password.value, remember: remember.checked });
        onSuccess(data);
      } catch (err) {
        if (err.fields && Object.keys(err.fields).length) showFieldErrors(form, err.fields);
        else errorSlot.append(alertBox('danger', '', err.message));
        form.closest('.auth-card')?.animate?.([{ transform: 'translateX(0)' }, { transform: 'translateX(-6px)' }, { transform: 'translateX(6px)' }, { transform: 'translateX(0)' }], { duration: 280 });
        password.select();
      }
    });
  });

  clear(root).append(
    h('div', { class: 'auth' },
      h('div', { class: 'auth-bg' }, h('div', { class: 'blob b1' }), h('div', { class: 'blob b2' }), h('div', { class: 'blob b3' }), h('div', { class: 'auth-grid' })),
      h('div', { class: 'auth-card' },
        h('div', { class: 'brand', html: LOGO.replace('<svg', '<svg class="brand-mark"') }),
        h('h1', {}, `Sign in to ${appName}`),
        h('p', { class: 'sub' }, 'Find, qualify and export business leads.'),
        form,
      ),
    ),
  );
  requestAnimationFrame(() => email.focus());
}
