# GlobeNest Atlas

GlobeNest Atlas is a Laravel financial-planning workspace for international students and other cross-border users. It brings a personal budget, manual activity, tuition targets, savings goals, currency estimates, scenario exploration and an AI planning assistant into one account-scoped application.

Atlas is a planning prototype, not a bank, remittance provider or regulated financial adviser. It does not connect to bank accounts or move money.

## What works

- Account registration, throttled sign-in, logout, private sessions and a student profile.
- Registration email verification and one-time email-code password recovery (requires a working SMTP configuration).
- A database-backed dashboard for the user’s own budget, activity, tuition dates, savings goals and monthly category budgets.
- Tuition savings pace and shortfall calculations, using integer minor units.
- A Student Financial Flight Plan that reuses the dashboard data and deterministic forecast engine to show tuition pace, goals, category-budget context, applicable stress cases, missing inputs and grounded next steps. It uses the earliest eligible outstanding tuition plan; it does not count user-entered awards as available funding.
- Dashboard warnings for approaching or overdue tuition, high monthly spending, and category-budget overruns, derived from user-entered records when the page is opened (no scheduled monitoring).
- A currency calculator with a disclosed, fixed demonstration-rate table.
- Remittance simulations ranked by estimated recipient amount after fees; the fee profiles are not providers or quotes.
- A scenario lab with favorable, baseline and adverse forecasts for rent changes, delayed income, scholarship timing, tuition savings and FX stress.
- An opt-in OpenRouter or Groq Chat Completions integration with a finite server-side tool registry, bounded tool rounds and batches, duplicate-call suppression, per-user record scoping and expiring, single-use confirmation for planning writes.
- A user-entered scholarship/funding tracker for potential value, deadlines, source links, and application status. It neither searches nor verifies opportunities; OpenRouter can summarize only the signed-in user’s entries.
- Persistent conversation history. Recent messages are sent to the configured AI provider only when the user enables memory consent.
- An in-workspace Help & FAQ section with a first-time guide, feature examples, currency guidance, and limitations.
- A trust center to review and confirm or discard pending changes, including after refreshing the workspace; a redacted agent audit trail.
- Persistent light/dark theme selection.
- Manual activity records clearly labeled as simulated or manually entered. Atlas never presents them as bank-confirmed.

## Data sources and limitations

| Capability | Current behavior |
| --- | --- |
| Exchange rates | Fixed demonstration values in `ExchangeRateService`; not live and may be stale or inaccurate. |
| Remittance options | Simulated fee profiles and indicative delivery estimates; no named provider, offer or guarantee. |
| Financial activity | User-entered records only; no bank import or payment execution. |
| Early warnings | Derived from recorded tuition deadlines and spending when the dashboard loads; no background monitoring or notifications. |
| Remittance ranking | Three simulated fee profiles ordered by estimated recipient amount; no verified routes or provider ranking. |
| Scholarships/funding | User-entered opportunities only; no scholarship search, eligibility verification, or award guarantee. |
| Scenario lab | Deterministic favorable/baseline/adverse tuition forecasts. Rent is a component of spending; income is included in the monthly budget; delayed income is unavailable for the first N months; an expected scholarship is counted only if its selected month falls within the forecast; FX stress applies symmetrically to the tuition conversion. Scholarship awards are unconfirmed. |
| AI | Real OpenRouter (default) or opt-in Groq integration when configured. The API key and model are server-side only. |
| Calculations | Deterministic application code; the AI provider does not calculate or query the database directly. |

This release does not include a live rate provider, verified remittance-provider API, bank connectivity, scholarship search or eligibility verification, authenticated password-change flow, authenticator-app MFA, or production operational monitoring. Registration email verification and password recovery by email OTP are implemented in source; their real delivery depends on a correctly configured SMTP service and has not been verified live. Review `SECURITY.md` before deployment.

AI connectivity depends on a server-side API key, model availability, account status, and tool-calling support. OpenRouter is the default. `openrouter/free` and `openrouter/auto` are deliberately rejected because they can route to different models. Run `php artisan openrouter:test` or `php artisan groq:test` for the selected provider to request one minimal synthetic function call; these checks validate the function name and token without executing an application tool, printing the API key, or showing generated content. Automated tests fake providers and do not require paid API calls. A successful mocked test is not proof of live connectivity or free quota.

## Stack

- PHP 8.3+ and Laravel 13
- MySQL 8+ recommended for deployment; SQLite is used by the automated test suite
- Blade, vanilla JavaScript and custom responsive CSS, built with Vite
- OpenRouter (default) and Groq Chat Completions REST APIs, optional and server-configured (`openai/gpt-4o-mini` and `openai/gpt-oss-120b` example models)

## Local setup

