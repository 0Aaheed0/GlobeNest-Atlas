# GlobeNest Atlas — presentation source and speaker script

**Run time:** 8 minutes 25 seconds (under the 10-minute maximum)  
**Format:** eight-slide editable source. Transfer slide titles and bullets into PowerPoint, Google Slides or Keynote; use the notes as speaker script.  
**Evidence rule:** replace visual placeholders only with captured results from a real run using fictional accounts. Label mocked test output as mocked. Do not claim a live provider, browser, security or test result without current evidence.

The published international-track checklist asks for system architecture, core algorithm description and security design; a presentation of no more than 10 minutes; source code with README, repository link, deployment instructions and test cases; a security self-assessment; and execution evidence for the selected non-on-chain topic. The same appendix describes the optional demo video as no longer than five minutes and lists simulated-account/sandbox operation logs and a demo video under execution evidence. Confirm with the organizers which evidence items are mandatory for this submission. [Competition rules, Appendix 2](https://www.its.ac.id/international/wp-content/uploads/sites/66/2026/10/PBCSF-Appendix-2-Problem-Description-and-Competition-Rules-for-International-Track.pdf)

---

## Slide 1 — A clearer financial decision for students

**On slide**

- GlobeNest Atlas
- Student and cross-border financial planning assistant
- Understand the plan. Compare assumptions. Approve any saved change.

**Visual direction:** use one real dashboard screenshot with synthetic data after it has been captured. Otherwise use a simple illustration labeled “concept visual,” not a product screenshot.

**Speaker notes — 0:35**

“International students often plan across two currencies, uneven expenses and hard tuition deadlines. A useful answer needs to connect those records and show how it reached a recommendation. GlobeNest Atlas is a private planning workspace designed around that need. It organizes the information a student enters, calculates a transparent estimate and makes the next action reviewable. Atlas does not hold money or make payments.”

---

## Slide 2 — The user problem and design choice

**On slide**

- Home currency and destination costs
- Tuition dates and savings pace
- Manual records are incomplete by nature
- Make uncertainty visible instead of inventing certainty

**Visual direction:** a simple student timeline with “today,” “monthly spending,” and “tuition due” labels; no personal data.

**Speaker notes — 0:50**

“A student may know their tuition balance but not whether their budget can support the required pace. Exchange rates move, monthly spending records may cover only part of a month, and a scholarship application is not cash. Atlas keeps those facts separate. Its design choice is explainability: show which saved values were used, what the estimate assumes, what is missing and what remains unverified.”

---

## Slide 3 — One account-scoped planning workspace

**On slide**

- Profile, income and monthly budget
- Manual expenses and income
- Tuition, savings goals and category limits
- User-entered funding deadlines
- Fixed demonstration rates and simulated fees

**Visual direction:** show the workspace sections as a compact map. Use a real interface capture only after browser review.

**Speaker notes — 1:00**

“The workspace brings together the profile budget, manually entered activity, tuition plans, savings goals, category budgets and funding deadlines. Data access is scoped to the signed-in account. Transactions are not imported from banks. Funding records are entered by the user and are not externally verified. Currency conversion uses Atlas’s fixed demonstration table. Remittance comparisons use simulated fee profiles, not provider quotes. These limits are visible because a polished interface should not imply that a data source exists when it does not.”

---

## Slide 4 — Student Financial Flight Plan

**On slide**

- Outstanding tuition and required weekly/monthly pace
- Current-month expense baseline and category context
- Baseline plus applicable rent, income-delay and FX cases
- Missing information and assumptions shown
- Potential awards excluded from available funds

**Visual direction:** use the actual Flight Plan panel with a fictional account. Highlight the “earliest eligible plan” and stress-case labels.

**Speaker notes — 1:25**

“The Flight Plan combines saved tuition, goals, category limits and current-month spending. The deadline schedule reports its weekly and monthly periods and contribution requirement. For the affordability outlook, Atlas selects the earliest eligible outstanding tuition plan and says so. It repeats the current-month spending sample through the horizon, which can be incomplete. A rent stress is shown only when rent or housing expenses are recorded. An income-delay case needs a saved positive income value. Foreign-currency tuition can receive favorable and adverse demonstrations using the same fixed rate table. These scenarios are independent illustrations, not predictions. A funding opportunity, even one marked awarded, is never added to available funds.”

---

## Slide 5 — AI helps complete a bounded planning task

**On slide**

1. Ask in natural language
2. Model selects a registered tool
3. Server validates and scopes the request
4. Atlas shows the exact proposal
5. User confirms or discards
6. Atlas verifies and reports the outcome

**Visual direction:** use a real captured proposal with synthetic values; show the confirmation control only if observed in the browser.

**Speaker notes — 1:30**

“The language model can choose only from a finite set of declared tools. The server validates arguments and resolves records through the signed-in user’s relationships. Reads and calculations return concise, server-generated summaries. A create, update or supported delete becomes a short-lived proposal that shows the exact record and values. Confirmation authorizes only that proposal; deletion has an additional confirmation. Stale proposals fail without changing the record and are shown as failed, not left in the pending list. A successful write is checked and its result is stored in the conversation history. If the provider is unavailable, we show that failure. We do not stage a scripted answer as a live AI result.”

**Live-demo branch:** run `php artisan openrouter:test` immediately before the presentation. If it succeeds, use one fictional task and label the provider/model shown in the workspace. If it fails or is unavailable, skip the live provider claim and explain the tested server-side boundary from source or a clearly labeled mock.

---

## Slide 6 — Security and privacy boundaries

**On slide**

- Authenticated, owner-scoped reads and writes
- CSRF and throttled sensitive routes
- Memory opt-in; provider receives current message and tool results
- No passwords, OTPs, API keys or payment credentials in summaries
- No transfers, bank connectivity or external award verification

**Visual direction:** diagram: Browser → authenticated Laravel app → allowlisted tool registry → deterministic services/database; external AI shown as a separate, configured boundary.

**Speaker notes — 1:10**

“The backend, not the model, authorizes records and performs calculations. Sensitive routes are throttled, state-changing web routes use CSRF middleware, and tool inputs reject unexpected fields. Memory is opt-in: when enabled, recent conversation text is sent to the selected provider along with the current message and tool results. Provider terms and retention must be reviewed before deployment. Password recovery rotates the remember token and revokes database-backed sessions; other session drivers do not yet have global revocation. This is a source-level security assessment, not a penetration test or certification.”

---

## Slide 7 — Architecture and deterministic calculations

**On slide**

- Laravel controllers and owner-scoped Eloquent relations
- Agent orchestration with bounded tool rounds
- FinancialPlanner: integer minor-unit arithmetic
- CurrencyCalculator: fixed rate table and explicit conversions
- Approval transaction: snapshot check → write → audit → outcome

**Visual direction:** create a five-box flow diagram from the bullets. Mark model output “untrusted input.”

**Speaker notes — 1:00**

“The AI provider is an interface for understanding and explaining a request; it does not query the database or perform the arithmetic. Registered tools call the same application services used by the rest of the product. Amounts are calculated in integer minor units, and unlike currencies stay separate unless explicitly converted through the demonstration table. The approval path locks the owner-scoped proposal, compares the current record with its reviewed snapshot, then writes the record, audit status and outcome inside a database transaction. Automated tests use in-memory SQLite, so MySQL concurrency still needs deployment-level verification.”

---

## Slide 8 — Evidence, limits and next step

**On slide**

- Automated: current exact output goes here, dated
- Manual: browser, SMTP and account-isolation checklist
- Live AI: not established until freshly smoke-tested
- Presentation source, README, architecture and security assessment
- Next: collect fictional sandbox logs and complete final checks

**Visual direction:** insert only real dated evidence. Leave any unavailable evidence visibly marked “pending.”

**Speaker notes — 0:55**

“Atlas’s strongest demonstration is a complete, reviewable planning loop: a student asks a question, sees the data and assumptions, reviews an exact action and can verify what happened. The product remains deliberately limited: rates are static, funding is user-entered, alerts are request-time, and there is no bank access or transfer execution. The repository includes setup and architecture documentation, a security self-assessment, test cases and this presentation source. We will label the exact automated and manual evidence collected, and leave live-provider or browser checks pending until they are actually performed. Thank you.”

---

## Presenter evidence checklist

- [ ] Confirm official competition dates, submission channel and evidence requirements with the organizers.
- [ ] Replace concept visuals with browser captures from two fictional accounts; remove cookies and browser storage from any recording.
- [ ] Fill in only current test/build/audit output with command, date and actual result.
- [ ] Collect a sandbox operation log for one read, one forecast, one confirmed planning update and one canceled proposal.
- [ ] If a live model is demonstrated, run the provider smoke-test command immediately before recording and retain its non-secret output.
- [ ] Keep the optional video at five minutes or less; verify whether the organizers also require it as execution evidence.
- [ ] Time the presentation aloud and keep it below ten minutes.
