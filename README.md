# GlobeNest Atlas

GlobeNest Atlas is a Laravel financial-planning workspace for international students and other cross-border users. It brings a personal budget, manual activity, tuition targets, savings goals, currency estimates, scenario exploration and an AI planning assistant into one account-scoped application.

Atlas is a planning prototype, not a bank, remittance provider or regulated financial adviser. It does not connect to bank accounts or move money.

## What works

- Account registration, throttled sign-in, logout, private sessions and a student profile.
- A database-backed dashboard for the user’s own budget, activity, tuition dates, savings goals and monthly category budgets.
- Tuition savings pace and shortfall calculations, using integer minor units.
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

This release does not include a live rate provider, verified remittance-provider API, bank connectivity, scholarship search or eligibility verification, email verification, password reset flow, or production operational monitoring. Review `docs/SECURITY.md` before deployment.

AI connectivity depends on a server-side API key, model availability, account status, and tool-calling support. OpenRouter is the default. `openrouter/free` and `openrouter/auto` are deliberately rejected because they can route to different models. Run `php artisan openrouter:test` or `php artisan groq:test` for the selected provider to request one minimal synthetic function call; these checks validate the function name and token without executing an application tool, printing the API key, or showing generated content. Automated tests fake providers and do not require paid API calls. A successful mocked test is not proof of live connectivity or free quota.

## Stack

- PHP 8.3+ and Laravel 13
- MySQL 8+ recommended for deployment; SQLite is used by the automated test suite
- Blade, vanilla JavaScript and custom responsive CSS, built with Vite
- OpenRouter (default) and Groq Chat Completions REST APIs, optional and server-configured (`openai/gpt-4o-mini` and `openai/gpt-oss-120b` example models)

## Local setup

1. Install PHP 8.3+, Composer, Node.js and npm. Copy `.env.example` to `.env`.
2. Install packages and generate the app key:

   ```powershell
   composer install
   npm ci
   php artisan key:generate
   ```

3. Configure a local MySQL database in `.env`, for example:

   ```dotenv
   APP_ENV=local
   APP_DEBUG=true
   APP_URL=http://localhost:8000
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=globenest_atlas
   DB_USERNAME=your_local_user
   DB_PASSWORD=your_local_password
   SESSION_DRIVER=database
   SESSION_ENCRYPT=true
   AI_PROVIDER=openrouter
   OPENROUTER_API_KEY=
   OPENROUTER_MODEL=openai/gpt-4o-mini
   OPENROUTER_TIMEOUT=25
   ```

4. Create the database, apply migrations, and build the assets:

   ```powershell
   php artisan migrate
   npm run build
   ```

5. Run the application:

   ```powershell
   php artisan serve
   ```

   Open the local URL printed by Artisan, register an account and complete the profile setup. For a SQLite-only local prototype, set `DB_CONNECTION=sqlite` and create `database/database.sqlite` before migrating.

### Configure OpenRouter (default)

Set `AI_PROVIDER=openrouter` (also the default), then set `OPENROUTER_API_KEY` and an OpenRouter model ID available to your account. Never put credentials in a `VITE_*` variable, source file, or committed `.env`; keep them in a host secret manager or ignored local `.env`. Laravel reads them server-side, and they are not sent to the browser. Use `php artisan openrouter:test` for a minimal live connection check. Provider account limits can block free models; changing models does not guarantee account access or free quota.

OpenRouter HTTPS requests verify TLS certificates against the portable Mozilla CA bundle at `certs/cacert.pem`, resolved from the Laravel project root with `base_path()`. TLS verification remains enabled; the client does not use an operating-system-specific or developer-home certificate path. The bundle is public and allowed by `.gitignore`, so it can travel with the repository without exposing credentials. To refresh it, download the current PEM bundle from the official source at [curl.se/ca/cacert.pem](https://curl.se/ca/cacert.pem) and replace `certs/cacert.pem`; verify the download came from that HTTPS URL before committing. Do not store API keys, passwords, or private certificates in `certs/`.

### Enable Groq (optional)

To opt into Groq, set `AI_PROVIDER=groq`, store `GROQ_API_KEY` server-side, and choose a model available to the Groq account in `GROQ_MODEL` (the example is `openai/gpt-oss-120b`). `GROQ_TIMEOUT` defaults to 25 seconds. Groq’s OpenAI-compatible tool-calling protocol preserves Atlas’s server-side tool registry and approval flow; TLS verification stays enabled using the project CA bundle. Run `php artisan groq:test` for a minimal synthetic tool call. Groq model access, rate limits, and any free quota are account-specific and are not guaranteed. Check the account’s plan and quota before enabling it; the adapter does not enable billing or retry requests automatically.

## Quality checks

```powershell
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run build
php artisan migrate:status
composer audit --locked
```

## Deployment

Deploy the Laravel application to a PHP-capable host with managed MySQL; a static-only Vercel or Netlify deployment cannot run the backend. Use PHP 8.3+, Composer’s production dependencies, and a built Vite bundle.

- Set `APP_ENV=production`, `APP_DEBUG=false`, a unique `APP_KEY`, HTTPS `APP_URL`, and `SESSION_SECURE_COOKIE=true`. The application forces Secure session cookies in production even if the variable is accidentally set to false; still set it explicitly and reject or redirect HTTP at the deployment edge.
- Store database credentials and the selected AI-provider key in the host’s secret manager. Do not deploy development secrets.
- Provision MySQL, run `php artisan migrate --force`, and configure durable session/cache storage. `SESSION_ENCRYPT=true` is recommended.
- Run `npm ci` and `npm run build` in the deployment build stage. Set correct ownership for Laravel’s `storage` and `bootstrap/cache` directories.
- Enable HTTPS, redirect HTTP, use a restrictive host/network policy, and keep PHP/Laravel/dependencies patched.
- No queue worker, file upload, or public storage is required by the current features. If queues are added later, configure a persistent queue and worker.
- Use `php artisan config:cache` and `php artisan view:cache` as appropriate for the host. Do not cache configuration before production environment values are present.

## Competition materials

- [System architecture and calculation methodology](docs/ARCHITECTURE.md)
- [Security self-assessment and residual risks](docs/SECURITY.md)
- [Under-10-minute demo and evidence checklist](docs/DEMO.md)
