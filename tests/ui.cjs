/*
 * Browser test (Playwright): drives the real UI end to end and saves
 * screenshots. Expects the app on :8088 set up by tests/setup-ui.php and the
 * mock internet on :8099.
 *
 *   NODE_PATH=$(npm root -g) node tests/ui.cjs [outDir]
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.BASE || 'http://127.0.0.1:8088';
const OUT = process.argv[2] || path.join(__dirname, 'output');
fs.mkdirSync(OUT, { recursive: true });

const problems = [];
let passed = 0;
function check(name, ok, detail = '') {
  if (ok) {
    passed++;
    console.log(`  \u001b[32m✓\u001b[0m ${name}`);
  } else {
    problems.push(`${name}${detail ? ' — ' + detail : ''}`);
    console.log(`  \u001b[31m✗ ${name}\u001b[0m ${detail}`);
  }
}

(async () => {
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true });
  const page = await context.newPage();
  const consoleErrors = [];
  page.on('console', (m) => {
    // "Failed to load resource" lines mirror API validation responses (e.g. a wrong
    // password); those are asserted separately through failedApi below.
    if (m.type() === 'error' && !/fonts\.g|flagcdn|ERR_TUNNEL|ERR_CONNECTION|net::ERR|Failed to load resource/i.test(m.text())) consoleErrors.push(m.text());
  });
  page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));
  // Count real document loads only (hash routing doesn't reload the page).
  let navigations = 0;
  page.on('load', () => { navigations++; });
  const failedApi = [];
  page.on('response', (r) => {
    if (r.status() >= 400 && r.url().includes('api.php')) failedApi.push(`${r.status()} ${r.url().replace(/^.*r=/, '')}`);
  });
  const shot = (name) => page.screenshot({ path: path.join(OUT, `${name}.png`) });

  console.log('\nLogin');
  await page.goto(BASE + '/');
  await page.waitForSelector('.auth-card form');
  await page.waitForTimeout(700);
  await shot('01-login');
  await page.fill('input[name=email]', 'dawood@example.test');
  await page.fill('input[name=password]', 'wrong-password');
  await page.click('form button[type=submit]');
  await page.waitForSelector('.field.invalid, .form-error .alert');
  check('wrong password shows inline error', await page.isVisible('.field.invalid .error-text'));
  await page.fill('input[name=password]', 'demo-password');
  await page.click('form button[type=submit]');
  await page.waitForSelector('.sidebar');
  await page.waitForSelector('.hero');
  await page.waitForTimeout(900);
  const navAfterLogin = navigations;
  check('dashboard with welcome hero for new user', await page.isVisible('.hero'));
  await shot('02-dashboard-empty');

  console.log('\nCampaign wizard');
  await page.click('text=New campaign');
  await page.waitForSelector('.wizard');
  await page.waitForTimeout(400);
  await page.click('.wizard-footer >> text=Continue');
  check('step 1 requires a country', await page.isVisible('.field.invalid'));
  await page.click('.country-card:has-text("Australia")');
  await page.waitForSelector('.source-card');
  await shot('03-wizard-country');
  await page.click('.wizard-footer >> text=Continue');
  await page.waitForSelector('.tags-input');
  const kw = page.locator('.tags-input input').nth(0);
  await kw.fill('Restaurants');
  await kw.press('Enter');
  const loc = page.locator('.tags-input input').nth(1);
  await loc.fill('Brisb');
  await page.waitForSelector('.suggest button');
  check('location autocomplete suggests Brisbane QLD', (await page.textContent('.suggest')).includes('Brisbane QLD'));
  await loc.press('Enter');
  await page.waitForTimeout(250);
  check('two tags added', (await page.locator('.tag').count()) === 2);
  await shot('04-wizard-search');
  await page.click('.wizard-footer >> text=Continue');
  await page.waitForSelector('.cond-box');
  for (const label of ['Business email', 'Phone number', 'Website']) {
    await page.click(`.cond-box .option-card:has-text("${label}")`);
  }
  await page.waitForTimeout(250);
  check('website collection locked when emails wanted', await page.locator('.option-grid').first().locator('.option-card:has-text("Website link") input').isDisabled());
  await shot('05-wizard-conditions');
  await page.click('.wizard-footer >> text=Continue');
  await page.waitForSelector('.summary-list');
  await page.click('.chips >> text=Unlimited');
  await shot('06-wizard-review');
  await page.click('.wizard-footer >> text=Start campaign');
  await page.waitForSelector('.detail-head');
  check('lands on campaign detail', page.url().includes('#/campaigns/'));

  console.log('\nLive campaign');
  await page.waitForTimeout(2500);
  await shot('07-campaign-running');
  await page.waitForFunction(() => document.querySelector('.detail-head .badge')?.textContent.includes('Completed'), null, { timeout: 180000 });
  await page.waitForTimeout(3000);
  const qualified = await page.textContent('.mini-stat strong');
  check('campaign completed with qualified leads', Number(qualified.replace(/\D/g, '')) > 0, qualified);
  await page.waitForSelector('.table tbody tr');
  check('leads table populated', (await page.locator('.table tbody tr').count()) > 0);
  await shot('08-campaign-completed');

  await page.click('.tab:has-text("Activity")');
  await page.waitForSelector('.log-line');
  await page.waitForTimeout(500);
  check('activity log shows lines', (await page.locator('.log-line').count()) > 5);
  await shot('09-campaign-activity');
  await page.click('.tab:has-text("Rejections")');
  await page.waitForTimeout(600);
  await shot('10-campaign-rejections');
  await page.click('.tab:has-text("Leads")');
  await page.waitForSelector('.table tbody tr');

  console.log('\nLead drawer & export');
  await page.locator('.table tbody tr').first().locator('.cell-main').click();
  await page.waitForSelector('.drawer .contact-tile');
  await page.waitForTimeout(600);
  check('drawer shows contact tiles', (await page.locator('.drawer .contact-tile').count()) === 3);
  await shot('11-lead-drawer');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);
  check('Esc closes drawer', !(await page.isVisible('.drawer')));

  await page.locator('.table tbody tr').first().locator('input[type=checkbox]').check();
  await page.locator('.table tbody tr').nth(1).locator('input[type=checkbox]').check();
  await page.waitForTimeout(500);
  check('bulk bar appears', await page.isVisible('.bulkbar.show'));
  await shot('12-bulk-select');
  await page.click('.bulkbar >> text=Export');
  await page.waitForSelector('.modal:has-text("Export leads")');
  await shot('13-export-modal');
  const [download] = await Promise.all([page.waitForEvent('download'), page.click('.modal >> text=Download CSV')]);
  const csvPath = path.join(OUT, 'export.csv');
  await download.saveAs(csvPath);
  const csv = fs.readFileSync(csvPath, 'utf8');
  check('CSV downloaded with 2 selected rows', csv.trim().split('\n').length === 3, `${csv.split('\n').length} lines`);
  await page.click('.bulkbar button[title="Clear selection"]');

  console.log('\nOther pages');
  await page.click('.nav-item:has-text("Leads")');
  await page.waitForSelector('.table tbody tr');
  await page.waitForTimeout(700);
  await shot('14-leads');
  await page.click('.chip:has-text("Has socials")');
  await page.waitForTimeout(800);
  check('socials filter keeps rows with socials', (await page.locator('.table tbody tr').count()) > 0);

  await page.click('.nav-item:has-text("Campaigns")');
  await page.waitForSelector('.campaign-row');
  await page.waitForTimeout(600);
  await shot('15-campaigns');

  await page.click('.nav-item:has-text("Sources")');
  await page.waitForSelector('.source-panel');
  await page.waitForTimeout(600);
  await shot('16-sources');
  await page.locator('.source-panel').first().locator('text=Run live test').click();
  await page.waitForSelector('.modal:has-text("It works")', { timeout: 30000 });
  await page.waitForTimeout(500);
  check('source live test succeeds', await page.isVisible('.modal .table'));
  await shot('17-source-test');
  await page.keyboard.press('Escape');

  for (const tab of ['connection', 'scraping', 'worker', 'account', 'system']) {
    await page.evaluate((t) => { window.location.hash = `#/settings/${t}`; }, tab);
    await page.waitForSelector('.tab-panel .card');
    await page.waitForTimeout(600);
    await shot(`18-settings-${tab}`);
  }

  console.log('\nDashboard, palette, theme, mobile');
  await page.click('.nav-item:has-text("Dashboard")');
  await page.waitForSelector('.chart svg');
  await page.waitForTimeout(1500);
  await page.hover('.chart svg', { position: { x: 500, y: 120 } });
  await page.waitForTimeout(300);
  check('chart tooltip on hover', await page.isVisible('.chart.hovering .chart-tip'));
  await shot('19-dashboard');
  await page.keyboard.press('Control+k');
  await page.waitForSelector('.palette');
  await page.keyboard.type('camp');
  await page.waitForTimeout(600);
  await shot('20-palette');
  await page.keyboard.press('Escape');
  await page.click('.sidebar [aria-label="Dark theme"]');
  await page.waitForTimeout(700);
  check('dark theme applied', (await page.getAttribute('html', 'data-theme')) === 'dark');
  await shot('21-dashboard-dark');
  await page.evaluate(() => { window.location.hash = '#/campaigns/1'; });
  await page.waitForSelector('.detail-head');
  await page.waitForTimeout(1200);
  await shot('22-campaign-dark');
  await page.evaluate(() => { window.location.hash = '#/leads'; });
  await page.waitForSelector('.table tbody tr');
  await page.waitForTimeout(700);
  await shot('23-leads-dark');
  await page.click('text=New campaign');
  await page.waitForSelector('.wizard');
  await page.click('.country-card:has-text("United States")');
  await page.waitForTimeout(500);
  await shot('24-wizard-dark');
  await page.keyboard.press('Escape');

  check('no full page reloads after login (pure SPA)', navigations === navAfterLogin, `${navigations - navAfterLogin} extra navigations`);

  const mobile = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, storageState: await context.storageState() });
  const m = await mobile.newPage();
  m.on('pageerror', (e) => consoleErrors.push('mobile pageerror: ' + e.message));
  await m.goto(BASE + '/#/dashboard');
  await m.waitForSelector('.stat');
  await m.waitForTimeout(1200);
  await m.screenshot({ path: path.join(OUT, '25-mobile-dashboard.png') });
  const overflow = await m.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check('no horizontal overflow on mobile', overflow <= 1, `${overflow}px`);
  await m.click('.menu-btn');
  await m.waitForTimeout(500);
  await m.screenshot({ path: path.join(OUT, '26-mobile-nav.png') });
  await m.click('.nav-item:has-text("Leads")');
  await m.waitForSelector('.table');
  await m.waitForTimeout(800);
  await m.screenshot({ path: path.join(OUT, '27-mobile-leads.png') });

  check('no JavaScript errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
  const unexpected = failedApi.filter((x) => x !== '422 auth.login');
  check('only the deliberate wrong-password API call failed', unexpected.length === 0, failedApi.join(', '));

  await browser.close();
  console.log(`\n${problems.length ? `\u001b[31m${problems.length} failed\u001b[0m, ` : ''}\u001b[32m${passed} passed\u001b[0m`);
  process.exit(problems.length ? 1 : 0);
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
