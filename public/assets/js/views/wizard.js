// New-campaign wizard: country → search → data & conditions → review.

import { h, ic, clear } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { emit } from '../lib/state.js';
import { workerPump } from '../lib/worker.js';
import { modal, button, withLoading, tagsInput, field, input, select, segmented, switchInput, alertBox, flag, notify, showError, showFieldErrors, clearFieldErrors } from '../lib/ui.js';
import { num, plural } from '../lib/format.js';
import { loadCatalogue, sourceLogo } from './common.js';

const STEPS = [
  { id: 'where', label: 'Country & source' },
  { id: 'what', label: 'Search' },
  { id: 'data', label: 'Data & conditions' },
  { id: 'review', label: 'Limits & review' },
];

const COLLECT = [
  { key: 'phone', label: 'Phone numbers', desc: 'From the directory listing', icon: 'phone' },
  { key: 'email', label: 'Email addresses', desc: 'From listings and business websites', icon: 'mail' },
  { key: 'website', label: 'Website link', desc: 'The business’s own site', icon: 'globe' },
  { key: 'address', label: 'Address', desc: 'Street, suburb, postcode', icon: 'pin' },
  { key: 'socials', label: 'Social profiles', desc: 'Facebook, Instagram, LinkedIn…', icon: 'link' },
  { key: 'rating', label: 'Rating & reviews', desc: 'Star rating and review count', icon: 'star' },
];

const REQUIRE = [
  { key: 'email', label: 'Business email', icon: 'mail' },
  { key: 'phone', label: 'Phone number', icon: 'phone' },
  { key: 'website', label: 'Website', icon: 'globe' },
  { key: 'address', label: 'Address', icon: 'pin' },
  { key: 'socials', label: 'Social profile', icon: 'link' },
];

const FIELD_STEP = { country: 0, source: 0, keywords: 1, locations: 1, name: 3 };

let openInstance = null;

/**
 * openWizard({ navigate, preset, editId })
 * preset: campaign object (e.g. a draft) used to pre-fill every step.
 */
