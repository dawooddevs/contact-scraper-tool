# Scrap — business lead scraper

A lead-generation web app for cPanel shared hosting. You run campaigns such as
**“Restaurants in Brisbane QLD”**. Scrap searches the right business directory
for that country, visits each business’s website to find email addresses and
social profiles, and keeps only the leads that meet your conditions (for
example *must have a business email, a phone number and a website*). Results can
be exported to CSV.

| Country | Directory | What it provides |
|---|---|---|
| 🇦🇺 Australia | [yellowpages.com.au](https://www.yellowpages.com.au) | phone, email (often), website, address, rating |
| 🇺🇸 United States | [yelp.com](https://www.yelp.com) (website or official API) | phone, website, address, rating (emails come from the business websites) |

More countries and websites plug in as small source classes (see
[Adding a country or website](#adding-a-country-or-website)).

## Highlights

- **Works like an app.** It's a single-page interface: every screen, dialog and
  action runs over AJAX with no page reloads. It has animated transitions, live
  progress, a command palette (`Ctrl/⌘ K`), keyboard shortcuts, light and dark
  themes, and a mobile layout.
- **Campaign wizard.** Pick the country and source, then categories × locations
  (several of each are allowed). Then choose which fields to collect (phone, email,
  website, address, socials, rating) and the lead conditions. Conditions can be
  *all of* or *any of* the fields, plus minimum rating and reviews. Finally set a
  target number of leads and a page limit.
- **Email discovery.** Scrap crawls each business's home page and its best
  contact/about pages. It finds emails in `mailto:` links, plain text,
  Cloudflare-protected addresses, `name [at] domain [dot] com` obfuscation and
  JSON-LD. It filters out junk such as `logo@2x.png` and placeholders, and ranks
  addresses on the business's own domain first.
- **Reliable on shared hosting.** Work is stored in a MySQL queue and processed
  in short, time-boxed batches. Batches run from a cron job or from the open
  browser tab, and a database lock ensures only one worker runs at a time.
  Crashed batches are retried automatically. Retries use backoff.
- **Handles bot protection.** Scrap recognises Cloudflare, DataDome, Akamai,
  Imperva, PerimeterX and reCAPTCHA pages. If a site keeps blocking, it pauses
  the campaign and tells you what to change.
- **Connection options.** You can connect directly, through your own
  HTTP/SOCKS proxies, or through ScraperAPI, ScrapingBee, ZenRows, Scrape.do or
  any other API via a URL template. The connection can be set globally or per
  source, and there are built-in live tests.
- **Duplicate handling.** Duplicates are merged across searches within a
  campaign. You can optionally skip businesses already collected by earlier
  campaigns (same listing or phone number).
- **Security.** Includes a one-time web installer, bcrypt passwords, "remember
  me" tokens, CSRF protection, login throttling and a strict CSP. Private folders
  are blocked by `.htaccess`, and the website crawler has SSRF protection.

---

## Install on Spaceship (cPanel)

**Requirements:** PHP 8.1 or newer (8.2 or 8.3 recommended) with `pdo_mysql`,
`curl`, `mbstring` and `dom`, plus MySQL 5.7+ or MariaDB 10.3+. Every standard
cPanel host has these.

### 1. Create the subdomain

In cPanel open **Domains**, then create `scrap.dawood.top`. Set its **document
root** to the `public` folder, e.g. `/home/CPANELUSER/scrap.dawood.top/public`.

> If you keep the default document root (`…/scrap.dawood.top`), it still works:
> the root `.htaccess` serves `public/` and blocks the private folders. Pointing
> the root at `public/` is simply the cleanest setup.

### 2. Upload the code

Choose **one** of these options:

- **Git (easiest to update):** In cPanel open **Git™ Version Control**, then
  **Create**. Clone `https://github.com/dawooddevs/contact-scraper-tool.git`
  into `/home/CPANELUSER/scrap.dawood.top`. To update later, click **Update
  from Remote**.
- **ZIP:** Download the repository as a ZIP. In **File Manager**, upload it to
  `/home/CPANELUSER/scrap.dawood.top` and **Extract** it there, so that `app/`,
  `public/`, `storage/`, … sit directly inside that folder.

### 3. Choose the PHP version

Open **MultiPHP Manager** and select PHP **8.2** or **8.3** for
`scrap.dawood.top`. If your cPanel has **Select PHP Version**, make sure
`pdo_mysql`, `curl`, `mbstring` and `dom` are ticked.

### 4. Create a database

1. Open **MySQL® Databases** and create a database, e.g. `cpaneluser_scrap`.
2. Create a user with a strong password.
3. Under **Add User To Database**, add the user with **ALL PRIVILEGES**.

### 5. Run the installer

Visit **https://scrap.dawood.top/install.php**. It checks the server, tests
your database details (host is `localhost`), creates the tables and your admin
account. Once finished, the installer disables itself.

> Enable HTTPS first if needed. Go to **SSL/TLS Status** and run **AutoSSL**
> for the subdomain.

### 6. Add the cron job (recommended)

Open **Cron Jobs**, choose **Once Per Minute (`* * * * *`)**, and use this
command. The exact path is shown in **Settings → Worker & cron** inside the app.

```
/usr/local/bin/php /home/CPANELUSER/scrap.dawood.top/bin/cron.php >/dev/null 2>&1
```

Without cron, campaigns only progress while the app is open in a browser tab.
With cron they keep running in the background. If your host can only call URLs,
Settings shows a secret `cron.php?key=…` URL you can use with cron-job.org or a
similar service.

### 7. Set up the connection (important)

Yellow Pages and Yelp both use bot protection, and they often block requests
from shared-hosting data-centre IPs. Here's how to check and fix it:

1. Open **Sources** and click **Run live test** for each source.
2. If the result is **Blocked**, open **Settings → Connection** and pick a
   scraping API such as ScraperAPI or ScrapingBee (both have free trial credits).
   Paste your API key, then use **Test Yellow Pages** or **Test Yelp**.
3. For **Yelp** you will usually need **Premium / residential proxies**.
   Another option is the official **Yelp Places API**: add the key under
   **Sources → Yelp** and switch *Discovery method* to the API.

Business websites (for email discovery) are fetched directly by default. That's
free, and they rarely block.

---

## Using Scrap

1. **New campaign** (or press `N`).
2. **Country & source**: Australia → Yellow Pages, United States → Yelp.
3. **Search**: add categories (e.g. *Restaurants*, *Cafes*) and locations (e.g.
   *Brisbane QLD*, *Gold Coast QLD*). Every combination is searched.
4. **Data & conditions**: choose what to collect and what a lead **must**
   have. For example, set *Business email + Phone number + Website* with **All
   of these**.
5. **Limits & review**: set a target number of qualified leads (the campaign
   stops when it's reached) and the maximum pages per search, then **Start**.

The campaign page shows live progress, where every listing went (qualified,
rejected, duplicate), a live activity log and the leads table. Leads that fail
your conditions are kept as **Rejected**, with the reason, so you can review
them. You can turn this off in Settings.

**Leads** has search, filters (has email, phone, website or socials, starred,
campaign, status), sorting, bulk actions, a detail drawer with notes, and
**CSV export** with column selection. The CSV opens correctly in Excel and
Google Sheets.

### How emails are found

Yellow Pages listings often include a business email. For everything else,
Scrap visits the business website: the home page plus the best contact, about
and team pages (4 pages by default). It stops early once an email is found. In
practice 30–60% of small businesses publish an address. You can tune this in
**Settings → Scraping**.

---

## Adding a country or website

Sources are plug-ins. To add one, for example Yell.com for the UK:

1. Create `app/Sources/YellUk.php`. The easiest start is to copy
   `YellowPagesAu.php` and change:
   - `key()`: a unique id, e.g. `yell_uk`
   - `info()`: name, country, domain, what it provides, results per page, max
     pages and location hint
   - `searchRequest()`: builds the search URL for a keyword, location and page
   - `parseSearch()`: turns the page into `Listing` objects and says whether
     there's another page
   - optionally `needsDetail()`, `detailRequest()` and `parseDetail()`, if a
     business page has to be fetched for more details
2. Register it in `app/config/sources.php`:
   `'yell_uk' => App\Sources\YellUk::class,`
3. Add it to a country in `app/config/countries.php` (with location
   suggestions):

   ```php
   'GB' => ['name' => 'United Kingdom', 'sources' => ['yell_uk'], 'locations' => ['London', 'Manchester']],
   ```

The new country appears in the wizard and on the Sources page automatically.
Connection modes, block detection, email crawling, conditions, dedupe and export
all work without further changes. `AbstractSource` provides helpers for
tolerant JSON field picking, JSON-LD and "find the listing array" parsing.

---

## Troubleshooting

| Problem | Fix |
|---|---|
| Source test says **Blocked** / campaign **paused** | Configure a scraping API (Settings → Connection), enable premium proxies for Yelp, then **Resume** the campaign. |
| **Unrecognised page** | The site served a challenge page or changed its layout. Try JavaScript rendering on your scraping API. If it persists, the parser needs updating. |
| Few emails | Keep **Visit websites to find emails** on and raise *Pages per website*. Remember that some businesses simply don't publish an email. |
| Campaigns don't progress when the tab is closed | Add the cron job; **Settings → Worker & cron** shows whether cron is detected. |
| HTTP 500 / blank page | Check `storage/logs/app.log`, and your PHP version in MultiPHP Manager. |
| Forgot password | In cPanel **Terminal**: `php ~/scrap.dawood.top/bin/reset-password.php you@example.com "new-password"` |
| Reinstall | Delete `storage/config.php` and visit `/install.php` again. |

Updates are safe. `storage/config.php` is never overwritten by Git, and
database migrations run automatically on the next request.

---

## Project structure

```
app/            PHP application (not web-accessible)
  Api/          JSON API: Kernel (router) + controllers
  Core/         App, Database, Auth, Settings, Lock, Logger, Migrator
  Http/         Fetcher (cURL multi), Connection modes, BlockDetector
  Scraper/      Worker, Queue, LeadPipeline, Enricher, EmailExtractor, LeadFilter, CampaignService
  Sources/      Source plug-ins: YellowPagesAu, Yelp (+ AbstractSource, Listing, SourceRegistry)
  Support/      Html/JSON extraction, URL and phone helpers
  config/       countries.php, sources.php
bin/            cron.php (worker), reset-password.php
database/       numbered migrations
public/         web root: index.php (app shell), api.php, install.php, cron.php, assets/
storage/        config.php (created by installer), logs, cookies
tests/          unit + integration + browser tests, mock directory/website server
```

## Development & tests

```bash
php tests/unit.php                     # parsers, email extraction, helpers (no DB)
DB_NAME=scrap_test DB_USER=scrap DB_PASS=secret tests/run.sh   # + full end-to-end run (wipes that DB)
tests/ui.sh                            # Playwright browser test with screenshots
```

The integration and browser tests run real campaigns against a local **mock
internet** (`tests/mock/`). It simulates Yellow Pages and Yelp search results,
un-hydrated Yelp results, business pages, bot walls, and business websites that
hide emails in different ways. Every expected lead count is computed exactly.

## Responsible use

Directory terms of service may restrict automated access, and privacy/anti-spam
laws apply to how you use contact details. Examples are Australia's *Spam Act
2003* and the US *CAN-SPAM Act*. Use the delay settings and campaign limits,
collect only what you need, and follow the rules that apply to your outreach.
