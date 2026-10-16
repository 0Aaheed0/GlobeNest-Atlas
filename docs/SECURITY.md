# Security assessment

This is a repository-level assessment, not a penetration test, formal security review, or production-environment verification. Claims below are limited to the source and checks available in this worktree.

## Verified controls

- The browser application uses Laravel's session-authenticated `web` routes. Registration and login regenerate the session identifier, logout invalidates the session and rotates its CSRF token, login and registration are throttled, and stored passwords are hashed. Regression tests exercise registration/login/logout and session-ID changes.
- All state-changing application routes are in the `web` middleware group and therefore use Laravel's CSRF middleware. Laravel disables CSRF verification during its normal PHPUnit HTTP tests, so request-level CSRF rejection was not independently exercised.
- Finance writes use authenticated-user relationships. Tests attempt cross-account tuition and goal progress, funding-status changes, dashboard reads, conversation history, and approval/cancellation actions; foreign records are not returned or changed.
- The agent can dispatch only the fixed `AgentToolRegistry` tool names. Arguments are validated and unexpected keys rejected; model-generated identifiers are resolved through the current user's relations. Tool rounds, batch size and total calls are bounded.
- Planning writes are staged in owner- and conversation-bound pending records. The confirmation endpoint revalidates stored arguments, expires and single-uses proposals, and wraps the record write, audit event and proposal status in a transaction. Existing-record changes compare a locked current snapshot with the reviewed snapshot. Tests cover confirmation, cancellation, expiry, replay, stale updates and foreign approval attempts.
- Financial calculations are deterministic backend code using integer minor units. Different currencies are not combined as if they shared a unit. Conversion responses identify the static demonstration-rate source, request-time retrieval timestamp and `live=false`; remittance profiles are explicitly simulated.
- Chat and history responses use an allowlist display presenter rather than returning raw tool JSON. Full results remain server-side for tool processing and approval history. Tests cover response shaping and retained approval behavior.
- User-controlled values in dashboard Blade output are escaped, and chat text is inserted with DOM text nodes; a regression test renders script-shaped synthetic financial and conversation text and verifies it is not executable markup.
- The selected OpenRouter or Groq credential is read through server-side configuration. Requests use HTTPS, the project CA bundle and TLS verification, with bounded connection/overall timeouts. Provider errors are converted to user-safe messages; diagnostic logs omit prompts, tool arguments, authorization headers and raw response bodies. Requests are not automatically retried.
- `.env` is ignored and not tracked in the current index. No `.env` revisions were found in Git history. A scan of tracked files found no actual credential; the only token-shaped match is synthetic test data in the provider-redaction test, not a usable key.
- Current automated dependency checks found no Composer advisories and no npm vulnerabilities. These results are time-bound and do not guarantee future dependency safety.

## Partially verified controls

- Production session configuration forces the `Secure` cookie flag when `APP_ENV=production`; local development can opt in or out explicitly. The configuration is covered by a regression test, but the deployed `Set-Cookie` header, proxy behavior, HTTPS edge and browser enforcement have not been observed.
- Approval code uses database transactions and `lockForUpdate()`. The automated suite uses in-memory SQLite; it does not establish concurrent-request behavior or lock semantics on the target MySQL server.
- Owner-scoping and approval tests exercise the principal record classes and endpoints, but this repository check is not an exhaustive penetration test of every request permutation, timing race or database failure.
- Groq's adapter explicitly configures TLS verification with the project CA bundle, but that transport has only been exercised with mocked HTTP responses, not a live Groq TLS handshake. The latest live OpenRouter check returned HTTP 429 in 0.80 seconds; OpenRouter TLS and CA verification passed, and diagnostics classified the limit as an OpenRouter account/free-model daily limit. No tool-call inference succeeded, and no paid credits were added. No Groq live request was made, and no Groq account entitlement or free quota is verified.
- Input validation and financial edge cases have focused regression coverage, not exhaustive property-based testing or independent financial-model validation.

## Not implemented or not tested

- Email verification and password-recovery routes/flows are not implemented. The user model does not enable Laravel's `MustVerifyEmail`, and the example mailer uses the `log` driver. Before offering recovery or verification in production, select and configure a deliverable mail transport, sender/domain authentication, rate limits, token expiry/storage cleanup, URL/HTTPS behavior and user-facing templates; add abuse, expiry and enumeration-resistance tests. Do not treat the local log mailer as a production service.
- CSRF request rejection was not tested because Laravel disables that middleware in PHPUnit. Browser-based CSRF and session-cookie tests were not run.
- No real-browser verification was available for responsive layout, rendered history, approval/cancellation controls, loading states, duplicate submissions or provider failure display.
- MySQL concurrent-approval tests, database privilege inspection, deployed HTTPS/CORS/security-header checks, production `APP_DEBUG` and secret-manager inspection, backup/restore drills, monitoring and incident-response exercises were not performed.
- No prompt-injection red-team, independent penetration test, formal privacy/compliance review or production deployment inspection was performed.

## Deployment responsibilities and residual risks

- Enforce HTTPS at the production edge, set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, and confirm trusted-proxy configuration matches the actual platform. Keep session cookies `HttpOnly` and `SameSite=Lax` unless a documented integration requires otherwise.
- Inject `APP_KEY`, database credentials and only the selected provider's key (`OPENROUTER_API_KEY` or `GROQ_API_KEY`) from a protected secret manager. Set `AI_PROVIDER` deliberately; do not place secrets in source control, frontend bundles, command history or support recordings. Rotate a credential if it is ever exposed; this assessment did not rotate credentials.
- Use a unique production application key and least-privilege MySQL credentials; restrict network access, encrypt and test backups, and document recovery objectives. Do not run database migrations or tests against production data.
- Configure security headers, CORS policy, edge rate limits, redacted centralized logging, uptime/error monitoring, dependency updates and incident response at the deployment layer. Their deployed state cannot be inferred from this repository.
- The selected provider and model receive the current message and tool results; recent conversation context is sent only when the user has opted in. Review the selected provider's retention/terms and lawful basis before production use. Never submit passwords, payment credentials or identity documents.
- Exchange rates and remittance fee profiles are static demonstrations, not live rates or offers. Funding opportunities are user-entered and unverified. Warnings are request-time calculations, not continuous monitoring. Atlas does not move funds or guarantee financial outcomes.
- Generated model text may still be inaccurate or misleading. Treat the deterministic tool calculations and explicit confirmation boundary as the authority for supported actions, and keep human review in consequential workflows.

## Verification record

For the current provider-adapter change, `php artisan test --compact` passed 90 tests and 635 assertions; `npm run build` succeeded; `composer audit --locked` reported no advisories; `npm audit --audit-level=moderate` found zero vulnerabilities; `vendor/bin/pint --dirty --format agent` completed; and `git diff --check` passed with line-ending normalization warnings. The suite includes provider-mocked Groq request, tool-call, approval, key-rejection, rate-limit, timeout, and malformed-response coverage. No live Groq request was made because account access and free quota were not verified. The latest live OpenRouter smoke-test result remains the HTTP 429 account/free-model daily limit described above. Migration status was not rerun for this provider-only change, which added no migrations. Earlier live smoke-test success is historical evidence only. A passing automated suite is not a security certification.