export async function openWizard({ navigate, preset = null, editId = null } = {}) {
  if (openInstance) return;
  let catalogue;
  try {
    catalogue = await loadCatalogue(true);
  } catch (e) {
    showError(e, 'Could not load sources');
    return;
  }
  const countries = catalogue.countries.filter((c) => c.sources.length);

  const s = {
    step: 0,
    country: preset?.country || (countries.length === 1 ? countries[0].code : ''),
    source: preset?.source || '',
    keywords: preset?.keywords || [],
    locations: preset?.locations || [],
    collect: { phone: true, email: true, website: true, address: true, socials: true, rating: true, ...(preset?.options?.collect || {}) },
    require: { email: false, phone: false, website: false, address: false, socials: false, ...(preset?.options?.require || {}) },
    match: preset?.options?.match || 'all',
    crawl_website: preset?.options?.crawl_website ?? true,
    skip_duplicates: preset?.options?.skip_duplicates ?? true,
    exclude_free_emails: preset?.options?.exclude_free_emails ?? false,
    min_rating: preset?.options?.min_rating || 0,
    min_reviews: preset?.options?.min_reviews || 0,
    max_leads: preset?.options?.max_leads ?? 100,
    max_pages: preset?.options?.max_pages || 10,
    name: preset?.name || '',
  };
  const country = () => countries.find((c) => c.code === s.country);
  const source = () => {
    const c = country();
    if (!c) return null;
    return c.sources.find((x) => x.key === s.source) || c.sources.find((x) => x.settings.enabled) || c.sources[0];
  };

  // Persist tag inputs across step re-renders.
  let kwInput = null;
  let locInput = null;
  let direction = 'next';

  const stepButtons = STEPS.map((st, i) => h('button', { type: 'button', class: 'wstep', disabled: true, onClick: () => { if (i < s.step) go(i); } },
    h('span', { class: 'num' }, String(i + 1)), h('span', { class: 'label' }, st.label)));
  const body = h('div', { class: 'wizard-body' });
  const backBtn = button('Back', { icon: 'arrow-left', variant: 'ghost', onClick: () => go(s.step - 1) });
  const nextBtn = button('Continue', { variant: 'primary', iconRight: 'arrow-right', onClick: () => next() });
  const draftBtn = button(editId ? 'Save draft' : 'Save as draft', { icon: 'file', onClick: (e) => submit(false, e.currentTarget) });
  const startBtn = button('Start campaign', { variant: 'primary', icon: 'play', onClick: (e) => submit(true, e.currentTarget) });
  const stepInfo = h('span', { class: 'muted small' });
  const footer = h('div', { class: 'wizard-footer' }, backBtn, stepInfo, h('div', { class: 'spacer' }), draftBtn, nextBtn, startBtn);

  const layout = h('div', { class: 'wizard' },
    h('div', { class: 'wizard-steps' }, h('h2', {}, editId ? 'Edit campaign' : 'New campaign'), stepButtons),
    h('div', { class: 'wizard-main' }, body, footer),
  );

  const m = modal({ body: layout, size: 'xl', cls: 'wizard-modal', onClose: () => { openInstance = null; } });
  m.body.style.padding = '0';
  m.el.querySelector('.modal-close').style.zIndex = '2';
  openInstance = m;

  function go(i) {
    direction = i >= s.step ? 'next' : 'prev';
    s.step = Math.max(0, Math.min(STEPS.length - 1, i));
    render();
  }

  function next() {
    const err = validate(s.step);
    if (err) {
      showStepError(err);
      return;
    }
    go(s.step + 1);
  }

  function showStepError(err) {
    clearFieldErrors(body);
    if (!showFieldErrors(body, err)) notify.warning('Check this step', Object.values(err)[0]);
  }

  function validate(step) {
    if (step === 0 && !s.country) return { country: 'Choose a country to continue.' };
    if (step === 1) {
      syncTags();
      const e = {};
      if (!s.keywords.length) e.keywords = 'Add at least one category or keyword.';
      if (!s.locations.length) e.locations = 'Add at least one location.';
      if (s.keywords.length * s.locations.length > 60) e.locations = `That is ${s.keywords.length * s.locations.length} searches. Keep categories × locations at 60 or fewer.`;
      return Object.keys(e).length ? e : null;
    }
    return null;
  }

  function syncTags() {
    if (kwInput) s.keywords = kwInput.values;
    if (locInput) s.locations = locInput.values;
  }

  function render() {
    stepButtons.forEach((b, i) => {
      b.classList.toggle('active', i === s.step);
      b.classList.toggle('done', i < s.step);
      b.disabled = i >= s.step;
      b.querySelector('.num').replaceChildren(i < s.step ? ic('check') : String(i + 1));
    });
    const last = s.step === STEPS.length - 1;
    backBtn.classList.toggle('hidden', s.step === 0);
    nextBtn.classList.toggle('hidden', last);
    startBtn.classList.toggle('hidden', !last);
    draftBtn.classList.toggle('hidden', !last);
    stepInfo.textContent = `Step ${s.step + 1} of ${STEPS.length}`;

    const panel = h('div', { class: direction === 'next' ? 'step-enter-next' : 'step-enter-prev' });
    [renderWhere, renderWhat, renderData, renderReview][s.step](panel);
    clear(body).append(panel);
    body.scrollTop = 0;
    const focus = panel.querySelector('input:not([type=checkbox]):not([type=radio])');
    if (focus && s.step === 1) setTimeout(() => focus.focus(), 50);
  }

  /* ---------------------------------------------------------- step 1 */
  function renderWhere(panel) {
    panel.append(h('h3', {}, 'Where do you want to find leads?'), h('p', { class: 'lead-text' }, 'Each country is searched on the directory that works best there. More countries and sites can be added over time.'));
    const grid = h('div', { class: 'country-grid' });
    countries.forEach((c) => {
      const radio = h('input', { type: 'radio', name: 'wiz-country', value: c.code, checked: s.country === c.code });
      radio.addEventListener('change', () => {
        s.country = c.code;
        s.source = '';
        if (!preset || preset.country !== c.code) {
          s.locations = [];
          locInput = null;
        }
        s.max_pages = Math.min(s.max_pages, source()?.max_pages || s.max_pages);
        clearFieldErrors(body);
        renderSourceInfo();
      });
      grid.append(h('label', { class: 'option-card country-card' },
        radio,
        h('span', { class: 'oc-icon flag-icon' }, flag(c.code, 'lg')),
        h('span', {}, h('strong', {}, c.name), h('small', {}, c.sources.map((x) => x.name).join(', '))),
        h('span', { class: 'oc-check' }, ic('check')),
      ));
      radio.addEventListener('dblclick', () => next());
    });
    panel.append(h('div', { class: 'field', dataset: { field: 'country' } }, grid, h('div', { class: 'error-text' })));
    const info = h('div');
    panel.append(info);
    function renderSourceInfo() {
      clear(info);
      const c = country();
      if (!c) return;
      const src = source();
      if (c.sources.length > 1) {
        const sel = select(c.sources.map((x) => ({ value: x.key, label: x.name })), src.key);
        sel.addEventListener('change', () => {
          s.source = sel.value;
          renderSourceInfo();
        });
        info.append(h('div', { class: 'mt-4' }, field('Data source', sel)));
      }
      info.append(h('div', { class: 'source-card' },
        sourceLogo(src.key),
        h('div', { style: { flex: '1', minWidth: '0' } },
          h('div', { class: 'flex items-center gap-2 wrap' }, h('strong', {}, src.name), h('span', { class: 'muted small' }, src.domain),
            src.settings.enabled ? null : h('span', { class: 'badge failed' }, 'Disabled')),
          h('div', { class: 'small text-2 mt-1' }, src.description),
          h('div', { class: 'provides' }, src.provides.map((p) => h('span', { class: 'badge accent' }, ic(p === 'rating' ? 'star' : p === 'address' ? 'pin' : p === 'email' ? 'mail' : p === 'website' ? 'globe' : 'phone'), p))),
        ),
      ));
      if (!src.settings.enabled) {
        info.append(h('div', { class: 'mt-3' }, alertBox('danger', 'This source is disabled.', 'Enable it on the Sources page before starting a campaign.')));
      } else if (src.notes && /direct/i.test(src.connection_label || '')) {
        info.append(h('div', { class: 'mt-3' }, alertBox('warning', 'Heads-up:', src.notes)));
      }
    }
    renderSourceInfo();
  }

  /* ---------------------------------------------------------- step 2 */
  function renderWhat(panel) {
    const c = country();
    const src = source();
    panel.append(h('h3', {}, 'What are you looking for?'), h('p', { class: 'lead-text' }, `Add one or more categories and locations. Every combination is searched on ${src.name}.`));

    kwInput = tagsInput({ values: s.keywords, suggestions: catalogue.categories, placeholder: `e.g. ${src.keyword_placeholder || 'Restaurants'} — press Enter to add`, onChange: (v) => { s.keywords = v; updateCount(); } });
    locInput = tagsInput({ values: s.locations, suggestions: c.locations, placeholder: `e.g. ${src.location_placeholder || 'City'} — press Enter to add`, onChange: (v) => { s.locations = v; updateCount(); } });
    kwInput.input.addEventListener('input', updateCount);
    locInput.input.addEventListener('input', updateCount);

    const quickCats = h('div', { class: 'chips mt-2' }, ['Restaurants', 'Cafes', 'Plumbers', 'Electricians', 'Dentists', 'Real Estate Agents', 'Lawyers', 'Hair Salons'].map((cat) =>
      h('button', { type: 'button', class: 'chip', onClick: () => { kwInput.add(cat); updateCount(); } }, ic('plus'), cat)));
    const quickLocs = h('div', { class: 'chips mt-2' }, c.locations.slice(0, 8).map((loc) =>
      h('button', { type: 'button', class: 'chip', onClick: () => { locInput.add(loc); updateCount(); } }, ic('plus'), loc)));

    const count = h('div', { class: 'alert info mt-5' }, ic('zap'), h('div', { class: 'alert-body' }));
    function updateCount() {
      const k = kwInput.values.length;
      const l = locInput.values.length;
      const n = k * l;
      count.querySelector('.alert-body').replaceChildren(
        n ? h('span', {}, h('strong', {}, plural(n, 'search', 'searches')), ` will run (${plural(k, 'category', 'categories')} × ${plural(l, 'location')}). Duplicates across searches are merged automatically.`)
          : h('span', {}, 'Add a category and a location to see how many searches will run.'),
      );
    }
    panel.append(
      field('Categories or keywords', kwInput.el, { name: 'keywords', hint: 'Business type as you would type it on the directory, e.g. “Restaurants”, “Thai restaurant”, “Emergency plumber”.' }),
      quickCats,
      h('div', { class: 'mt-5' }),
      field('Locations', locInput.el, { name: 'locations', hint: src.location_hint }),
      quickLocs,
      count,
    );
    updateCount();
  }

  /* ---------------------------------------------------------- step 3 */
  function renderData(panel) {
    const src = source();
    panel.append(h('h3', {}, 'Which details do you need?'), h('p', { class: 'lead-text' }, 'Choose what to collect, then set the conditions a lead must meet to be kept.'));

    const collectCards = {};
    const collectGrid = h('div', { class: 'option-grid' });
    COLLECT.forEach((c) => {
      const cb = h('input', { type: 'checkbox', checked: !!s.collect[c.key] });
      cb.addEventListener('change', () => {
        s.collect[c.key] = cb.checked;
        sync();
      });
      const card = h('label', { class: 'option-card' }, cb, h('span', { class: 'oc-icon' }, ic(c.icon)), h('span', {}, h('strong', {}, c.label), h('small', { class: 'desc' }, c.desc)), h('span', { class: 'oc-check' }, ic('check')));
      collectCards[c.key] = { cb, card, desc: card.querySelector('.desc'), base: c.desc };
      collectGrid.append(card);
    });

    const requireCards = {};
    const requireGrid = h('div', { class: 'option-grid' });
    REQUIRE.forEach((r) => {
      const cb = h('input', { type: 'checkbox', checked: !!s.require[r.key] });
      cb.addEventListener('change', () => {
        s.require[r.key] = cb.checked;
        if (cb.checked) s.collect[r.key] = true;
        sync();
      });
      requireCards[r.key] = cb;
      requireGrid.append(h('label', { class: 'option-card' }, cb, h('span', { class: 'oc-icon' }, ic(r.icon)), h('span', {}, h('strong', {}, r.label), h('small', {}, 'Required')), h('span', { class: 'oc-check' }, ic('check'))));
    });

    const matchSeg = segmented([{ value: 'all', label: 'All of these' }, { value: 'any', label: 'Any of these' }], s.match, (v) => {
      s.match = v;
      sync();
    });
    const condSummary = h('div', { class: 'small text-2 mt-3' });

    const ratingSel = select([{ value: 0, label: 'Any rating' }, { value: 3, label: '3.0+' }, { value: 3.5, label: '3.5+' }, { value: 4, label: '4.0+' }, { value: 4.5, label: '4.5+' }], s.min_rating);
    ratingSel.addEventListener('change', () => {
      s.min_rating = Number(ratingSel.value);
      sync();
    });
    const reviewsInput = input({ type: 'number', min: 0, max: 100000, value: s.min_reviews, inputmode: 'numeric' });
    reviewsInput.addEventListener('input', () => {
      s.min_reviews = Math.max(0, parseInt(reviewsInput.value, 10) || 0);
      sync();
    });

    const crawl = switchInput({ checked: s.crawl_website, label: 'Visit websites to find emails', description: 'Checks each business’s homepage and contact pages. Most emails are found this way.', onChange: (v) => { s.crawl_website = v; sync(); } });
    const dup = switchInput({ checked: s.skip_duplicates, label: 'Skip leads from previous campaigns', description: 'Ignore businesses you already collected (same listing or phone number).', onChange: (v) => { s.skip_duplicates = v; } });
    const free = switchInput({ checked: s.exclude_free_emails, label: 'Business email domains only', description: 'Ignore Gmail, Yahoo, Hotmail, Bigpond… addresses.', onChange: (v) => { s.exclude_free_emails = v; } });
    const warn = h('div');

    function sync() {
      // Required fields are always collected; email/socials need the website.
      for (const key of Object.keys(collectCards)) {
        const { cb, card, desc, base } = collectCards[key];
        let locked = !!s.require[key];
        let why = locked ? 'Required by your conditions' : base;
        if (key === 'website' && (s.collect.email || s.collect.socials) && s.crawl_website) {
          s.collect.website = true;
          locked = true;
          why = 'Needed to find emails & socials';
        }
        if (key === 'rating' && (s.min_rating > 0 || s.min_reviews > 0)) {
          s.collect.rating = true;
          locked = true;
          why = 'Needed for the rating filter';
        }
        if (locked) s.collect[key] = true;
        cb.checked = !!s.collect[key];
        cb.disabled = locked;
        card.style.cursor = locked ? 'default' : '';
        desc.textContent = why;
      }
      const req = REQUIRE.filter((r) => s.require[r.key]).map((r) => r.label.toLowerCase());
      condSummary.textContent = req.length
        ? `Only leads with ${req.join(s.match === 'all' ? ' AND ' : ' OR ')} are kept. Everything else is marked as rejected (you can still review it).`
        : 'No conditions: every listing found becomes a lead.';
      clear(warn);
      if (s.require.email && !s.crawl_website && !src.provides.includes('email')) {
        warn.append(alertBox('warning', 'Emails need website crawling.', `${src.name} doesn’t show email addresses, so with crawling off almost every lead will be rejected.`));
      } else if (s.require.email && !src.provides.includes('email')) {
        warn.append(alertBox('info', 'How emails are found:', `${src.name} doesn’t list emails. Scrap visits each business website (home + contact pages) to find them — typically 30–60% of businesses publish one.`));
      }
    }

    panel.append(
      h('div', { class: 'label mb-3' }, 'Collect'),
      collectGrid,
      h('div', { class: 'cond-box mt-5' },
        h('div', { class: 'cond-head' }, h('div', {}, h('div', { class: 'label' }, ic('filter'), 'Lead conditions'), h('div', { class: 'hint' }, 'A lead must have…')), matchSeg),
        requireGrid,
        condSummary,
      ),
      h('div', { class: 'mt-3' }, warn),
      h('div', { class: 'row mt-5' }, field('Minimum rating', ratingSel, { optional: true }), field('Minimum reviews', reviewsInput, { optional: true })),
      h('div', { class: 'mt-5 stack' }, crawl, dup, free),
    );
    sync();
  }

  /* ---------------------------------------------------------- step 4 */
  function renderReview(panel) {
    syncTags();
    const c = country();
    const src = source();
    const autoName = `${s.keywords[0] || 'Leads'}${s.keywords.length > 1 ? ` +${s.keywords.length - 1}` : ''} in ${s.locations[0] || ''}${s.locations.length > 1 ? ` +${s.locations.length - 1}` : ''}`;
    const nameInput = input({ value: s.name, placeholder: autoName, maxlength: 190 });
    nameInput.addEventListener('input', () => { s.name = nameInput.value; });

    const maxLeads = input({ type: 'number', min: 0, max: 100000, value: s.max_leads, inputmode: 'numeric' });
    maxLeads.addEventListener('input', () => { s.max_leads = Math.max(0, parseInt(maxLeads.value, 10) || 0); updateSummary(); });
    const presets = h('div', { class: 'chips mt-2' }, [25, 50, 100, 250, 500, 0].map((n) => h('button', { type: 'button', class: ['chip', s.max_leads === n && 'active'], onClick: (e) => {
      s.max_leads = n;
      maxLeads.value = String(n);
      presets.querySelectorAll('.chip').forEach((x) => x.classList.remove('active'));
      e.currentTarget.classList.add('active');
      updateSummary();
    } }, n ? num(n) : 'Unlimited')));
    maxLeads.addEventListener('input', () => presets.querySelectorAll('.chip').forEach((x) => x.classList.remove('active')));

    const maxPages = input({ type: 'number', min: 1, max: src.max_pages, value: Math.min(s.max_pages, src.max_pages), inputmode: 'numeric' });
    maxPages.addEventListener('input', () => { s.max_pages = Math.max(1, Math.min(src.max_pages, parseInt(maxPages.value, 10) || 1)); updateSummary(); });

    const summary = h('dl', { class: 'summary-list' });
    function updateSummary() {
      const req = REQUIRE.filter((r) => s.require[r.key]).map((r) => r.label);
      const collected = COLLECT.filter((x) => s.collect[x.key]).map((x) => x.label);
      const searches = s.keywords.length * s.locations.length;
      const rows = [
        ['Country', h('span', { class: 'flex items-center gap-2' }, flag(c.code), c.name)],
        ['Source', `${src.name} (${src.domain})`],
        ['Categories', s.keywords.join(', ')],
        ['Locations', s.locations.join(', ')],
        ['Searches', `${plural(searches, 'search', 'searches')} × up to ${plural(s.max_pages, 'page')} (≈${num(searches * s.max_pages * src.results_per_page)} listings max)`],
        ['Collect', collected.join(', ') || 'Name only'],
        ['Conditions', req.length ? `Must have ${req.join(s.match === 'all' ? ' + ' : ' or ')}` : 'None — keep every listing'],
        ['Stop after', s.max_leads ? `${num(s.max_leads)} qualified leads` : 'All pages are processed'],
      ];
      if (s.min_rating || s.min_reviews) rows.splice(7, 0, ['Filters', [s.min_rating ? `rating ≥ ${s.min_rating}` : '', s.min_reviews ? `${s.min_reviews}+ reviews` : ''].filter(Boolean).join(', ')]);
      clear(summary);
      rows.forEach(([k, v]) => summary.append(h('dt', {}, k), h('dd', {}, v)));
    }
    updateSummary();

    panel.append(
      h('h3', {}, 'Almost there'), h('p', { class: 'lead-text' }, 'Set limits and review your campaign before starting it.'),
      field('Campaign name', nameInput, { name: 'name', optional: true, hint: 'Leave empty to use the suggested name.' }),
      h('div', { class: 'row mt-4' },
        h('div', {}, field('Target qualified leads', maxLeads, { name: 'max_leads', hint: 'The campaign stops when this many leads qualify. 0 = no limit.' }), presets),
        field('Max pages per search', maxPages, { name: 'max_pages', hint: `${src.name} shows about ${src.results_per_page} results per page (max ${src.max_pages} pages).` }),
      ),
      h('div', { class: 'label mt-5 mb-3' }, 'Summary'),
      summary,
    );
  }

  /* ---------------------------------------------------------- submit */
  async function submit(start, btn) {
    syncTags();
    for (let i = 0; i < 2; i++) {
      const err = validate(i);
      if (err) {
        go(i);
        setTimeout(() => showStepError(err), 60);
        return;
      }
    }
    const payload = {
      name: s.name.trim(),
      country: s.country,
      source: source()?.key,
      keywords: s.keywords,
      locations: s.locations,
      start,
      options: {
        collect: s.collect,
        require: s.require,
        match: s.match,
        max_leads: s.max_leads,
        max_pages: s.max_pages,
        crawl_website: s.crawl_website,
        skip_duplicates: s.skip_duplicates,
        exclude_free_emails: s.exclude_free_emails,
        min_rating: s.min_rating,
        min_reviews: s.min_reviews,
      },
    };
    await withLoading(btn, async () => {
      try {
        let campaign;
        if (editId) {
          campaign = await api.post('campaigns.update', { id: editId, ...payload });
          if (start) campaign = await api.post('campaigns.action', { id: editId, action: 'start' });
        } else {
          campaign = await api.post('campaigns.create', payload);
        }
        m.close();
        emit('campaign:created', campaign);
        if (start) {
          workerPump.kick(1);
          notify.success('Campaign started', `“${campaign.name}” is collecting leads.`);
        } else {
          notify.success('Draft saved', `“${campaign.name}” is ready to start whenever you are.`);
        }
        navigate?.(`campaigns/${campaign.id}`);
      } catch (e) {
        if (e.fields && Object.keys(e.fields).length) {
          const firstStep = Math.min(...Object.keys(e.fields).map((f) => FIELD_STEP[f] ?? 3));
          if (firstStep !== s.step) go(firstStep);
          setTimeout(() => showStepError(e.fields), 60);
        } else {
          showError(e, start ? 'Could not start the campaign' : 'Could not save the campaign');
        }
      }
    });
  }

  render();
}
