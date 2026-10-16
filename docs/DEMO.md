# Judge demo: 8 minutes

## Presentation flow

1. **0:00–0:45 — The problem.** Introduce a student balancing a home currency, a destination-country budget and a tuition deadline. Emphasize that Atlas organizes decisions; it does not hold or transfer funds.
2. **0:45–1:40 — Private onboarding.** Register a fresh account, choose a destination and home currency, set a monthly budget, and show that conversation memory is opt-in.
3. **1:40–2:45 — Build the financial picture.** Add one housing expense, one income record, a tuition deadline, and an opportunity found from an official scholarship source. Explain that these entries are user-entered and nothing is externally verified or bank-confirmed.
4. **2:45–3:40 — Tuition and goals.** Add a savings goal and show the deterministic remaining balance and required weekly/monthly savings pace for the chosen due date. Update the scholarship application status.
5. **3:40–4:40 — Cross-border estimates.** Convert a home-currency amount, point out the rate source and retrieval timestamp, then compare fee models. State that the rates, fees and delivery windows are demonstrations—not live quotes.
6. **4:40–5:35 — Signals and scenarios.** Review the tuition/budget warnings and explain they are recalculated from recorded information only when the page opens. Adjust rent change, income delay, scholarship timing, monthly contribution and FX stress; compare favorable, baseline and adverse tuition forecasts and inspect the stated currency conversion assumptions. Call out that scholarship funding and all scenario results are hypothetical and not monitored continuously.
7. **5:35–7:15 — Agentic workflow.** First run `php artisan openrouter:test` with a fixed, tool-capable model and valid server credentials. If it succeeds, ask Atlas to summarize spending or prepare/update a savings goal or tuition plan. Show the backend tool result, compare the current and proposed values in the trust center, then explicitly confirm or discard. Refresh the page to demonstrate persisted outcome and remaining approvals. If the live test fails or cannot be run, demonstrate only the guarded setup/provider error—never present a mocked or scripted answer as live AI.
8. **7:15–8:00 — Security and limits.** Show the persistent light/dark control and a second account that cannot see the first account’s records. Recap memory consent, simulated data labels, and the no-transfer boundary.

## Data and integration labels

- **Live external integrations in this build:** none are claimed as verified.
- **Configurable external integration:** OpenRouter Chat Completions using server-side `OPENROUTER_API_KEY` and a fixed `OPENROUTER_MODEL`. `openrouter/free` and `openrouter/auto` are rejected. Run the real synthetic tool-call smoke test before presenting the assistant as live; the last live connection attempt failed at transport, so successful live responses and tool calls remain unverified.
- **Simulated:** exchange-rate table, remittance fee profiles (ranked by estimated recipient amount), delivery windows and scenario outcomes.
- **Warnings:** request-time calculations from manually recorded budget, spending, and tuition data; no background monitoring.
- **Funding opportunities:** manually entered by the user; Atlas does not search, verify eligibility or deadlines, or guarantee an award.
- **Manually entered:** budget assumptions, tuition records, savings progress and activity.
- **Confirmed application writes:** saved goals, category budgets and planning notes after an explicit confirmation; these do not initiate payments.

## Evidence-recording checklist

- [ ] Capture the registration/profile flow with a demo account and fictional values.
- [ ] Capture the tuition savings calculation and the changed scenario output.
- [ ] Capture the displayed exchange-rate provenance and simulated remittance labels.
- [ ] Capture a real OpenRouter tool result and pending-confirmation state only after the live tool-call smoke test succeeds; if credentials/model access are unavailable, capture the setup error instead.
- [ ] Capture the confirmation click and resulting saved record, without exposing account secrets.
- [ ] Capture a separate user account showing no access to the first account’s records.
- [ ] Capture financial alerts and verify they cite user-entered data, plus the light/dark control at desktop and mobile widths.
- [ ] Keep API keys, real personal/financial data, session cookies and browser storage out of recordings.
- [ ] Label any synthetic account/transaction information as demo data in presentation materials.
