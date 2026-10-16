# Security self-assessment

## Controls implemented

- Registration and authentication use Laravel’s session guard; successful login regenerates the session, logout invalidates it, passwords use Laravel’s hashing cast, and sign-in is throttled.
- State-changing web routes retain CSRF middleware. Profile, activity, planning and agent endpoints validate input server-side and apply per-route throttles.
- User-owned queries are scoped through authenticated Eloquent relations. Agent conversation history, tuition records, and funding opportunities are checked against the current user. Models use explicit fillable fields.
- Tool declarations are allowlisted, arguments are validated twice (model output and confirmation), extra keys are rejected, execution is bounded and financial calculations stay in application code.
- Consequential planning writes require an owned, expiring, single-use server-side pending action and an explicit confirmation request. Saves and confirmation audit entries share a database transaction.
- Pending approvals are restored from owner-scoped records after refresh. The browser exposes confirmation/discard actions but is never authoritative; expired, foreign, confirmed, or cancelled actions are rejected server-side.
- OpenRouter requests use Laravel's HTTP client with bounded connection/response timeouts, a four-round/four-calls-per-batch/twelve-calls-per-turn bound, and identical-call suppression. Completion POSTs are not automatically retried because the provider may have processed a timed-out request.
- OpenRouter credentials are read through Laravel configuration and sent only in a backend authorization header. Provider errors are converted to bounded messages; raw provider text and credentials are not returned or placed in audit records. The safe smoke-test command validates a synthetic function call without running an Atlas tool.
- The user controls whether recent conversation context is sent to OpenRouter. There is an on-screen warning not to submit passwords, card numbers, API keys, account credentials or identity documents.
- Known dynamic model aliases (`openrouter/free`, `openrouter/auto`) are rejected so a request cannot silently switch models. A fixed model ID must still be available to the account and pass the real smoke test; catalog tool support alone is not proof of access.
- Proposed changes to existing goals and tuition plans include the current state snapshot. Confirmation rejects a stale record, retains its existing currency, and saves the result in the same database transaction as the audit event. Confirmed, cancelled and expired outcomes are persisted in the conversation history.
- Rates, fee profiles, activities and outcomes are labeled as simulated or manually entered; Atlas has no transfer endpoint.

## Threat considerations

| Threat | Current mitigation |
| --- | --- |
| Cross-account access | Owner-scoped relationships and authorization tests for dashboard, agent history and tuition tools. |
| Prompt injection or model misuse | System instruction, fixed tool registry, schema validation, no arbitrary SQL/services, bounded tool loop, explicit write confirmation. |
| Forged/replayed confirmation | Pending action is stored server-side, tied to user and conversation, expires, is row-locked and marked confirmed transactionally. |
| Repeated model tool calls | Identical name/argument calls are skipped in-turn; tool rounds and batch/turn call counts are bounded. |
| Stale financial warning | Warnings disclose that they are calculated only on page load from user-entered records; no continuous-monitoring claim is made. |
| Monetary rounding errors | Integer minor-unit arithmetic and deterministic service tests. |
| Credential exposure | Server-only env configuration and API-key header; no credential output in errors or browser code. |
| Misleading financial claim | Demo provenance, no-transfer disclaimer, and explicit estimate/simulation labels. |

## Residual risks and deployment requirements

- OpenRouter receives the current user message and tool results when used; with consent it also receives recent conversation context. Do not send credentials, payment-card data, identity documents or data without a lawful basis. Review OpenRouter's and the selected upstream model provider's applicable terms and data-retention behavior before production use.
- Exchange and remittance values are demonstrations, not verified live data. The application must not be presented as a rate source or provider comparison service until a reputable provider is integrated, attribution/terms are addressed, and stale-data/error handling is validated.
- Funding opportunities are user-entered and not verified against scholarship sources or eligibility rules; simulated route profiles must not be represented as genuine providers. Dashboard warnings are request-time calculations only, not scheduled notifications.
- Automated coverage is focused on core workflows and service logic; it is not a penetration test or formal compliance review. Production needs dependency monitoring, centralized redacted logs, backups, alerting, security headers/CSP, email verification, password recovery, retention/deletion workflows and operational incident response.
- Cookie security depends on deployment configuration. Set HTTPS, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, a strong unique `APP_KEY`, least-privilege MySQL credentials and a protected secret manager.
- TLS verification must remain enabled for OpenRouter. Never disable HTTP certificate verification to work around local CA configuration.
- Composer may report advisories as its database changes. Run `composer audit --locked` regularly and update supported dependencies promptly.
- No regulatory approval, deposit protection, payment execution or guaranteed savings is claimed.

## Verification

Verified in this worktree: `php artisan test --compact` passed (67 tests, 340 assertions); `npm run build` passed; `vendor\bin\pint --dirty --format agent` passed; `php artisan migrate:status` showed all migrations applied after the additive nullable snapshot-column migration was applied to the local development database; `composer audit --locked` reported no advisories; and `php artisan route:list --except-vendor` listed 23 application routes.

The configured model is currently `openrouter/free`, which the smoke-test command rejected before making a network request because it can route to different models. An earlier live provider attempt failed at transport before a successful response. No live OpenRouter model/tool-call success is claimed. Automated tests mock OpenRouter and do not establish account/model availability. A visual browser review across themes/viewports and a penetration test were not performed. Passing automated checks are not a penetration test or formal compliance review.
