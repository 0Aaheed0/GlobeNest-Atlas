# Security self-assessment

This is a source-level assessment of the repository, not a penetration test, security certification, privacy review or production-environment inspection. Source presence, automated tests and deployment verification are stated separately. Reported results are available for the tested revision `c44a0e2` (an ancestor of the current `main`); they are listed under Automated evidence below, they are labelled as applying to that tested revision, and they are not a claim about any later revision.

## Implemented controls (source review)

- Authenticated financial routes use Laravel's `web` middleware. State-changing requests retain CSRF protection; sensitive authentication, finance and agent routes are throttled.
- Registration uses hashed, expiring email OTPs. Password recovery uses the existing reset-token table, hashed single-use codes, generic account-enumeration-resistant responses, throttling and the existing password policy. OTP delivery rejects log mailers and failover chains containing a log transport; failure logs contain an exception class, not message content.
- Login regenerates the session; logout invalidates it and rotates the CSRF token. Recovery rotates the remember token and deletes the user's stored sessions only when `SESSION_DRIVER=database`.
- Finance, conversation and pending-action reads resolve through the authenticated user's relationships. The agent dispatches only the fixed registry, rejects unexpected argument keys, validates input on the server and bounds tool rounds and call counts.
- Create/update/delete of tuition plans, savings goals and category budgets, plus saved planning notes, use an explicit per-action proposal. Existing-record updates and deletions retain an owner-scoped before-state snapshot. Confirmation revalidates and checks the current snapshot before writing. Deletes require an extra browser confirmation. Cancellation, expiry, replay and stale-state paths do not authorize a write. A stale/invalid proposal is recorded as failed while the record write rolls back.
- Financial arithmetic is server-side deterministic code using integer minor units. Supported currencies are explicit; unlike currencies are not added together without conversion. Rates and remittance fees are static demonstrations. Funding is user-entered and unverified.
- The UI/history receives allowlisted tool summaries and receipts rather than raw tool JSON. User text is rendered with escaped Blade output or DOM text nodes. Conversation history is sent to the selected AI provider only with memory consent; the current message and tool results are sent for a configured AI request.
- AI credentials are read from server configuration. HTTP clients use HTTPS, TLS verification and bounded timeouts; they do not automatically retry. Provider-facing errors are mapped to safe messages, and diagnostic logging is designed to omit prompts, tool arguments, authorization headers and raw response bodies.
- Session configuration encrypts session data by default, sets `HttpOnly` and `SameSite=Lax`, and forces `Secure` in production. `.env` is ignored; never place actual secrets in source control or `VITE_*` variables.

## Automated evidence and current verification

Existing tests cover selected authentication, account isolation, financial calculations, provider failure handling, data redaction, tool validation, approval/cancellation/expiry/replay and stale-state behavior. A regression test covers a stale approval being marked failed and displayed as a no-change outcome.

**Reported results for the tested revision `c44a0e2`** (an ancestor of the current `main`; also recorded in `docs/TECHNICAL-DOCUMENTATION.md`):

- `php artisan test --compact tests/Feature/AgentToolTest.php`: **90 passed (811 assertions)**.
- `php artisan test --compact`: **161 passed (1,343 assertions)**.
- `vendor/bin/pint --dirty --format agent`: **passed**.
- `npm.cmd run build`: **passed**.
- `git diff --check`: **passed** (no trailing whitespace or conflict markers).

These results apply to the tested revision `c44a0e2` only. The earlier statement that the suite could not be run because `php` and `composer` were unavailable on `PATH` described an earlier audit environment and is superseded for that revision by the results above. The PHPUnit runs use the repository test configuration (in-memory SQLite with array session/cache/mail), so they verify application logic and account isolation, not live integrations or production behavior.

Dependency-audit status remains unverified: no `composer audit` or `npm audit` result for this revision is available here, and a failed registry request must not be counted as dependency clearance.

## Partial verification and accepted limitations

- Automated HTTP tests run with in-memory SQLite and do not establish MySQL row-lock behavior or concurrent approval safety. Test-level CSRF rejection is not observed because Laravel disables CSRF middleware in its normal PHPUnit environment.
- Source configuration is not proof of deployed cookie behavior, HTTPS termination, proxy trust, CORS, headers, secret-manager use, least-privilege database grants, backups, monitoring, or incident response.
- Registration and recovery require a working SMTP provider and verified sender. The `.env.example` loopback address is a placeholder. SMTP delivery, real Google OAuth and current OpenRouter/Groq access are unverified here.
- The latest historical OpenRouter note dated 2026-10-16 records an HTTP 429 that prevented a successful tool-call smoke test. No current live provider success is claimed. No paid credits were added according to that report.
- Recovery revokes existing sessions only with the database session driver. The application has no authenticated password-change flow or login MFA/TOTP. It does not implement role-based permission tiers.
- No real-browser responsive/accessibility review, independent penetration test, prompt-injection red-team, financial-model validation, or production audit has been completed in this phase.

## Deployment responsibilities

- Use HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, a unique `APP_KEY`, `SESSION_SECURE_COOKIE=true`, and proxy settings matching the host. Confirm the actual `Set-Cookie` header and browser behavior.
- Inject database and selected-provider credentials from a protected secret manager. Rotate any credential if exposed. Review provider retention, terms and lawful basis before transmitting user messages or tool results.
- Use a least-privilege database account, restrict network access, encrypt and test backups, and document recovery procedures. Apply migrations only to the intended deployment database after review.
- Configure security headers, CORS, edge rate limits, redacted centralized logs, monitoring, dependency updates and incident response at the hosting layer.
- Keep demo records fictional. Never submit passwords, OTPs, API keys, payment credentials, session tokens or identity documents to chat or presentation evidence.

Atlas does not connect to banks, execute transfers, verify funding awards, provide live exchange rates, or monitor accounts continuously. Estimates are not guarantees or regulated financial advice.
