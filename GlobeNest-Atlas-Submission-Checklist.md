# GlobeNest Atlas — submission checklist

Status reviewed 2026-10-18. This document distinguishes implementation found in source from automated evidence and live/manual verification. It is not a security certification or an organizer's approval.

## Competition requirements checked

The published PBCSF International Track Appendix 2 describes the theme “AI as a Financial Participant,” six topic choices (including Cross-border & Student Finance Assistant), and scoring of task completion 40%, security and compliance 30%, innovation and interaction 30%. It asks for technical documentation (architecture, core algorithms and security design), a presentation of at most 10 minutes, source code with README/repository/deployment instructions/test cases, a security self-assessment, and execution evidence. For non-on-chain topics, execution evidence lists simulated-account/sandbox operation logs and a demo video; separately, the appendix labels the demo video optional and caps it at five minutes. Confirm this interpretation and current submission channel with the organizers. The published deadline is October 20, 2026 at 23:59 Beijing time (UTC+8); verify it has not changed before submission. [Appendix 2](https://www.its.ac.id/international/wp-content/uploads/sites/66/2026/10/PBCSF-Appendix-2-Problem-Description-and-Competition-Rules-for-International-Track.pdf) · [Competition site](https://fintechathon.g-ican.com/)

## Capability and evidence matrix

| Area | Source implementation | Automated test coverage | Manual/live verification |
| --- | --- | --- | --- |
| Registration, login, logout, email OTP | Implemented; custom OTP flow, throttles, hashed codes, session regeneration/invalidation | Existing feature tests; latest results not independently reproduced here | SMTP delivery and browser flow pending |
| Password recovery | Implemented using existing reset-token table; single-use 15-minute code; password policy; remember-token rotation; database-session deletion | `PasswordRecoveryTest.php` exists; not executed in this audit environment | Test mailbox and browser flow pending; non-database global session revocation unsupported |
| Google OAuth | Callback validates provider identity and verified-email claim | Mocked callback tests exist | Live OAuth/client redirect pending |
| Private finance reads | Dashboard and agent reads use authenticated user relationships | Tests cover representative isolation paths | Two-account browser evidence pending |
| Agent read tools | Summary, funding opportunities, spending analysis, tuition plan/schedule, forecast, conversion and simulated remittance | `AgentToolTest.php` covers registry validation and selected executions | Live-provider behavior pending |
| Agent create | Tuition plans, savings goals, category budgets and saved planning notes use explicit proposals | Proposal/confirmation tests exist | Browser confirmation and outcome capture pending |
| Agent update | Tuition plans, savings goals and category budgets; snapshot and ownership revalidated | Update, invalid, stale and replay tests exist; new stale UI regression is not run | Browser stale-state refresh pending |
| Agent delete | Tuition plans, savings goals and category budgets only; preview and extra confirmation | Deletion, cancellation, expiry, replay and ownership tests exist | Browser deletion confirmation pending |
| Agent unsupported writes | Profile destination_country and financial_goals are now writable through the `update_profile` agent tool (allowlisted, approval-gated, ownership-scoped, before-state snapshot). Home currency, password, email and security settings remain inaccessible through the agent. Transactions/activity and funding opportunities are not writable through general agent tools. | `test_update_profile_tool_*` tests in `AgentToolTest.php`; unexpected-field rejection, empty-update rejection, persist-after-confirm and cross-user isolation covered | Browser confirmation and outcome capture pending |
| Flight Plan | Reuses user-owned goals, budgets, tuition, current-month expenses and user-entered funding; scenario cases are conditional; opportunities are never counted as confirmed cash | Finance workflow tests added for period calculations, scenario math, rent-data gaps and ownership; not run in this audit environment | Responsive browser and visual review pending |
| Provider error/privacy handling | Server-side keys; bounded tool orchestration; redacted presenter and safe provider errors | Mocked provider and redaction tests exist | Current OpenRouter/Groq connectivity pending; last recorded OpenRouter smoke attempt was HTTP 429 |
| Responsive UI, themes, accessibility | Blade, custom CSS and vanilla JS; theme preference persists; status text uses accessible regions | No browser automation package is installed; source tests do not establish rendered behavior | Desktop/mobile and screen-reader review pending |

“Tests exist” describes source coverage only. It does not mean a current run passed.

## Submission materials

- [x] Source code and `README.md` with prerequisites, local setup, test commands and deployment notes.
- [x] [Architecture and calculation documentation](ARCHITECTURE.md).
- [x] [Security self-assessment](SECURITY.md) with residual risks and verification limits.
- [x] [Eight-minute demo flow and evidence checklist](DEMO.md).
- [x] [Eight-slide presentation source and speaker script under ten minutes](PRESENTATION.md). An editable `.pptx` is not present; the Markdown is the transfer-ready source.
- [ ] Confirm official submission channel, deadline and whether demo video is required as execution evidence.
- [ ] Collect synthetic-account/sandbox operation logs and the required evidence package. Do not fabricate output or include secrets/real personal data.
- [ ] Optional demo video, maximum five minutes, unless organizers confirm it is mandatory for the selected evidence submission.
- [ ] Confirm repository access/link and deployment environment requested by the organizers.

## Current audit verification record

- `php artisan test --compact tests/Feature/AgentToolTest.php`: **77 passed** (719 assertions) — run 2026-10-18 on dev branch after all fixes.
- `php artisan test --compact`: **148 passed** (1251 assertions) — run 2026-10-18; all suites green.
- `vendor/bin/pint --dirty --format agent`: **passed** — run 2026-10-18 after final edits.
- `npm.cmd run build`: **passed** — clean Vite build 2026-10-18; output: app-DAy_pxR2.css 68.98 kB / app-B5jcsDmF.js 24.39 kB.
- `git diff --check`: **passed** — no trailing whitespace or merge-conflict markers on dev.
- `composer audit --locked`: not run in this session; no PHP registry access confirmed; carry forward prior clean state.
- `npm.cmd audit --audit-level=moderate`: npm registry audit endpoint did not respond; not a clean audit pass.
- No current browser run, SMTP delivery, live Google OAuth, live OpenRouter/Groq request, MySQL concurrency test, deployment inspection, external security review or money transfer was performed.

## Risks and product limits to disclose

- No bank connectivity, live exchange rates, named remittance providers, real transfer execution, external scholarship search/verification, continuous account monitoring or payment guarantees.
- The AI can produce incorrect prose. Deterministic tool results and explicit approvals bound supported operations, but this is not independent financial advice.
- Recovery revokes sessions only for the database session driver. No authenticated password-change screen or TOTP/MFA is implemented.
- Approval locking uses database transactions; production MySQL concurrency behavior is not established by the SQLite suite.
- SMTP, Google OAuth, selected AI-provider access/quota, production HTTPS/cookies, backups, monitoring, CORS/security headers and secret-manager configuration need deployment/manual verification.
- No browser-accessibility review, penetration test, prompt-injection red team, formal privacy review or security certification is claimed.

## Exact next checks in a PHP-enabled Windows checkout

Run from the repository root after syncing these files. Confirm the checkout and test environment before running; the PHPUnit config uses in-memory SQLite and should not be changed to a personal or production database.

```powershell
php artisan test --compact tests/Feature/AgentToolTest.php
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm.cmd run build
git diff --check
composer audit --locked
npm.cmd audit --audit-level=moderate
```

Record the actual command output, date, test count and assertion count. A failed package-registry request is “audit unavailable,” not “no vulnerabilities.”

## Manual checks before recording or submission

1. Register a fictional account using a test SMTP mailbox; verify the registration code, expiry and resend throttling without recording the OTP.
2. Configure a fictional profile, expenses in one home currency, tuition plan, savings goal, category budget and a user-entered funding entry marked awarded.
3. Confirm the Flight Plan shows the selected earliest eligible plan, dates, monthly/weekly periods, contribution math, applicable stress cases and gaps. Ensure the award is not added to funds and different currencies remain separate unless converted.
4. At desktop and mobile widths, inspect keyboard focus, labels, contrast, dark/light theme, loading/error states and no horizontal overflow.
5. In two fictional accounts, verify financial rows, conversations and pending approvals remain private to their owner.
6. Stage an update and deletion. Verify exact current/proposed values, explicit action confirmation and extra deletion confirmation. Cancel one action. Change a record between proposal and approval and verify the stale action fails, the current row remains intact, and the UI shows the failed outcome after refresh.
7. Confirm a valid proposal once, refresh and verify the saved database-backed UI state; replay the same approval and verify no second write.
8. Run the selected provider smoke test immediately before any live AI demo. If it fails, show the safe provider error or a clearly labeled mock; never claim a mocked output is live.
9. Review provider retention/terms, HTTPS `Secure`/`HttpOnly`/`SameSite` cookies, proxy trust, `APP_DEBUG=false`, secret-manager configuration, database permissions, backups and production monitoring with the deployment owner.
10. Capture only synthetic sandbox logs and approved screenshots. Confirm the event's latest deadline, submission channel and execution-evidence/video requirement with organizers.