### Windows PowerShell fresh clone

Install these tools separately on each computer; they are not bundled in this repository:

- PHP 8.3 or newer with the extensions required by Laravel 13 and this project's Composer dependencies. Make sure `php.exe` is on `PATH`.
- Composer 2, configured to use that PHP installation. Make sure `composer` is on `PATH`.
- Node.js 20.19+ or 22.12+ with npm, as required by Vite 8. Make sure `node` and `npm` are on `PATH`.
- MySQL 8+ for the recommended local setup (a MySQL Server installation and a client such as MySQL Workbench or `mysql.exe`).
- Git for Windows.

The repository contains the application source, dependency lock files, `.env.example`, and the public CA bundle at `certs/cacert.pem`. Composer packages, npm packages, PHP, Node.js, MySQL, generated Vite assets, and your local `.env` are installed or created locally. Never copy another developer's `.env` or credentials.

1. Clone the repository and enter the project directory. Replace the example URL if your teammate uses a different GitHub remote:

   ```powershell
   git clone https://github.com/0Aaheed0/GlobeNest-Atlas.git
   Set-Location .\GlobeNest-Atlas
   ```

2. Create a local environment file only if one does not already exist. This avoids overwriting local configuration. Then install the locked dependencies and create a unique local application key:

   ```powershell
   if (-not (Test-Path .env)) { Copy-Item .env.example .env }
   composer install
   npm ci
   php artisan key:generate
   ```

   `npm ci` installs the versions in `package-lock.json` (use `npm install` only when intentionally updating that lock file). Edit `.env` in a text editor and set your own local `APP_URL`, MySQL connection values, and mail settings. Keep `APP_ENV=local`, `APP_DEBUG=true`, and `SESSION_ENCRYPT=true` for local development. Do not share `.env` or commit it.

3. Create a new, empty local MySQL database and a dedicated local database user using MySQL Workbench or your MySQL client. For example, after connecting to your local MySQL server, run these statements, replacing the example password with a unique local password:

   ```sql
   CREATE DATABASE globenest_atlas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'globenest_local'@'localhost' IDENTIFIED BY 'choose-a-unique-local-password';
   GRANT ALL PRIVILEGES ON globenest_atlas.* TO 'globenest_local'@'localhost';
   ```

   Set `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=globenest_atlas`, `DB_USERNAME=globenest_local`, and the chosen `DB_PASSWORD` in your `.env`. Confirm the database is the new local database before continuing.

4. Apply the project's existing migrations to that local database and build the frontend assets:

   ```powershell
   php artisan migrate
   npm run build
   ```

   This is the normal non-forced local migration command. Do not run `migrate:fresh` for setup; it drops existing tables. The `composer run setup` helper currently includes a forced migration, so follow these explicit steps instead.

5. Start the application:

   ```powershell
   php artisan serve
   ```

   Open the local URL printed by Artisan, register an account, and complete the profile setup. Run `npm run dev` in a second PowerShell window only if you want Vite's live-reload development server; the production-style local build is already available after `npm run build`.

The test suite uses the isolated database configured by `phpunit.xml`; it does not use your development MySQL database when run with the repository's test configuration. Do not change the test database to your personal development database.

### Optional integrations

The app can run without external AI or Google credentials. To enable AI, create your own provider account and put its key only in the ignored local `.env`: set `AI_PROVIDER=openrouter` with `OPENROUTER_API_KEY`, or set `AI_PROVIDER=groq` with `GROQ_API_KEY`; use a model available to that account. Google sign-in requires an OAuth client you own: set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` and register `http://127.0.0.1:8000/auth/google/callback` as an authorized redirect URI (update both Google and `GOOGLE_REDIRECT_URI` if using a different local URL). Registration verification and password-recovery codes require a reachable SMTP service configured in `MAIL_*`; the sample `127.0.0.1:2525` address is only a local development placeholder, not a bundled mail server. Never paste credentials into source, documentation, chat, or `VITE_*` variables. If any credential was exposed, revoke it and issue a replacement before use.

To verify email delivery safely, use an SMTP sandbox or dedicated fictional test mailbox and a test account. Enter that provider's SMTP host, port, encryption mode, username, password, and authorized sender address in your ignored `.env`; submit one registration or recovery request for the fictional address. Confirm one message arrives, verify its sender and expiry instructions, and use the code once without recording it in screenshots, logs, or chat. Check both Inbox and Spam/Junk. If it is missing, inspect the SMTP provider's delivery/rejection metadata without message contents, then check outbound port access, credentials, sender authorization, and the provider's domain-authentication requirements before one controlled retry. The application refuses to send OTP mail through Laravel's `log` mailer or a configured failover chain that includes a log transport, because those transports can write message bodies to application logs. Mail fakes test application behavior; they do not verify real delivery.

