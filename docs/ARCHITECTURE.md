# Technical architecture

## Application flow

Browser → Laravel routes/controllers → authenticated Eloquent relations → MySQL. Blade renders the workspace. Small JSON requests are used for conversion, remittance estimates, scenario calculations and the chat workspace. CSRF protection remains enabled for state-changing requests; write endpoints are throttled.

`StudentProfile`, `Transaction`, `TuitionPlan`, `SavingsGoal`, `Budget`, `FundingOpportunity`, `FinancialPlan`, and agent records all have user ownership. Dashboard queries use the authenticated user’s relationships. There are no client-selected user IDs in tool schemas.

## Gemini agent

`GeminiAgent` calls the configurable `GEMINI_MODEL` through Google’s `v1beta/models/{model}:generateContent` REST endpoint using a server-only API key header. The default is `gemini-3.8-flash`. Its system instruction treats prompt content and tool results as untrusted, and the request includes only the signed-in user’s current message and—if the profile’s memory consent is enabled—up to 12 recent conversation messages.

The documented API version, endpoint and GenerateContent request structure are used. Gemini 2.5 Flash returned HTTP 404 with a provider message that it is unavailable to new users and recommended Gemini 3.8 Flash. The configured Gemini 3.8 Flash model returned HTTP 503 (`UNAVAILABLE`, high demand) on two cautious minimal live requests, so the live agent/tool-calling round cannot yet be verified. The application surfaces provider failures and never pretends the call succeeded. PHP cURL TLS verification succeeded when given a trusted Mozilla CA bundle; set `GEMINI_CA_BUNDLE` if the runtime does not already trust the provider certificate.

The model receives declarations from `AgentToolRegistry`, not arbitrary application services. The registry validates argument keys and values again before dispatch. Available tools are: financial summary, the current user’s funding-opportunity list, currency conversion, spending analysis, tuition savings plan, remittance comparison, and draft savings-goal, budget, or saved-plan actions. Funding records are user-entered and are not externally verified. Tuition IDs are resolved through the current user’s relation. No arbitrary SQL, free-form model-generated database query or user ID is accepted.

The loop allows at most four tool rounds, four calls in a response batch, and twelve calls in one turn. Identical name/argument calls are suppressed for the rest of the turn, preventing repeated model output from staging duplicate changes. The application performs financial arithmetic; Gemini explains results. Tool failures are returned as bounded, user-safe results. API connect/read timeouts, provider errors and request limits do not expose raw provider response bodies or credentials.

Planning writes create a 15-minute `AgentPendingAction`. The user reviews the proposed arguments in the workspace and confirms it. The server then reloads that pending action through the authenticated user and conversation, validates it, saves the change and audit event in a database transaction, and marks it confirmed. A confirmed/expired action cannot be replayed. Audit events record tool name, status and a short non-sensitive summary, not tool arguments.

The workspace reloads persisted tool results with conversation history and lists all non-expired pending approvals across the user’s conversations in a trust center. Approve/discard controls remain bound to the owning user and conversation. The dashboard derives tuition, budget, and spending warnings from recorded information on each page request; there is no scheduler, push notification, or continuous monitor.

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
7. With a valid `GEMINI_API_KEY`, ask Atlas to summarize finances or prepare a savings goal. Review its staged arguments in the trust center and explicitly confirm or discard the action.

For the complete timed presentation and recording checklist, see [DEMO.md](DEMO.md).
