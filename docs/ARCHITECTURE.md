# Technical architecture

## Application flow

Browser → Laravel routes/controllers → authenticated Eloquent relations → MySQL. Blade renders the workspace. Small JSON requests are used for conversion, remittance estimates, scenario calculations and the chat workspace. CSRF protection remains enabled for state-changing requests; write endpoints are throttled.

`StudentProfile`, `Transaction`, `TuitionPlan`, `SavingsGoal`, `Budget`, `FundingOpportunity`, `FinancialPlan`, and agent records all have user ownership. Dashboard queries use the authenticated user’s relationships. There are no client-selected user IDs in tool schemas.

## OpenRouter agent

`OpenRouterClient` calls the OpenRouter Chat Completions endpoint through Laravel's HTTP client. It reads `OPENROUTER_API_KEY`, `OPENROUTER_MODEL`, and a bounded timeout from Laravel service configuration. The key and authorization header are server-side only. `ChatCompletionClient` is the provider boundary; `OpenRouterAgent` depends on that contract, not on HTTP implementation details.

The agent sends the exact configured model ID and OpenAI-compatible tool declarations. `openrouter/free` and `openrouter/auto` are rejected because they can select different models between requests. `openai/gpt-4o-mini` is the documented setup candidate and the public OpenRouter model catalog advertises tool calling for it; account access and an actual successful tool call still require the live smoke test. The last live connection attempt failed before a successful response, so no live model or tool-call success is claimed. `php artisan openrouter:test` requests a minimal synthetic function call, validates its exact name and token, and never executes an application tool or prints generated content or credentials.

The model receives declarations from `AgentToolRegistry`, not arbitrary application services. Strict server-side validation rejects unknown keys and invalid values before dispatch. Read-only tools provide financial summary, the current user’s funding-opportunity list, currency conversion, spending analysis, tuition savings calculations, and remittance comparisons. Planning writes can prepare a savings goal, category budget, saved note, existing savings-goal target update, or existing tuition-plan update. Funding records are user-entered and unverified. Record IDs are resolved through the authenticated user’s Eloquent relations.

The loop allows at most four tool rounds, four calls in a response batch, and twelve calls in one turn. Identical name/argument calls are suppressed for the rest of the turn. Application code performs financial arithmetic; OpenRouter explains results. Provider failures, malformed responses, timeouts, and request limits are converted to bounded user-safe errors; raw response bodies and credentials are not returned. Completion POSTs are not automatically retried because a timeout may follow provider processing.

Planning writes create a 15-minute `AgentPendingAction` with the exact validated arguments and, for existing-record updates, a before-state snapshot. The pending row is bound to its owner and conversation. The UI displays the affected record, original and proposed values, currency, and action scope. Confirmation reloads the owner-scoped row, locks and validates it, rejects stale snapshots, saves the change and audit event in a database transaction, and persists the actual outcome to conversation history. Cancellation, expiry, and successful confirmation are recorded; handled actions cannot be replayed. These approvals authorize only saved Atlas planning changes, never a payment or transfer.

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
7. With a valid OpenRouter key and a fixed model that supports tool calling, ask Atlas to summarize finances or prepare/update a planning record. Review the exact before/proposed values in the trust center and explicitly confirm or discard the action. The configured model must pass `php artisan openrouter:test` before presenting this as a live AI workflow.

For the complete timed presentation and recording checklist, see [DEMO.md](DEMO.md).
