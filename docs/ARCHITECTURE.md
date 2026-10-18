# Technical architecture

## Application flow

Browser → Laravel routes/controllers → authenticated Eloquent relations → MySQL. Blade renders the workspace. Small JSON requests are used for conversion, remittance estimates, scenario calculations and the chat workspace. CSRF protection remains enabled for state-changing requests; write endpoints are throttled.

`StudentProfile`, `Transaction`, `TuitionPlan`, `SavingsGoal`, `Budget`, `FundingOpportunity`, `FinancialPlan`, and agent records all have user ownership. Dashboard queries use the authenticated user’s relationships. There are no client-selected user IDs in tool schemas.

## Authentication and authorization boundaries

Registration uses a 15-minute email OTP stored as a hash in the existing `password_reset_tokens` table; registration verification and resend endpoints are throttled. Login uses account-aware and IP-aware rate limits and rotates the session after authentication. Logout invalidates the session. Password recovery sends a 15-minute email OTP, stores only its hash in that same table, rate-limits requests and verification, uses a generic response for known and unknown addresses, and deletes the token after use or failed delivery. Successful reset updates the password and remember token and deletes sessions when the configured session driver is database-backed. State-changing routes remain in the `web` middleware group for CSRF protection; the PHPUnit environment disables CSRF verification, so its request-level rejection is not covered by the automated HTTP tests. Cross-account ownership checks are exercised for dashboard data, tuition and goal progress, funding status, conversation history, and pending-action confirmation/cancellation.

No schema change was required for recovery. The flow's real SMTP delivery and cross-session invalidation on non-database session drivers have not been verified. The application does not provide an authenticated password-change screen or authenticator-app MFA.

Registration uses the application's own email OTP flow; it does not implement Laravel's `MustVerifyEmail` contract. Password recovery also uses a short-lived email OTP and the existing `password_reset_tokens` table. Both flows require a working non-logging SMTP transport and a verified sender in deployment. Successful recovery rotates the remember token and revokes sessions only when `SESSION_DRIVER=database`; non-database session revocation is not implemented.

## AI provider and agent

`ChatCompletionClient` is the provider boundary; `OpenRouterAgent` depends on that contract, not on HTTP implementation details. `AI_PROVIDER` selects `openrouter` (default) or `groq`. `OpenRouterClient` and `GroqClient` call their respective Chat Completions endpoints through Laravel's HTTP client and read provider-specific API keys, models, and bounded timeouts from Laravel service configuration. Keys and authorization headers are server-side only. Both adapters use HTTPS with TLS verification and the project CA bundle; provider POSTs are not automatically retried.

The agent sends the configured model ID and OpenAI-compatible tool declarations. `openrouter/free` and `openrouter/auto` are rejected because they can select different models between requests. Groq requests translate the agent's `max_tokens` field to `max_completion_tokens`; tool-result messages include the matching tool name and call ID. `php artisan openrouter:test` and `php artisan groq:test` send a minimal synthetic tool-call request, validate the function name and token, and never execute an application tool or print generated content or credentials. Mocked success does not establish live model access, free quota, or provider uptime.

The model receives declarations from `AgentToolRegistry`, not arbitrary application services. Strict server-side validation rejects unknown keys and invalid values before dispatch. Read tools provide financial and funding summaries, spending analysis, tuition schedules and forecasts, currency conversion, and simulated remittance comparisons. Confirmable planning writes create tuition plans, savings goals, category budgets and saved planning notes; update tuition plans, savings goals and category budgets; or delete only those three supported record types. Each target is resolved through the authenticated user's Eloquent relation. Funding entries are user-entered and unverified; user records marked awarded are not treated as confirmed funds by the Flight Plan.

The loop allows at most four tool rounds, four calls in a response batch, and twelve calls in one turn. Identical name/argument calls are suppressed for the rest of the turn. Application code performs financial arithmetic; the selected provider explains results. Provider failures, malformed responses, timeouts, and request limits are converted to bounded user-safe errors; raw response bodies and credentials are not returned. Completion POSTs are not automatically retried because a timeout may follow provider processing. Full structured tool results remain server-side in the agent loop and persistence; chat and history responses expose concise summaries and only the allowlisted details needed to review an approval.

Planning writes create a 15-minute `AgentPendingAction` with exact validated arguments and a before-state snapshot for updates and deletions. The pending row is bound to its owner and conversation. The UI displays the exact target, current and proposed values, currency, and action scope; deletion adds a browser confirmation. Confirmation reloads the owner-scoped row, locks and validates it, rejects stale snapshots, saves the change and audit event in one database transaction, and persists the actual outcome to conversation history. Cancellation, expiry, stale/invalid failure and successful confirmation are recorded; terminal actions cannot be replayed. Failed stale approvals leave the underlying record unchanged and are removed from the active approvals list. These approvals authorize only saved Atlas planning changes, never a payment or transfer.

