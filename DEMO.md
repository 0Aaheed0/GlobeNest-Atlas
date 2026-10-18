# Judge demo: 8 minutes

## Presentation flow

1. **0:00–0:45 — The problem.** Introduce an international student balancing home-currency spending and a tuition deadline. Atlas helps organize the decision; it does not hold or transfer funds.
2. **0:45–1:30 — Private setup.** Use a prepared fictional account. Point out its explicit currency, budget and opt-in conversation memory. Registration and recovery email require a configured test mailbox, so avoid spending live-demo time on account setup.
3. **1:30–2:20 — Build the picture.** Show fictional rent and food entries, tuition, a savings goal and a user-entered funding opportunity. State that records are manual, and funding is unverified.
4. **2:20–3:45 — Student Financial Flight Plan.** Show the saved goals, active category limits, tuition balance, date and required weekly/monthly pace. Explain that the outlook uses the earliest eligible tuition target, the current-month spending sample and the existing deterministic planner. Compare only the displayed rent/income/FX stress cases and point to missing-input notes. Emphasize that potential awards are not counted as available money.
5. **3:45–4:35 — Cross-border estimates.** Convert a fictional amount and compare simulated fee profiles. Point out the fixed-rate source, read timestamp, fee currency and no-live-quote disclaimer.
6. **4:35–6:30 — Agent completes a planning task.** If live access has been freshly verified, ask Atlas to update one clearly identified goal or tuition plan. Show exact before/proposed values, then explicitly approve; for deletion, show the extra confirmation. Refresh and show the verified saved result. Also discard another proposal and show no write. If provider access is unavailable, label the interaction as a mocked automated-test example or show the real safe setup/provider error; never describe a mock as live.
7. **6:30–7:20 — Security and privacy.** Show account isolation with a second fictional account, approval expiry/stale-state behavior, the opt-in memory setting and redacted action log. Do not expose OTPs, credentials, cookies or real financial details.
8. **7:20–8:00 — Limits and close.** Reiterate no bank connection, external award verification, live rates, continuous monitoring or money movement. Close on explainable estimates and explicit approval.

## Data and integration labels

- **Live external integrations in this build:** none are claimed as verified.
- **Configurable external integration:** OpenRouter Chat Completions using server-side `OPENROUTER_API_KEY` and a fixed `OPENROUTER_MODEL`; `openrouter/free` and `openrouter/auto` are rejected. The last recorded live attempt (2026-10-16) returned HTTP 429 before a tool-call result. No current live-provider success is established; rerun `php artisan openrouter:test` immediately before describing the integration as live.
- **Simulated:** exchange-rate table, remittance fee profiles (ranked by estimated recipient amount), delivery windows and scenario outcomes.
- **Warnings:** request-time calculations from manually recorded budget, spending, and tuition data; no background monitoring.
- **Funding opportunities:** manually entered by the user; Atlas does not search, verify eligibility or deadlines, or guarantee an award.
- **Manually entered:** budget assumptions, tuition records, savings progress and activity.
- **Confirmed application writes:** saved goals, category budgets and planning notes after an explicit confirmation; these do not initiate payments.

## Evidence-recording checklist

- [ ] Use two fictional accounts (for example, `student-one@example.test` and `student-two@example.test`) and clearly invented values; do not record real personal or financial information.
- [ ] Do not use or record the seeded default test account; the repository seeder no longer creates a fixed account. Create fictional accounts through registration for each demo run.
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
- [ ] If demonstrating password recovery, use a fictional account and test mailbox; show the single-use reset and avoid exposing the OTP or either password in the recording.
