# Judge demo: 8 minutes

## Presentation flow

1. **0:00–0:45 — The problem.** Introduce a student balancing a home currency, a destination-country budget and a tuition deadline. Emphasize that Atlas organizes decisions; it does not hold or transfer funds.
2. **0:45–1:40 — Private onboarding.** Register a fresh account, choose a destination and home currency, set a monthly budget, and show that conversation memory is opt-in.
3. **1:40–2:45 — Build the financial picture.** Add one housing expense, one income record, a tuition deadline, and an opportunity found from an official scholarship source. Explain that these entries are user-entered and nothing is externally verified or bank-confirmed.
4. **2:45–3:40 — Tuition and goals.** Add a savings goal and show the deterministic remaining balance and required weekly/monthly savings pace for the chosen due date. Update the scholarship application status.
5. **3:40–4:40 — Cross-border estimates.** Convert a home-currency amount, point out the rate source and retrieval timestamp, then compare fee models. State that the rates, fees and delivery windows are demonstrations—not live quotes.
6. **4:40–5:35 — Signals and scenarios.** Review the tuition/budget warnings and explain they are recalculated from recorded information only when the page opens. Adjust rent change, income delay, scholarship timing, monthly contribution and FX stress; compare favorable, baseline and adverse tuition forecasts and inspect the stated currency conversion assumptions. Call out that scholarship funding and all scenario results are hypothetical and not monitored continuously.
7. **5:35–7:15 — Agentic workflow.** Run `php artisan openrouter:test` with the fixed, tool-capable configured model and valid server credentials. The current audit environment passed this minimal live test; rerun it before a live demonstration. Ask Atlas to summarize spending or prepare/update a savings goal or tuition plan. Show the concise tool summary and compare the current and proposed values in the trust center, then explicitly confirm or discard. Refresh the page to demonstrate the persisted outcome and remaining approvals. If the live test fails or cannot be run, demonstrate only the guarded setup/provider error—never present a mocked or scripted answer as live AI.
8. **7:15–8:00 — Security and limits.** Show the persistent light/dark control and a second account that cannot see the first account’s records. Recap memory consent, simulated data labels, and the no-transfer boundary.

## Data and integration labels

- **Live external integrations in this build:** none are claimed as verified.
- **Configurable external integration:** OpenRouter Chat Completions using server-side `OPENROUTER_API_KEY` and a fixed `OPENROUTER_MODEL`. `openrouter/free` and `openrouter/auto` are rejected. The configured model passed a real synthetic tool-call smoke test on 2026-10-16; rerun the smoke test before presenting a later demo as live.
- **Simulated:** exchange-rate table, remittance fee profiles (ranked by estimated recipient amount), delivery windows and scenario outcomes.
- **Warnings:** request-time calculations from manually recorded budget, spending, and tuition data; no background monitoring.
- **Funding opportunities:** manually entered by the user; Atlas does not search, verify eligibility or deadlines, or guarantee an award.
- **Manually entered:** budget assumptions, tuition records, savings progress and activity.
- **Confirmed application writes:** saved goals, category budgets and planning notes after an explicit confirmation; these do not initiate payments.

## Evidence-recording checklist

- [ ] Use two fictional accounts (for example, `student-one@example.test` and `student-two@example.test`) and clearly invented values; do not record real personal or financial information.
- [ ] On account one, capture profile setup with an explicit home currency and a fictional tuition plan showing its deterministic remaining balance and required savings pace.
- [ ] Change one scenario assumption (such as rent increase, income delay or tuition contribution) and capture the changed forecast with its assumptions and uncertainty visible.
- [ ] Convert a fictional amount and capture the currency codes, demonstration-rate source, retrieval timestamp and `live=false` label.
- [ ] Run a fictional remittance simulation and capture the simulated fee-profile disclaimer, fee currency and estimated recipient amount; do not call these provider offers.
- [ ] After `php artisan openrouter:test` succeeds against the configured fixed model, capture an actual AI tool result using only account-one synthetic records. If the live request is unavailable, show the real setup/provider error and do not substitute a mocked response.
- [ ] Prepare a savings goal or tuition-plan update and capture the pending proposal, exact before/proposed values, currency and explicit confirmation controls.
- [ ] Confirm the proposal, refresh, and capture the saved outcome. Also capture a separate discarded proposal and verify it was not saved.
- [ ] Sign in as account two and capture that account one's records, conversation and pending proposals are unavailable. If testing a direct unauthorized request, record only the expected not-found result and synthetic record identifier.
- [ ] Capture financial alerts and verify they cite user-entered data, plus the light/dark control at desktop and mobile widths.
- [ ] Capture a long AI response, loading state, provider failure handling and conversation history after refresh; verify ordinary assistant messages do not contain raw tool JSON.
- [ ] Keep API keys, real personal/financial data, session cookies and browser storage out of recordings.
- [ ] Label any synthetic account/transaction information as demo data in presentation materials.