Approval transactions use database row locks, but the automated test database is in-memory SQLite. Concurrent requests and lock behavior must still be tested against the production MySQL version before relying on concurrency guarantees.

The Student Financial Flight Plan reuses user-scoped dashboard queries and the existing `FinancialPlanner` and `CurrencyCalculator`. It summarizes tuition plans, savings goals, active category budgets, current-month home-currency expenses and user-entered funding deadlines. The forecast uses the earliest eligible outstanding tuition plan, compares a baseline with rent, income-delay and foreign-exchange cases only when their inputs support them, reports missing assumptions, and does not include potential funding as cash. Dates and period counts come from the existing tuition schedule. The interface identifies the selected target and repeats its assumptions. It is a request-time estimate, not an alerting service or prediction.

The workspace reloads persisted tool results with conversation history and lists non-expired pending approvals across the user's conversations in a trust center. Approve/discard controls remain bound to the owning user and conversation. The dashboard derives tuition, budget, and spending warnings from recorded information on each page request; there is no scheduler, push notification, or continuous monitor.

## Money and rate methodology

Database monetary values use decimal columns with two fractional digits and an explicit ISO currency code. The supported demonstration currencies are all two-decimal currencies. Calculations parse decimal strings to integer minor units; conversion rates are integer micro-units relative to USD. Cross-currency amounts are rounded half-up to the target currency’s two-digit minor unit. Tuition periods use ceiling division so required savings are not understated by fractional cents. Zero-decimal currencies are intentionally not supported until currency-specific storage and rounding are implemented.

The rate table is deliberately static demo data. Each conversion labels the source, retrieval time and `live=false`; this timestamp means when the application read the local table, not a market update. Simulated remittance models subtract the disclosed fee before conversion and mark delivery windows as estimates. They are ranked by the deterministic recipient amount after fees, but are not genuine transfer routes or provider offers. Tuition installment dates are generated as equal monthly amounts ending on the selected due date; saved progress is allocated to earlier installments. This is a planning estimate, not an institution-issued payment schedule. No external rate or remittance API is currently integrated.

The scenario lab returns deterministic favorable, baseline and adverse tuition forecasts. Monthly inputs use the profile’s home currency and are explicitly converted to the selected budget currency using the static demonstration rate table. It treats monthly rent as part of submitted spending and monthly income as part of the submitted budget, subtracts income during the selected initial delay, applies a stated percentage rent change from month one, and adds only an expected scholarship whose month falls within the selected horizon. The tuition target and current savings are converted using the same static table; symmetric FX stress adjusts those values by integer percentage arithmetic and half-up minor-unit rounding. Planned contributions are capped by each month’s available amount after spending and assumptions. Outputs are estimates; the scholarship is unconfirmed and rates are simulated.

## Persistence and database

Migrations define foreign keys with cascading owner deletion, owner/date indexes, conversation history and audit records. The schema is compatible with MySQL and SQLite. Automated tests use an in-memory SQLite database. No sample financial records are seeded: an empty account remains genuinely empty until its owner adds records.

## Reproducible demo

1. Register a new account and set destination, home currency, monthly budget and privacy preference.
2. Record one manually entered expense and distinguish it from a simulated activity item.
3. Add a tuition deadline and savings goal; review the calculated monthly/weekly savings requirement.
4. Add a funding opportunity found from an official source; explicitly note Atlas does not verify eligibility or amounts.
5. Convert an amount and compare the clearly simulated remittance fee profiles.
6. Review page-load financial warnings, then run a scenario with a rate change and different tuition contribution.
7. With a valid key for the selected provider and a model that supports tool calling, ask Atlas to summarize finances or prepare/update a planning record. Review the exact before/proposed values in the trust center and explicitly confirm or discard the action. Run the matching `php artisan openrouter:test` or `php artisan groq:test` before presenting the provider as live; the account's current access and quota must also be checked.

For the recovery flow, use only a fictional test mailbox and test SMTP account. Request a password-recovery code, confirm receipt, then use it once and verify the prior password no longer works. Do not record real passwords or OTPs in demo materials. Mail fakes establish application message behavior only; they do not verify delivery to Gmail or another mailbox.

For the complete timed presentation and recording checklist, see [DEMO.md](DEMO.md).