### Troubleshooting

- `php`, `composer`, `node`, or `npm` is not recognized: install the corresponding tool, add its executable to `PATH`, and open a new PowerShell window.
- Composer reports a missing PHP extension or incompatible PHP version: enable/install the required extension for the PHP executable Composer is using, then rerun `composer install`.
- MySQL connection refused: start the local MySQL server and verify host, port, database, username, and password in `.env`.
- MySQL access denied or unknown database: create the database and dedicated user first, then check the matching `.env` values and grants.
- Laravel reports a missing application key: create `.env` from `.env.example` and run `php artisan key:generate`.
- Vite reports a missing manifest: run `npm ci` and `npm run build` from the project root.
- Google rejects the callback: make the Google OAuth redirect URI exactly match `GOOGLE_REDIRECT_URI` and the local URL being used.
- AI or SMTP features are unavailable: check the relevant provider credentials, model, account access, and mail settings; these integrations are optional for basic startup.
- TLS verification fails: check that the tracked `certs/cacert.pem` exists and your computer's clock is correct. Keep certificate verification enabled; do not work around the issue by disabling TLS verification.

### Configure OpenRouter (default)

Set `AI_PROVIDER=openrouter` (also the default), then set `OPENROUTER_API_KEY` and an OpenRouter model ID available to your account. Never put credentials in a `VITE_*` variable, source file, or committed `.env`; keep them in a host secret manager or ignored local `.env`. Laravel reads them server-side, and they are not sent to the browser. Use `php artisan openrouter:test` for a minimal live connection check. Provider account limits can block free models; changing models does not guarantee account access or free quota.

OpenRouter HTTPS requests verify TLS certificates against the portable Mozilla CA bundle at `certs/cacert.pem`, resolved from the Laravel project root with `base_path()`. TLS verification remains enabled; the client does not use an operating-system-specific or developer-home certificate path. The bundle is public and allowed by `.gitignore`, so it can travel with the repository without exposing credentials. To refresh it, download the current PEM bundle from the official source at [curl.se/ca/cacert.pem](https://curl.se/ca/cacert.pem) and replace `certs/cacert.pem`; verify the download came from that HTTPS URL before committing. Do not store API keys, passwords, or private certificates in `certs/`.

### Enable Groq (optional)

To opt into Groq, set `AI_PROVIDER=groq`, store `GROQ_API_KEY` server-side, and choose a model available to the Groq account in `GROQ_MODEL` (the example is `openai/gpt-oss-120b`). `GROQ_TIMEOUT` defaults to 25 seconds. Groq’s OpenAI-compatible tool-calling protocol preserves Atlas’s server-side tool registry and approval flow; TLS verification stays enabled using the project CA bundle. Run `php artisan groq:test` for a minimal synthetic tool call. Groq model access, rate limits, and any free quota are account-specific and are not guaranteed. Check the account’s plan and quota before enabling it; the adapter does not enable billing or retry requests automatically.

## Quality checks

```powershell
php artisan test --compact tests/Feature/AgentToolTest.php
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm.cmd run build
php artisan migrate:status
composer audit --locked
npm.cmd audit --audit-level=moderate
```

PHP-based checks require PHP and Composer on `PATH`. The test suite is configured for its isolated in-memory SQLite database. Never point automated tests at production data. Dependency audit commands require registry access; retain and review their output instead of treating a network failure as a clean audit.

## Deployment

Deploy the Laravel application to a PHP-capable host with managed MySQL; a static-only Vercel or Netlify deployment cannot run the backend. Use PHP 8.3+, Composer’s production dependencies, and a built Vite bundle.

- Set `APP_ENV=production`, `APP_DEBUG=false`, a unique `APP_KEY`, HTTPS `APP_URL`, and `SESSION_SECURE_COOKIE=true`. The application forces Secure session cookies in production even if the variable is accidentally set to false; still set it explicitly and reject or redirect HTTP at the deployment edge.
- Store database credentials and the selected AI-provider key in the host’s secret manager. Do not deploy development secrets.
- Provision MySQL, run `php artisan migrate --force`, and configure durable session/cache storage. `SESSION_ENCRYPT=true` is recommended.
- Run `npm ci` and `npm run build` in the deployment build stage. Set correct ownership for Laravel’s `storage` and `bootstrap/cache` directories.
- Enable HTTPS, redirect HTTP, use a restrictive host/network policy, and keep PHP/Laravel/dependencies patched.
- No queue worker, file upload, or public storage is required by the current features. If queues are added later, configure a persistent queue and worker.
- Use `php artisan config:cache` and `php artisan view:cache` as appropriate for the host. Do not cache configuration before production environment values are present.
