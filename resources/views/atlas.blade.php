<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#09111f">
    <title>Workspace · GlobeNest Atlas</title>
    @include('partials.theme-init')
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="app-page" data-currency-endpoint="{{ route('currency.convert') }}" data-remittance-endpoint="{{ route('remittance.compare') }}" data-scenario-endpoint="{{ route('scenario.run') }}" data-agent-endpoint="{{ route('agent.chat') }}" data-confirm-endpoint="{{ route('agent.confirm') }}" data-cancel-endpoint="{{ route('agent.cancel') }}" data-currency-names="{{ json_encode($currencyNames, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}">
    <div class="app-shell">
        <aside class="sidebar" id="sidebar">
            <a class="brand sidebar-brand" href="{{ route('dashboard') }}"><span class="brand-mark">A</span><span>GlobeNest <b>Atlas</b></span></a>
            <div class="workspace-label">YOUR WORKSPACE</div>
            <nav class="side-nav" aria-label="Main navigation">
                <a class="nav-item active" href="#overview"><span class="nav-icon">◫</span> Overview</a>
                <a class="nav-item" href="#readiness"><span class="nav-icon">↗</span> Resilience snapshot</a>
                <a class="nav-item" href="#assistant"><span class="nav-icon">✳</span> Atlas assistant <span class="nav-tag">AI</span></a>
                <a class="nav-item" href="#tuition"><span class="nav-icon">▤</span> Tuition planner</a>
                <a class="nav-item" href="#currency"><span class="nav-icon">↔</span> Currency &amp; transfers</a>
                <a class="nav-item" href="#simulator"><span class="nav-icon">⌁</span> Scenario lab</a>
                <a class="nav-item" href="#alerts"><span class="nav-icon">!</span> Financial alerts</a>
                <a class="nav-item" href="#activity"><span class="nav-icon">◷</span> Activity</a>
                <a class="nav-item" href="#funding"><span class="nav-icon">+</span> Funding planner</a>
                <a class="nav-item" href="#help"><span class="nav-icon">?</span> Help &amp; how to use</a>
                <a class="nav-item" href="#profile-settings"><span class="nav-icon">⚙</span> Profile &amp; settings</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-note"><span class="note-icon">i</span><span>Atlas helps you plan. It never moves money or guarantees an outcome.</span></div>
                <div class="sidebar-user"><div class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</div><div class="user-copy"><b>{{ auth()->user()->name }}</b><span>{{ auth()->user()->email }}</span></div><form method="post" action="{{ route('logout') }}" data-confirm-logout>@csrf<button class="icon-button" type="submit" aria-label="Sign out" title="Sign out">↗</button></form></div>
            </div>
        </aside>
        <main class="main-content">
            <header class="topbar"><button class="menu-toggle" type="button" data-menu-toggle aria-label="Open navigation">☰</button><div class="breadcrumb">Atlas <span>/</span> Workspace</div><div class="topbar-right"><button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch to light theme">Light</button><span class="secure-badge"><i></i> Private workspace</span><span class="today-label">{{ now()->format('D, M j') }}</span></div></header>
            <div class="content-wrap">
                @php($firstTuition = data_get($tuitionPlans->first(), 'plan'))
                @if (session('status'))
                    <div class="toast" role="status" data-toast>{{ session('status') }}<button type="button" aria-label="Dismiss" data-dismiss-toast>×</button></div>
                @endif
                @if ($errors->any())
                    <div class="alert alert-error" role="alert">{{ $errors->first() }}</div>
                @endif

                @if (!$profile)
                    <section class="onboarding-card" id="overview">
                        <div class="onboarding-mark">01</div>
                        <div class="section-heading"><div class="eyebrow">FIRST, YOUR CONTEXT</div><h1>Welcome to your financial map.</h1><p>Tell Atlas where you’re headed and what you’re planning for. This stays in your account.</p></div>
                        <form method="post" action="{{ route('profile.save') }}" class="form-grid onboarding-form">
                            @csrf @method('PUT')
                            <label>Study destination<input name="destination_country" value="{{ old('destination_country') }}" placeholder="e.g. Canada" required maxlength="80"></label>
                            <label>Home currency<select name="home_currency" required>@if (old('home_currency') === null)<option value="" selected disabled>Choose your home currency</option>@endif @foreach ($currencies as $currency)<option value="{{ $currency }}" @selected(old('home_currency') === $currency)>{{ $currency }} — {{ $currencyNames[$currency] }}</option>@endforeach</select></label>
                            <label>Monthly budget (<span class="currency-name" data-currency-name-for="home_currency" data-empty-label="Choose home currency">Choose home currency</span>, per month)<input name="monthly_budget" type="number" min="0" max="99999999.99" step="0.01" inputmode="decimal" placeholder="1800.00" value="{{ old('monthly_budget') }}" required><small class="currency-hint">Example: 1800.00 per month in your selected home currency.</small></label>
                            <label>Monthly income (<span class="currency-name" data-currency-name-for="home_currency" data-empty-label="Choose home currency">Choose home currency</span>, optional per month)<input name="monthly_income" type="number" min="0" max="99999999.99" step="0.01" inputmode="decimal" placeholder="0.00" value="{{ old('monthly_income') }}"><small class="currency-hint">Enter 0.00 if you have no monthly income to include.</small></label>
                            <label class="wide-field">What are you working toward?<textarea name="financial_goals" rows="2" maxlength="1000" placeholder="A tuition payment, emergency fund, or a more predictable monthly budget">{{ old('financial_goals') }}</textarea></label>
                            <label class="check-row wide-field"><input name="memory_consent" type="checkbox" value="1"><span>Allow Atlas to use recent messages in this workspace as context for future answers. Conversations are still saved for history; turn this off any time.</span></label>
                            <button class="button button-primary wide-field" type="submit">Set up my workspace <span aria-hidden="true">↗</span></button>
                        </form>
                    </section>
                @else
                    <section class="page-heading" id="overview">
                        <div><div class="eyebrow"><span class="live-dot"></span> YOUR FINANCIAL OVERVIEW</div><h1>A little more <span>in focus.</span></h1><p>Here’s your current picture, {{ auth()->user()->name }}. Estimates are labeled; your records stay yours.</p></div>
                        <a class="button button-quiet" href="#profile-settings">Update profile <span aria-hidden="true">↗</span></a>
                    </section>
                    <section class="metric-grid">
                        <article class="metric-card metric-primary">
                            <div>
                                <div class="metric-top"><span>AVAILABLE THIS MONTH</span><span class="metric-icon">↗</span></div>
                                <div class="metric-value">{{ $profile->home_currency }} {{ $availableFunds }}</div>
                                <div class="metric-foot">@if ($overBudget > 0) Over budget by {{ $profile->home_currency }} {{ \App\Services\CurrencyCalculator::formatMinorUnits($overBudget) }} @else {{ $profile->home_currency }} budget less same-currency recorded expenses @endif</div>
                            </div>
                            <div>
                                <div class="metric-rule"><i style="width: {{ $budgetUsagePercent }}%"></i></div>
                                <div class="metric-foot metric-bottom"><span>Monthly budget {{ $profile->home_currency }} {{ $profile->monthly_budget }}</span> <span>Study destination: {{ $profile->destination_country }} · Home currency: {{ $profile->home_currency }}</span></div>
                            </div>
                        </article>
                        <article class="metric-card">
                            <div>
                                <div class="metric-top"><span>SPENT THIS MONTH</span><span class="metric-icon">⌁</span></div>
                                <div class="metric-value">{{ $profile->home_currency }} {{ $monthlySpending }}</div>
                                <div class="metric-foot">Recorded expenses in your home currency</div>
                            </div>
                            <div class="metric-mini"><span class="mini-dot green"></span> {{ $categoryBreakdown->count() }} spending {{ \Illuminate\Support\Str::plural('category', $categoryBreakdown->count()) }} tracked</div>
                        </article>
                        <article class="metric-card">
                            <div>
                                <div class="metric-top"><span>TUITION PLANS</span><span class="metric-icon">▤</span></div>
                                <div class="metric-value">{{ $tuitionPlans->count() }} <small>{{ \Illuminate\Support\Str::plural('plan', $tuitionPlans->count()) }}</small></div>
                                <div class="metric-foot">{{ $firstTuition?->due_date?->format('M j, Y') ?? 'Add a tuition deadline to begin' }}</div>
                            </div>
                            <a class="metric-link" href="#tuition">View tuition plan <span>→</span></a>
                        </article>
                        <article class="metric-card">
                            <div>
                                <div class="metric-top"><span>SAVINGS GOALS</span><span class="metric-icon">◎</span></div>
                                <div class="metric-value">{{ $goals->count() }} <small>{{ \Illuminate\Support\Str::plural('goal', $goals->count()) }}</small></div>
                                <div class="metric-foot">{{ $goals->first()['goal']->title ?? 'Small steps, clearly measured' }}</div>
                            </div>
                            <a class="metric-link" href="#goals">View savings goals <span>→</span></a>
                        </article>
                    </section>
                @endif

                <section class="panel resilience-panel" id="readiness">
                    <div class="panel-heading"><div><div class="eyebrow">ATLAS STUDENT FINANCIAL FLIGHT PLAN · EVIDENCE FROM YOUR RECORDS</div><h2>Student Financial Flight Plan</h2><p>Cross-border financial resilience, planned from your saved goals, obligations and current-month activity. As of {{ $readinessSnapshot['as_of'] }}. Potential awards are not counted as available money.</p></div><span class="tool-symbol">↗</span></div>
                    <div class="resilience-grid">
                        <article class="resilience-card">
                            <h3>Tuition gaps &amp; deadlines</h3>
                            @if ($readinessSnapshot['tuition'] === [])
                                <p class="resilience-empty">No tuition plans are recorded. Add a plan to calculate the outstanding amount and savings pace.</p>
                            @else
                                @foreach ($readinessSnapshot['tuition'] as $item)
                                    <div class="resilience-record">
                                        <b>{{ $item['title'] }}</b>
                                        <span>Outstanding: {{ $item['currency'] }} — {{ $currencyNames[$item['currency']] }} {{ $item['remaining'] }}</span>
                                        <span>Deadline: {{ $item['due_date'] }} · {{ $item['due_in_days'] }} days · @if ($item['status'] === 'funded') recorded savings cover the total @elseif ($item['status'] === 'overdue') passed with a remaining balance @elseif ($item['status'] === 'due_today') today with a remaining balance @else future date; affordability is assessed separately @endif</span>
                                        <span>Saved so far: {{ $item['currency'] }} {{ $item['saved'] }} of {{ $item['currency'] }} {{ $item['total'] }}.</span>
                                        <span>Required pace: {{ $item['currency'] }} {{ $item['required_monthly'] }} across {{ $item['months_available'] }} monthly periods, or {{ $item['currency'] }} {{ $item['required_weekly'] }} across {{ $item['weeks_available'] }} weekly periods.</span>
                                        <details class="resilience-evidence"><summary>Evidence &amp; calculation</summary><p>{{ $item['formula'] }}</p><p>Recorded total {{ $item['currency'] }} {{ $item['total'] }} − recorded savings {{ $item['currency'] }} {{ $item['saved'] }}.</p></details>
                                    </div>
                                @endforeach
                            @endif
                        </article>
                        <article class="resilience-card">
                            <h3>Monthly budget &amp; currency exposure</h3>
                            @if ($readinessSnapshot['budget'] === null)
                                <p class="resilience-empty">No saved profile budget is available. Set a home currency and monthly budget to compare records.</p>
                            @else
                                <div class="resilience-record">
                                    <b>{{ $readinessSnapshot['budget']['currency'] }} — {{ $currencyNames[$readinessSnapshot['budget']['currency']] }}</b>
                                    <span>Monthly budget: {{ $readinessSnapshot['budget']['currency'] }} {{ $readinessSnapshot['budget']['monthly_budget'] }}</span>
                                    @if ($readinessSnapshot['budget']['monthly_income'] === null)
                                        <span>Expected monthly income is not recorded; no income-delay stress scenario is calculated.</span>
                                    @else
                                        <span>Saved expected monthly income: {{ $readinessSnapshot['budget']['currency'] }} {{ $readinessSnapshot['budget']['monthly_income'] }} (already included in the monthly budget assumption).</span>
                                    @endif
                                    <span>Recorded expenses this month: {{ $readinessSnapshot['budget']['currency'] }} {{ $readinessSnapshot['budget']['recorded_expenses'] }}</span>
                                    <span>@if ($readinessSnapshot['budget']['over_budget'] !== '0.00') Over budget by {{ $readinessSnapshot['budget']['currency'] }} {{ $readinessSnapshot['budget']['over_budget'] }} @else Remaining within budget: {{ $readinessSnapshot['budget']['currency'] }} {{ $readinessSnapshot['budget']['remaining'] }} @endif</span>
                                    <details class="resilience-evidence"><summary>Evidence &amp; calculation</summary><p>{{ $readinessSnapshot['budget']['formula'] }}</p><p>{{ $readinessSnapshot['budget']['expense_count'] }} same-currency expense {{ \Illuminate\Support\Str::plural('record', $readinessSnapshot['budget']['expense_count']) }} counted. Foreign-currency activity is kept separate.</p></details>
                                </div>
                            @endif
                            <div class="resilience-record" id="budgets">
                                <b>Active monthly category limits</b>
                                @if ($readinessSnapshot['monthly_budgets'] === [])
                                    <span>No active category budgets are available. Category limits are subsets of spending and are not subtracted a second time.</span>
                                @else
                                    @foreach ($readinessSnapshot['monthly_budgets'] as $categoryBudget)
                                        <span>{{ $categoryBudget['category'] }}: {{ $categoryBudget['currency'] }} {{ $categoryBudget['spent'] }} recorded against {{ $categoryBudget['currency'] }} {{ $categoryBudget['limit'] }} ({{ $categoryBudget['period_start'] }} to {{ $categoryBudget['period_end'] }}).</span>
                                        @if ($categoryBudget['over_budget'] !== '0.00')<small>Recorded amount is {{ $categoryBudget['currency'] }} {{ $categoryBudget['over_budget'] }} over this category limit.</small>@else<span>Remaining category limit: {{ $categoryBudget['currency'] }} {{ $categoryBudget['remaining'] }}.</span>@endif
                                    @endforeach
                                @endif
                            </div>
                            <div class="resilience-record">
                                <b>Foreign-currency tuition</b>
                                @if ($readinessSnapshot['home_currency'] === null)
                                    <span>Set a home currency to identify and convert foreign-currency tuition obligations.</span>
                                @elseif ($readinessSnapshot['currency_exposure'] === [])
                                    <span>No outstanding tuition plan in a currency different from your home currency.</span>
                                @else
                                    @foreach ($readinessSnapshot['currency_exposure'] as $exposure)
                                        <span>{{ $exposure['title'] }}: {{ $exposure['currency'] }} {{ $exposure['remaining'] }} outstanding; estimated {{ $readinessSnapshot['home_currency'] }} {{ $exposure['home_equivalent'] }} equivalent.</span>
                                        <small>Rate {{ $exposure['conversion']['rate'] }} {{ $exposure['conversion']['to_currency'] }} per {{ $exposure['conversion']['from_currency'] }} · {{ $exposure['conversion']['source'] }} · read {{ $exposure['conversion']['retrieved_at'] }}. Not a live market rate.</small>
                                    @endforeach
                                @endif
                            </div>
                        </article>
                        <article class="resilience-card">
                            <h3>Savings goals &amp; funding milestones</h3>
                            @if ($readinessSnapshot['savings_goals'] === [])
                                <p class="resilience-empty">No savings goals are recorded.</p>
                            @else
                                @foreach ($readinessSnapshot['savings_goals'] as $goal)
                                    <div class="resilience-record">
                                        <b>{{ $goal['title'] }}</b>
                                        <span>Saved {{ $goal['currency'] }} {{ $goal['saved'] }} of {{ $goal['currency'] }} {{ $goal['target'] }}; {{ $goal['currency'] }} {{ $goal['remaining'] }} remaining.</span>
                                        <span>Progress: {{ $goal['progress'] }}% · Target date: {{ $goal['target_date'] ?? 'not set' }}.</span>
                                    </div>
                                @endforeach
                            @endif
                            @if ($readinessSnapshot['funding_opportunities'] === [])
                                <div class="resilience-record"><b>Funding deadlines</b><span>No user-entered funding opportunities are recorded.</span></div>
                            @else
                                <div class="resilience-record">
                                    <b>User-entered funding deadlines</b>
                                    @foreach ($readinessSnapshot['funding_opportunities'] as $opportunity)
                                        <span>{{ $opportunity['title'] }} · {{ $opportunity['provider'] }} · deadline {{ $opportunity['deadline'] ?? 'not provided' }} · status {{ str_replace('_', ' ', $opportunity['status']) }}.</span>
                                        <small>Potential value: {{ $opportunity['potential_amount'] === null ? 'not provided' : $opportunity['currency'].' '.$opportunity['potential_amount'] }}. Not counted as confirmed funding.</small>
                                    @endforeach
                                    <a href="#funding">Review funding entries and their source details</a>
                                </div>
                            @endif
                            <p class="resilience-note">Funding entries are user-entered and unverified; even an entry marked awarded is not included in this projection.</p>
                        </article>
                        <article class="resilience-card resilience-projection">
                            <h3>Baseline and stress-test outlook</h3>
                            @if (!$readinessSnapshot['projection']['available'])
                                <p class="resilience-empty">Insufficient data for a baseline forecast.</p>
                                <ul>@foreach ($readinessSnapshot['projection']['missing'] as $missing)<li>{{ $missing }}</li>@endforeach</ul>
                            @else
                                @if ($readinessSnapshot['projection']['first_shortfall_month'] === null)
                                    <b>No monthly cash-flow or scheduled tuition-contribution shortfall is projected in the next {{ $readinessSnapshot['projection']['months'] }} months.</b>
                                @else
                                    <b>First projected financial shortfall: {{ $readinessSnapshot['projection']['first_shortfall_type'] }} in month {{ $readinessSnapshot['projection']['first_shortfall_month'] }}.</b>
                                @endif
                                <p>Baseline ending net cash flow: {{ $readinessSnapshot['projection']['currency'] }} {{ $readinessSnapshot['projection']['projected_cash_flow'] }} · tuition shortfall at horizon: {{ $readinessSnapshot['projection']['currency'] }} {{ $readinessSnapshot['projection']['tuition_shortfall'] }}.</p>
                                <p>As of {{ $readinessSnapshot['projection']['as_of'] }}: {{ $readinessSnapshot['projection']['monthly_capacity'] }} {{ $readinessSnapshot['projection']['currency'] }} remains per month after recorded expenses, against a scheduled contribution of {{ $readinessSnapshot['projection']['required_monthly'] }}. Contribution gap: {{ $readinessSnapshot['projection']['currency'] }} {{ $readinessSnapshot['projection']['monthly_contribution_gap'] }} per month.</p>
                                <div class="flight-plan-scenarios" aria-label="Baseline and hypothetical scenario comparisons">
                                    @foreach ($readinessSnapshot['projection']['scenarios'] as $scenario)
                                        <article class="flight-plan-scenario">
                                            <h4>{{ $scenario['name'] }}</h4>
                                            <p>{{ $scenario['change'] }}</p>
                                            <span>Tuition shortfall: {{ $readinessSnapshot['projection']['currency'] }} {{ $scenario['tuition_shortfall'] }} · change {{ $scenario['shortfall_change'] }}.</span>
                                            <span>Ending cash flow: {{ $readinessSnapshot['projection']['currency'] }} {{ $scenario['projected_cash_flow'] }} · change {{ $scenario['cash_flow_change'] }}.</span>
                                            <span>First shortfall: {{ $scenario['first_shortfall_month'] === null ? 'none projected in this horizon' : 'month '.$scenario['first_shortfall_month'] }}.</span>
                                        </article>
                                    @endforeach
                                </div>
                                @if ($readinessSnapshot['projection']['recommendations'] !== [])
                                    <div class="flight-plan-recommendations">
                                        <h4>Suggested next steps</h4>
                                        <ol>
                                            @foreach ($readinessSnapshot['projection']['recommendations'] as $recommendation)
                                                <li><b>{{ $recommendation['title'] }}</b><span>{{ $recommendation['detail'] }}</span><a href="{{ $recommendation['href'] }}">Review this area</a></li>
                                            @endforeach
                                        </ol>
                                    </div>
                                @endif
                                <details class="resilience-evidence"><summary>Evidence, calculation &amp; assumptions</summary><p>{{ $readinessSnapshot['projection']['formula'] }}</p><p>Plan: {{ $readinessSnapshot['projection']['plan_title'] }} · horizon: {{ $readinessSnapshot['projection']['months'] }} monthly periods and {{ $readinessSnapshot['projection']['weeks'] }} weekly periods · scheduled contribution {{ $readinessSnapshot['projection']['currency'] }} {{ $readinessSnapshot['projection']['required_monthly'] }} per month.</p><ul>@foreach ($readinessSnapshot['projection']['assumptions'] as $assumption)<li>{{ $assumption }}</li>@endforeach</ul><p>Tuition savings conversion: {{ $readinessSnapshot['projection']['conversion']['from_currency'] }} to {{ $readinessSnapshot['projection']['conversion']['to_currency'] }} at {{ $readinessSnapshot['projection']['conversion']['rate'] }} · {{ $readinessSnapshot['projection']['conversion']['source'] }} · read {{ $readinessSnapshot['projection']['conversion']['retrieved_at'] }}.</p></details>
                            @endif
                            @if ($readinessSnapshot['information_gaps'] !== [])
                                <details class="resilience-evidence"><summary>Information gaps and untested assumptions</summary><ul>@foreach ($readinessSnapshot['information_gaps'] as $gap)<li>{{ $gap }}</li>@endforeach</ul></details>
                            @endif
                            <p class="resilience-note">Estimates repeat saved inputs; they are not predictions. Ask Atlas to discuss this Flight Plan or prepare a supported draft; no change is saved without your exact approval. <a href="#assistant">Discuss this flight plan with Atlas</a></p>
                        </article>
                    </div>
                </section>

                <section class="panel help-guide" id="help">
                    <div class="panel-heading"><div><div class="eyebrow">HELP &amp; FAQ · HOW TO USE ATLAS</div><h2>Start with a clear picture</h2><p>Atlas is a private planning workspace built from information you enter. It does not connect to banks, move money, or guarantee outcomes.</p></div><span class="tool-symbol">?</span></div>
                    <div class="first-time-guide" data-first-time-guide>
                        <div class="guide-heading"><b>First-time guide</b><button class="text-button" type="button" data-dismiss-guide>Dismiss this guide</button></div>
                        <ol>
                            <li>After registering or signing in, start with <a href="#profile-settings">Profile &amp; settings</a>: choose your destination and home currency, then enter a monthly budget and optional monthly income.</li>
                            <li>Add a <a href="#tuition">tuition plan</a>, savings goal, or category budget. Each amount is tied to an explicit currency and supports up to two decimal places.</li>
                            <li>Use <a href="#activity">Financial activity</a> for manual records only. Atlas does not import bank data or move money.</li>
                            <li>Explore the <a href="#currency">currency and transfer estimates</a>, <a href="#simulator">scenario lab</a>, or <a href="#assistant">AI assistant</a>. Check the visible assumptions and limitations.</li>
                        </ol>
                    </div>
                    <div class="help-topics">
                        <details><summary>Register, sign in, navigate, and manage your profile</summary><p>Create an account from the landing page with your name, email, and a password of at least 12 characters that includes uppercase and lowercase letters, a number, and a special character. Verify your email with the code sent during registration. Sign in with the same email and password, and use Sign out to end your session. The left navigation jumps to each workspace section. Under Profile &amp; settings, enter your study destination, home currency, monthly budget and optional monthly income. Example: <b>CAD — Canadian dollar</b>, <b>1800.00 CAD per month</b>.</p></details>
                        <details><summary>Tuition timeline, savings goals, and budgets</summary><p>For tuition, enter a plan name, full amount, saved amount, currency, due date and installment count (1–24). Example: <b>12000.00 USD</b> annual tuition and <b>2000.00 USD</b> already saved, due in 10 months. The schedule estimates monthly/weekly savings and equal installments; it is not an institution invoice. A goal has a target, current savings, currency and optional date. Category budgets are monthly limits with a selected currency. Progress entries update your planning records only; they cannot exceed the target and do not move money.</p></details>
                        <details><summary>Currency converter and transfer estimates</summary><p>Select an amount, source and destination currency explicitly; for example, <b>500.00 USD (United States dollar) to EUR (Euro)</b>. The converter uses Atlas’s fixed demonstration-rate table, displays the conversion rate, table source and calculation time. The time is when Atlas read its local table, not when a market rate was published. Transfer estimates deduct illustrative fees in the source currency before converting the recipient amount. The fee profiles and delivery windows are simulated—not provider offers, live rates or guarantees—and neither tool sends money.</p></details>
                        <details><summary>Scenario lab and forecasts</summary><p>Enter monthly budget, spending, rent, income and expected scholarship in your profile’s home currency, or choose the forecast budget currency if you have no profile. Enter tuition target, saved amount and fee in the selected tuition source currency; then choose a destination currency, due date and installment count. Example: <b>1800.00 CAD per month</b> budget, <b>900.00 CAD per month</b> spending and <b>12000.00 USD</b> tuition. Favorable, baseline and adverse results are deterministic estimates; rent changes, income delays, FX stress and unconfirmed scholarship timing are assumptions, not predictions or verified funds.</p></details>
                        <details><summary>Activity, funding planner, and action log</summary><p>Activity records need a type, category, description, date, amount and selected currency. Example: <b>800.00 CAD monthly rent</b>. Filter by income or expense; manual and simulated records are labeled, and amounts in different currencies are kept separate. Funding entries are opportunities you found yourself: provider, potential value and currency, deadline, status and source link. Atlas does not search or verify eligibility or awards. Saved plans are user-approved notes. The action log stores tool names and outcomes, not action arguments.</p></details>
                        <details><summary>AI assistant, confirmation, loading, and errors</summary><p>Ask a specific question with its currency, such as “Convert 500 USD to EUR and explain the rate source.” The assistant can read only your account’s records. To change a saved goal or tuition plan, it first prepares a draft showing the affected record, current and proposed values, currency and expected change. Only the explicit Confirm action authorizes that exact change in your Atlas account; Not now discards it. Confirmation never authorizes a payment, bank transfer, remittance, or future action. Drafts expire after 15 minutes and must be confirmed separately. While a request is running, wait for its result; validation messages identify rejected input, while provider/network errors mean the assistant is unavailable and do not imply a change was saved.</p></details>
                        <details><summary>Privacy, security, and common questions</summary><p>The configured AI provider ({{ $aiProviderName }}) receives the current chat message and tool results when AI is configured. Recent message history is sent only when you opt in to memory. Never paste API keys, passwords, card numbers, account credentials or identity documents into chat. Atlas is a planning prototype, not financial, tax, immigration or legal advice; verify important decisions with a qualified professional. Rates, fee examples, forecasts, and manually entered funding information are not guaranteed or verified. Atlas makes no real payments. Registration email verification is available, but password recovery/change and login MFA (including authenticator apps) are not implemented. Google sign-in is not login MFA; use a unique password and keep your login details safe.</p></details>
                    </div>
                </section>

                <section class="panel alerts-panel" id="alerts">
                    <div class="panel-heading"><div><div class="eyebrow">EARLY WARNINGS · CURRENT RECORDS ONLY</div><h2>Financial signals</h2><p>Calculated when you open this page from the plans, budget, and activity you entered. Atlas does not monitor continuously.</p></div><span class="tool-symbol">!</span></div>
                    @if ($financialAlerts === [])
                        <div class="empty-state compact"><b>No current warning from your recorded plans.</b><p>This is not a guarantee; incomplete or unrecorded expenses and funding sources are not included.</p></div>
                    @else
                        <div class="financial-alert-list">
                            @foreach ($financialAlerts as $alert)
                                <article class="financial-alert {{ $alert['level'] }}">
                                    <span class="alert-mark" aria-hidden="true">{{ $alert['level'] === 'urgent' ? '!' : 'i' }}</span>
                                    <div><b>{{ $alert['title'] }}</b><p>{{ $alert['detail'] }}</p></div>
                                    <time datetime="{{ $alert['date'] }}">{{ \Illuminate\Support\Carbon::parse($alert['date'])->format('M j') }}</time>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>

                <div class="dashboard-grid">
                    <section class="panel spending-panel">
                        <div class="panel-heading"><div><div class="eyebrow">MONTHLY SIGNALS</div><h2>Where it’s going</h2></div><span class="subtle-pill">{{ now()->format('F') }}</span></div>
                        @if ($categoryBreakdown->isEmpty())
                            <div class="empty-state"><span class="empty-icon">⌁</span><b>Your spending picture starts here.</b><p>Record an expense below to see categories take shape. We never invent activity.</p><a href="#activity">Add a record <span>→</span></a></div>
                        @else
                            <div class="spending-bars">@foreach ($categoryBreakdown as $item)<div class="bar-row"><div class="bar-label"><span>{{ $item['category'] }}</span><b>{{ $profile?->home_currency ?? '' }} {{ $item['amount'] }}</b></div><div class="bar-track"><i style="width: {{ $item['share'] }}%"></i></div></div>@endforeach</div>
                            <p class="data-caption">Same-currency expenses recorded this month. Other currencies are shown separately.</p>
                        @endif
                    </section>
                    <section class="panel currency-panel">
                        <div class="panel-heading"><div><div class="eyebrow">ACROSS BORDERS</div><h2>Currency picture</h2></div><a class="panel-link" href="#currency">Convert <span>→</span></a></div>
                        @if ($currencyBreakdown->isEmpty())
                            <div class="empty-state compact"><span class="empty-icon">↔</span><b>No activity recorded yet.</b><p>Your balances stay empty until you add your own records.</p></div>
                        @else
                            <div class="currency-list">@foreach ($currencyBreakdown as $item)<div class="currency-line"><span class="currency-chip">{{ $item['currency'] }}</span><div><b>{{ $item['currency'] }} {{ $item['amount'] }}</b><small>{{ $item['kind'] }} this month</small></div><span class="currency-arrow">↗</span></div>@endforeach</div>
                        @endif
                    </section>
                    <section class="panel tuition-panel" id="tuition">
                        <div class="panel-heading"><div><div class="eyebrow">PLAN AHEAD</div><h2>Tuition timeline</h2></div><button class="text-button" type="button" data-open-form="tuition-form" aria-controls="tuition-form" aria-expanded="false">+ Add deadline</button></div>
                        @if ($tuitionPlans->isEmpty())
                            <div class="empty-state compact"><span class="empty-icon">▤</span><b>No tuition plans yet.</b><p>Add a due date to calculate a weekly savings target.</p></div>
                        @else
                            <div class="commitment-list">@foreach ($tuitionPlans as $item)<article class="commitment"><div class="commitment-date"><b>{{ $item['plan']->due_date->format('d') }}</b><small>{{ $item['plan']->due_date->format('M') }}</small></div><div class="commitment-info"><b>{{ $item['plan']->title }}</b><span>{{ $item['plan']->currency }} {{ $item['plan']->amount }} · {{ $item['plan']->installments }} {{ \Illuminate\Support\Str::plural('installment', $item['plan']->installments) }}</span><div class="commitment-progress"><i style="width: {{ $item['progress'] }}%"></i></div><small>{{ $item['plan']->currency }} {{ $item['schedule']['required_monthly'] }}/month needed · {{ $item['schedule']['due_in_days'] }} days left</small><details class="installment-detail"><summary>View generated monthly installment dates</summary><div>@foreach ($item['installments'] as $installment)<p><span>Installment {{ $installment['number'] }} · {{ \Illuminate\Support\Carbon::parse($installment['due_date'])->format('M j, Y') }}</span><b>{{ $item['plan']->currency }} {{ $installment['amount'] }}</b><small>{{ $item['plan']->currency }} {{ $installment['saved'] }} saved · {{ $item['plan']->currency }} {{ $installment['remaining'] }} remaining · {{ $installment['status'] }}</small></p>@endforeach</div><small>Equal monthly estimates ending on the selected deadline; saved progress is allocated to earlier installments first.</small></details><details class="progress-update"><summary>Record savings progress</summary><form method="post" action="{{ route('tuition.progress', $item['plan']) }}">@csrf<label>Additional amount saved ({{ $item['plan']->currency }} — {{ $currencyNames[$item['plan']->currency] }})<input name="additional_amount" type="number" min="0.01" max="99999999.99" step="0.01" required placeholder="100.00"></label><button class="button button-outline" type="submit">Update progress</button><small>Manual planning entry only. Atlas does not move money.</small></form></details></div><span class="status-dot {{ $item['schedule']['status'] === 'funded' ? 'done' : (in_array($item['schedule']['status'], ['overdue', 'due_today'], true) ? 'urgent' : '') }}"></span></article>@endforeach</div>
                        @endif
                        <form method="post" action="{{ route('tuition.store') }}" class="inline-form hidden-form" id="tuition-form">
                            @csrf
                            <div class="form-grid"><label>Payment name<input name="title" required maxlength="120" placeholder="Fall semester tuition"></label><label>Tuition amount (<span class="currency-name" data-currency-name-for="currency">Choose a currency</span>, total for this plan)<input name="amount" type="number" min="0.01" max="99999999.99" step="0.01" required placeholder="12000.00"></label><label>Already saved (<span class="currency-name" data-currency-name-for="currency">Choose a currency</span>, toward this plan)<input name="saved_amount" type="number" min="0" max="99999999.99" step="0.01" placeholder="0.00"></label><label>Tuition currency<select name="currency" required>@if (old('currency', $profile?->home_currency) === null)<option value="" selected disabled>Choose a currency</option>@endif @foreach ($currencies as $currency)<option value="{{ $currency }}" @selected(old('currency', $profile?->home_currency) === $currency)>{{ $currency }} — {{ $currencyNames[$currency] }}</option>@endforeach</select></label><label>Due date<input name="due_date" type="date" min="{{ today()->toDateString() }}" required></label><label>Installments (count)<input name="installments" type="number" min="1" max="24" value="1" required></label></div>
                            <button class="button button-primary" type="submit">Add tuition plan</button>
                        </form>
                    </section>
                    <section class="panel goals-panel" id="goals">
                        <div class="panel-heading"><div><div class="eyebrow">YOUR NEXT MILESTONE</div><h2>Savings goals</h2></div><button class="text-button" type="button" data-open-form="goal-form" aria-controls="goal-form" aria-expanded="false">+ Add goal</button></div>
                        @if ($goals->isEmpty())
                            <div class="empty-state compact"><span class="empty-icon">◎</span><b>Make room for a goal.</b><p>Set a target and track the progress you choose to record.</p></div>
                        @else
                            <div class="goals-list">@foreach ($goals as $item)<article class="goal-item"><div class="goal-heading"><b>{{ $item['goal']->title }}</b><span>{{ $item['progress'] }}%</span></div><div class="goal-track"><i style="width: {{ $item['progress'] }}%"></i></div><div class="goal-meta"><span>{{ $item['goal']->currency }} {{ $item['goal']->current_amount }} saved</span><span>of {{ $item['goal']->currency }} {{ $item['goal']->target_amount }}</span></div><details class="progress-update"><summary>Record savings progress</summary><form method="post" action="{{ route('goals.progress', $item['goal']) }}">@csrf<label>Additional amount saved ({{ $item['goal']->currency }} — {{ $currencyNames[$item['goal']->currency] }})<input name="additional_amount" type="number" min="0.01" max="99999999.99" step="0.01" required placeholder="50.00"></label><button class="button button-outline" type="submit">Update progress</button><small>Manual planning entry only. Atlas does not move money.</small></form></details></article>@endforeach</div>
                        @endif
                        <form method="post" action="{{ route('goals.store') }}" class="inline-form hidden-form" id="goal-form">
                            @csrf
                            <div class="form-grid"><label>Goal name<input name="title" required maxlength="120" placeholder="Emergency fund"></label><label>Target amount (<span class="currency-name" data-currency-name-for="currency">Choose a currency</span>)<input name="target_amount" type="number" min="0.01" max="99999999.99" step="0.01" required placeholder="1500.00"></label><label>Already saved (<span class="currency-name" data-currency-name-for="currency">Choose a currency</span>)<input name="current_amount" type="number" min="0" max="99999999.99" step="0.01" placeholder="0.00"></label><label>Goal currency<select name="currency" required>@if (old('currency', $profile?->home_currency) === null)<option value="" selected disabled>Choose a currency</option>@endif @foreach ($currencies as $currency)<option value="{{ $currency }}" @selected(old('currency', $profile?->home_currency) === $currency)>{{ $currency }} — {{ $currencyNames[$currency] }}</option>@endforeach</select></label><label class="wide-field">Target date (optional)<input name="target_date" type="date" min="{{ today()->toDateString() }}"></label></div>
                            <button class="button button-primary" type="submit">Save goal</button>
                        </form>
                    </section>
                </div>

                <section class="panel assistant-panel" id="assistant">
                    <div class="assistant-header"><div class="assistant-mark">✳</div><div><div class="eyebrow">ATLAS AI · {{ $aiProviderName }} · {{ $aiModel }}</div><h2>Think it through, together.</h2><p>Ask about your budget, a currency conversion or a tuition plan. Actions that change records always wait for your confirmation.</p></div><span class="ai-status {{ !$aiProviderSupported || !$aiKeyConfigured || $aiProviderNeedsFixedModel ? 'needs-key' : 'unverified' }}"><i></i> {{ !$aiProviderSupported ? 'Unsupported provider' : (!$aiKeyConfigured ? 'Needs server API key' : ($aiProviderNeedsFixedModel ? 'Needs a fixed model ID' : 'Key configured · connection not verified')) }}</span></div>
                    <section class="approval-center" aria-labelledby="approval-center-title">
                        <div class="approval-heading"><div><div class="eyebrow">TRUST &amp; PERMISSIONS</div><h3 id="approval-center-title">Pending approvals</h3><p>Review a proposed change before it is saved. Drafts expire after 15 minutes.</p></div><span class="subtle-pill">{{ $pendingActions->count() }} pending</span></div>
                        @if ($pendingActions->isEmpty())
                            <p class="approval-empty">No pending changes. Atlas can read only your own records; saving a planning change always requires your explicit confirmation.</p>
                        @else
                            <div class="pending-approval-list">
                                @foreach ($pendingActions as $action)
                                    <article class="pending-approval" data-pending-approval>
                                        <div><b>{{ \Illuminate\Support\Str::headline($action->tool_name) }}</b><small>Draft for conversation #{{ $action->conversation_id }} · expires {{ $action->expires_at->diffForHumans() }}</small></div>
                                        <div class="pending-action-summary" data-pending-action="{{ json_encode(['id' => $action->id, 'name' => $action->tool_name, 'arguments' => $action->arguments, 'before_state' => $action->before_state], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}"></div>
                                        <div class="approval-actions">
                                            <button class="button button-primary" type="button" aria-label="{{ str_starts_with($action->tool_name, 'delete_') ? 'Confirm deletion of this record' : 'Confirm and save '.\Illuminate\Support\Str::headline($action->tool_name) }}" data-approve-pending="{{ $action->id }}" data-conversation-id="{{ $action->conversation_id }}">{{ str_starts_with($action->tool_name, 'delete_') ? 'Confirm deletion' : 'Confirm and save' }}</button>
                                            <button class="button button-quiet" type="button" aria-label="Discard {{ \Illuminate\Support\Str::headline($action->tool_name) }}" data-reject-pending="{{ $action->id }}" data-conversation-id="{{ $action->conversation_id }}">Not now</button>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </section>
                    <div class="assistant-layout">
                        <aside class="conversation-rail">
                            <div class="rail-heading"><span>RECENT CONVERSATIONS</span><button type="button" data-new-chat aria-label="Start a new conversation">+</button></div>
                            <div class="conversation-list">
                                @forelse ($conversations as $conversation)
                                    <div class="conversation-row {{ $selectedConversation?->id === $conversation->id ? 'selected' : '' }}" data-conversation-row="{{ $conversation->id }}">
                                        <button class="conversation-item {{ $selectedConversation?->id === $conversation->id ? 'selected' : '' }}" type="button" data-load-conversation="{{ $conversation->id }}">
                                            <b>{{ \Illuminate\Support\Str::limit($conversation->title, 28) }}</b>
                                            <small>{{ $conversation->updated_at->diffForHumans() }}</small>
                                        </button>
                                        <button class="conversation-delete-btn" type="button" data-delete-conversation="{{ $conversation->id }}" title="Delete chat" aria-label="Delete chat">×</button>
                                    </div>
                                @empty
                                    <p class="conversation-empty">Your saved conversations will appear here.</p>
                                @endforelse
                            </div>
                        </aside>
                        <div class="chat-workspace">
                            <div class="chat-messages" id="chat-messages" aria-live="polite" data-conversation-id="{{ $selectedConversation?->id ?? '' }}">
                                @if ($selectedConversation)
                                    @foreach ($selectedConversation->messages as $message)
                                        <article class="chat-message {{ $message->role === 'assistant' ? 'assistant-message' : 'user-message' }}" @if ($message->role === 'assistant') data-display-tool-results="{{ json_encode($message->display_tool_result ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}" @endif><span class="message-avatar">{{ $message->role === 'assistant' ? 'A' : mb_substr(auth()->user()->name, 0, 1) }}</span><div><b>{{ $message->role === 'assistant' ? 'Atlas' : 'You' }}</b><p>{{ $message->content }}</p></div></article>
                                    @endforeach
                                @else
                                    <div class="chat-welcome"><span class="welcome-spark">✳</span><h3>What’s on your mind?</h3><p>Start with a question, or try one of these:</p><div class="prompt-chips"><button type="button" data-prompt="Help me understand my current budget and spending.">Review my budget</button><button type="button" data-prompt="Convert 500 USD to EUR and explain the rate source.">Convert 500 USD to EUR</button><button type="button" data-prompt="How should I think about saving for tuition?">Plan for tuition</button></div></div>
                                @endif
                            </div>
                            <form class="chat-composer" id="chat-form"><label class="sr-only" for="chat-input">Message Atlas</label><textarea id="chat-input" rows="1" maxlength="4000" placeholder="Ask Atlas about a financial decision…" required></textarea><button type="submit" aria-label="Send message">↑</button><div class="composer-note"><span>Enter to send · Shift + Enter for a new line</span><span>Never share passwords or account credentials</span></div></form>
                        </div>
                    </div>
                    <div class="assistant-disclaimer">Atlas is an AI planning tool, not financial, tax, immigration or legal advice. Check important decisions with a qualified professional.</div>
                </section>

                <div class="tools-grid" id="currency">
                    <section class="panel tool-panel">
                        <div class="panel-heading"><div><div class="eyebrow">CLEAR MATH, CLEAR SOURCE</div><h2>Currency converter</h2></div><span class="tool-symbol">↔</span></div>
                        <form class="form-grid tool-form" id="convert-form">
                            <label>Amount to convert (<span class="currency-name" data-currency-name-for="from_currency" data-empty-label="Choose source currency">Choose source currency</span>)<input name="amount" type="number" min="0.01" max="99999999.99" step="0.01" value="500.00" required></label>
                            <label>From currency<select name="from_currency" required>@if (!$profile)<option value="" selected disabled>Choose source currency</option>@endif @foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($profile?->home_currency === $currency)>{{ $currency }} — {{ $currencyNames[$currency] }}</option>@endforeach</select></label>
                            <label>To currency<select name="to_currency" required><option value="" selected disabled>Choose destination currency</option>@foreach ($currencies as $currency)<option value="{{ $currency }}">{{ $currency }} — {{ $currencyNames[$currency] }}</option>@endforeach</select></label>
                            <button class="button button-primary wide-field" type="submit">Calculate estimate <span>→</span></button>
                        </form>
                        <div class="result-box hidden" id="convert-result" aria-live="polite"></div>
                        <p class="data-caption">Atlas fixed demonstration-rate table; not a live market feed. Results show the source and calculation time, not a market-rate publication time. No money is exchanged.</p>
                    </section>
                    <section class="panel tool-panel">
                        <div class="panel-heading"><div><div class="eyebrow">COMPARE THE ASSUMPTIONS</div><h2>Transfer estimates</h2></div><span class="tool-symbol">⌁</span></div>
                        <form class="form-grid tool-form" id="remittance-form">
                            <label>Send amount (<span class="currency-name" data-currency-name-for="from_currency" data-empty-label="Choose source currency">Choose source currency</span>)<input name="amount" type="number" min="0.01" max="99999999.99" step="0.01" value="500.00" required></label>
                            <label>From currency<select name="from_currency" required>@if (!$profile)<option value="" selected disabled>Choose source currency</option>@endif @foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($profile?->home_currency === $currency)>{{ $currency }} — {{ $currencyNames[$currency] }}</option>@endforeach</select></label>
                            <label>To currency<select name="to_currency" required><option value="" selected disabled>Choose destination currency</option>@foreach ($currencies as $currency)<option value="{{ $currency }}">{{ $currency }} — {{ $currencyNames[$currency] }}</option>@endforeach</select></label>
                            <button class="button button-outline wide-field" type="submit">Compare illustrative models <span>→</span></button>
                        </form>
                        <div class="result-box hidden" id="remittance-result" aria-live="polite"></div>
                        <p class="data-caption">Fee is in the selected source currency; recipient amount is in the selected destination currency. Fee models and delivery windows are simulated—not provider offers or guarantees.</p>
                    </section>
                </div>

                <section class="panel simulator-panel" id="simulator">
                    <div class="panel-heading"><div><div class="eyebrow">CHANGE THE ASSUMPTIONS</div><h2>Scenario lab</h2><p>See how a different month, contribution or exchange-rate assumption changes the picture.</p></div><span class="simulator-stamp">HYPOTHETICAL ONLY</span></div>
                    <form class="scenario-form" id="scenario-form">
                        <div class="scenario-fields">
                            <label>Monthly budget (<span class="currency-name" @unless($profile) data-currency-name-for="budget_currency" data-empty-label="Choose forecast budget currency" @endunless>{{ $profile ? $profile->home_currency.' — '.$currencyNames[$profile->home_currency] : 'Choose forecast budget currency' }}</span>, per month)<input name="monthly_budget" type="number" min="0" max="99999999.99" step="0.01" value="{{ $profile?->monthly_budget ?? '' }}" placeholder="Enter monthly budget" required></label>
                            <label>Monthly spending (<span class="currency-name" @unless($profile) data-currency-name-for="budget_currency" data-empty-label="Choose forecast budget currency" @endunless>{{ $profile ? $profile->home_currency.' — '.$currencyNames[$profile->home_currency] : 'Choose forecast budget currency' }}</span>, per month)<input name="monthly_spending" type="number" min="0" max="99999999.99" step="0.01" value="{{ $monthlySpending }}" required></label>
                            <label>Tuition contribution (<span class="currency-name" @unless($profile) data-currency-name-for="budget_currency" data-empty-label="Choose forecast budget currency" @endunless>{{ $profile ? $profile->home_currency.' — '.$currencyNames[$profile->home_currency] : 'Choose forecast budget currency' }}</span>, per month)<input name="tuition_contribution" type="number" min="0" max="99999999.99" step="0.01" value="" placeholder="Enter monthly contribution" required></label>
                            <label>Tuition target (<span class="currency-name" data-currency-name-for="from_currency" data-empty-label="Choose tuition currency">Choose tuition currency</span>, total for this plan)<input name="tuition_amount" type="number" min="0.01" max="99999999.99" step="0.01" value="{{ $firstTuition?->amount ?? '' }}" placeholder="Enter total tuition" required></label>
                            <label>Already saved (<span class="currency-name" data-currency-name-for="from_currency" data-empty-label="Choose tuition currency">Choose tuition currency</span>)<input name="saved_amount" type="number" min="0" max="99999999.99" step="0.01" value="{{ $firstTuition?->saved_amount ?? '' }}" placeholder="Enter 0.00 if none" required></label>
                            <label>Tuition due date<input name="due_date" type="date" value="{{ $firstTuition?->due_date?->toDateString() ?? '' }}" required></label>
                            <label>Installment count<input name="installments" type="number" min="1" max="24" value="{{ $firstTuition?->installments ?? '' }}" placeholder="Enter count" required></label>
                            <label>Transfer fee assumption (<span class="currency-name" data-currency-name-for="from_currency" data-empty-label="Choose tuition currency">Choose tuition currency</span>)<input name="transfer_fee" type="number" min="0" max="99999999.99" step="0.01" value="" placeholder="Enter fee assumption" required></label>
                            <label>Recipient rate change <span class="inline-value" id="rate-value">0%</span><input name="exchange_rate_change" type="range" min="-50" max="50" value="0" data-rate-slider></label>
                            <label>Tuition source currency<select name="from_currency" required>@if (!$profile)<option value="" selected disabled>Choose tuition currency</option>@endif @foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($firstTuition?->currency === $currency || (!$firstTuition && $profile?->home_currency === $currency))>{{ $currency }} — {{ $currencyNames[$currency] }}</option>@endforeach</select></label>
                            <label>Forecast budget currency<select name="budget_currency" required>@if (!$profile)<option value="" selected disabled>Choose budget currency</option>@endif @foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($profile?->home_currency === $currency)>{{ $currency }} — {{ $currencyNames[$currency] }}</option>@endforeach</select></label>
                            <label>Recipient currency<select name="to_currency" required><option value="" selected disabled>Choose recipient currency</option>@foreach ($currencies as $currency)<option value="{{ $currency }}">{{ $currency }} — {{ $currencyNames[$currency] }}</option>@endforeach</select></label>
                            <label>Monthly rent included in spending (<span class="currency-name" @unless($profile) data-currency-name-for="budget_currency" data-empty-label="Choose forecast budget currency" @endunless>{{ $profile ? $profile->home_currency.' — '.$currencyNames[$profile->home_currency] : 'Choose forecast budget currency' }}</span>, per month)<input name="monthly_rent" type="number" min="0" max="99999999.99" step="0.01" value="" placeholder="Enter amount included in spending" required></label>
                            <label>Rent change at forecast start (%)<input name="rent_change_percent" type="number" min="-50" max="100" step="1" value="10" required></label>
                            <label>Monthly income included in budget (<span class="currency-name" @unless($profile) data-currency-name-for="budget_currency" data-empty-label="Choose forecast budget currency" @endunless>{{ $profile ? $profile->home_currency.' — '.$currencyNames[$profile->home_currency] : 'Choose forecast budget currency' }}</span>, per month)<input name="monthly_income" type="number" min="0" max="99999999.99" step="0.01" value="{{ $profile?->monthly_income ?? '' }}" placeholder="Enter amount already included in budget" required></label>
                            <label>Income delayed by (months)<input name="income_delay_months" type="number" min="0" max="12" step="1" value="0" required></label>
                            <label>Expected scholarship (<span class="currency-name" @unless($profile) data-currency-name-for="budget_currency" data-empty-label="Choose forecast budget currency" @endunless>{{ $profile ? $profile->home_currency.' — '.$currencyNames[$profile->home_currency] : 'Choose forecast budget currency' }}</span>, total)<input name="scholarship_amount" type="number" min="0" max="99999999.99" step="0.01" value="0.00" required></label>
                            <label>Scholarship arrives in month<input name="scholarship_month" type="number" min="0" max="24" step="1" value="0" required><small>0 means not received within the forecast.</small></label>
                            <label>Forecast length (months)<input name="forecast_months" type="number" min="1" max="24" step="1" value="10" required></label>
                            <label>FX stress range <span class="inline-value" id="stress-value">±5%</span><input name="rate_stress_percent" type="range" min="0" max="50" value="5" data-stress-slider></label>
                        </div>
                        <div class="scenario-actions"><p>Monthly budget inputs use your profile home currency, or the selected Budget currency if no profile is set. They are converted to the selected forecast currency. Tuition, saved amount, and fee use the selected Tuition source currency. Rent is included in spending; income is included in the budget; a scholarship is unconfirmed. FX stress applies symmetrically to the fixed demonstration rate.</p><button class="button button-primary" type="submit">Run three forecasts <span>→</span></button></div>
                    </form>
                    <div class="scenario-result hidden" id="scenario-result" aria-live="polite"></div>
                </section>

                <div class="tools-grid settings-grid">
                    <section class="panel budgets-panel">
                        <div class="panel-heading"><div><div class="eyebrow">A SIMPLE GUARDRAIL</div><h2>Monthly category budgets</h2></div><button class="text-button" type="button" data-open-form="budget-form" aria-controls="budget-form" aria-expanded="false">+ Add budget</button></div>
                        @if ($budgets->isEmpty())<div class="empty-state compact"><span class="empty-icon">⊞</span><b>No category budgets yet.</b><p>Create a monthly spending target for a category you choose.</p></div>@else
                            <div class="budget-list">@foreach ($budgets as $item)<div class="budget-line"><span class="budget-icon">{{ mb_substr($item['budget']->category, 0, 1) }}</span><div><b>{{ $item['budget']->category }}</b><small>{{ $item['budget']->currency }} {{ $item['spent'] }} spent of {{ $item['budget']->currency }} {{ $item['budget']->amount }} · {{ $item['budget']->period_start->format('M j') }} — {{ $item['budget']->period_end->format('M j') }}</small><div class="budget-track"><i class="{{ $item['over_budget'] > 0 ? 'over' : '' }}" style="width: {{ $item['progress'] }}%"></i></div></div><strong>{{ $item['over_budget'] > 0 ? 'Over' : $item['budget']->currency.' '.$item['remaining'].' left' }}</strong></div>@endforeach</div>
                        @endif
                        <form method="post" action="{{ route('budgets.store') }}" class="inline-form hidden-form" id="budget-form">
                            @csrf
                            <div class="form-grid"><label>Category<input name="category" required maxlength="60" placeholder="Food & groceries"></label>                            <label>Monthly limit (<span class="currency-name" data-currency-name-for="currency">Choose a currency</span>, per month)<input name="amount" type="number" min="0.01" max="99999999.99" step="0.01" required placeholder="300.00"></label><label class="wide-field">Budget currency<select name="currency" required>@if (old('currency', $profile?->home_currency) === null)<option value="" selected disabled>Choose a currency</option>@endif @foreach ($currencies as $currency)<option value="{{ $currency }}" @selected(old('currency', $profile?->home_currency) === $currency)>{{ $currency }} — {{ $currencyNames[$currency] }}</option>@endforeach</select></label></div>
                            <button class="button button-primary" type="submit">Save budget</button>
                        </form>
                    </section>
                    <section class="panel saved-plans-panel">
                        <div class="panel-heading"><div><div class="eyebrow">USER-APPROVED</div><h2>Saved plans</h2></div><span class="tool-symbol">✓</span></div>
                        @if ($financialPlans->isEmpty())<div class="empty-state compact"><span class="empty-icon">✳</span><b>Your plans, when you’re ready.</b><p>Ask Atlas to draft a plan. Nothing is saved until you confirm.</p><a href="#assistant">Talk to Atlas <span>→</span></a></div>@else<div class="saved-plan-list">@foreach ($financialPlans as $plan)<article><b>{{ $plan->title }}</b><p>{{ $plan->summary }}</p><small>Saved {{ $plan->created_at->format('M j, Y') }}</small></article>@endforeach</div>@endif
                    </section>
                </div>

                <section class="panel funding-panel" id="funding">
                    <div class="panel-heading"><div><div class="eyebrow">STUDENT FUNDING · USER-ENTERED</div><h2>Scholarship &amp; funding planner</h2><p>Track opportunities you find, their deadlines, application status, and potential value. Atlas does not verify eligibility, sources, or awards.</p></div><span class="tool-symbol">+</span></div>
                    @if ($fundingOpportunities->isEmpty())
                        <div class="empty-state compact"><b>No funding opportunities recorded.</b><p>Add a real opportunity you have found and keep its official source handy. We do not generate or recommend unverified awards.</p></div>
                    @else
                            <div class="funding-list">
                            @foreach ($fundingOpportunities as $opportunity)
                                <article class="funding-item">
                                    <div class="funding-item-heading"><div><b>{{ $opportunity->title }}</b><small>{{ $opportunity->provider }} · User-entered; not verified</small></div><span class="status-pill">{{ str_replace('_', ' ', $opportunity->status) }}</span></div>
                                    <div class="funding-item-details">
                                        <span>Potential total: {{ $opportunity->potential_amount === null ? 'Not provided' : $opportunity->currency.' — '.$currencyNames[$opportunity->currency].' '.$opportunity->potential_amount }}</span>
                                        <span>Deadline: {{ $opportunity->deadline?->format('M j, Y') ?? 'Not provided' }}</span>
                                        @if ($opportunity->source_url)<a href="{{ $opportunity->source_url }}" target="_blank" rel="noopener noreferrer">Open source ↗</a>@endif
                                    </div>
                                    <form method="post" action="{{ route('funding-opportunities.status', $opportunity) }}" class="funding-status-form">
                                        @csrf @method('PATCH')
                                        <label>Application status<select name="status">@foreach (\App\Models\FundingOpportunity::STATUSES as $status)<option value="{{ $status }}" @selected($status === $opportunity->status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>@endforeach</select></label>
                                        <button class="button button-outline" type="submit">Update status</button>
                                    </form>
                                </article>
                            @endforeach
                        </div>
                    @endif
                    <details class="funding-add">
                        <summary>+ Track a funding opportunity</summary>
                        <form method="post" action="{{ route('funding-opportunities.store') }}" class="form-grid inline-form">
                            @csrf
                            <label>Opportunity name<input name="title" required maxlength="120" placeholder="Name from the official source"></label>
                            <label>Provider<input name="provider" required maxlength="120" placeholder="University, foundation, or agency"></label>
                            <label>Potential value (<span class="currency-name" data-currency-name-for="currency">Choose a currency</span>, optional total)<input name="potential_amount" type="number" min="0" max="99999999.99" step="0.01" placeholder="0.00"></label>
                            <label>Potential-value currency<select name="currency" required>@if (!$profile)<option value="" selected disabled>Choose a currency</option>@endif @foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($profile?->home_currency === $currency)>{{ $currency }} — {{ $currencyNames[$currency] }}</option>@endforeach</select></label>
                            <label>Deadline (optional)<input type="date" name="deadline"></label>
                            <label>Application status<select name="status">@foreach (\App\Models\FundingOpportunity::STATUSES as $status)<option value="{{ $status }}">{{ str_replace('_', ' ', ucfirst($status)) }}</option>@endforeach</select></label>
                            <label class="wide-field">Official source URL (optional)<input type="url" name="source_url" maxlength="2048" placeholder="https://..."></label>
                            <button class="button button-primary wide-field" type="submit">Save opportunity</button>
                        </form>
                    </details>
                    <p class="data-caption">Potential awards are not guaranteed income. Verify eligibility, deadlines, and terms with the provider before making plans.</p>
                </section>

                <section class="panel audit-panel">
                    <div class="panel-heading"><div><div class="eyebrow">RECENT ASSISTANT ACTIVITY</div><h2>Agent action log</h2><p>Tool names, outcomes and confirmation state only. Sensitive arguments are not copied to this log.</p></div><span class="tool-symbol">⌘</span></div>
                    @if ($agentAuditLogs->isEmpty())
                        <div class="empty-state compact"><span class="empty-icon">⌘</span><b>No agent actions yet.</b><p>Tool calls and explicitly confirmed planning updates will be recorded here.</p></div>
                    @else
                        <div class="audit-list">@foreach ($agentAuditLogs as $event)<article class="audit-row"><span class="audit-state {{ $event->status === 'confirmed' ? 'confirmed' : ($event->status === 'rejected' ? 'rejected' : '') }}">{{ str_replace('_', ' ', $event->status) }}</span><div><b>{{ str_replace('_', ' ', $event->tool_name) }}</b><p>{{ $event->summary }}</p></div><time>{{ $event->created_at->format('M j, H:i') }}</time></article>@endforeach</div>
                    @endif
                </section>

                <section class="panel activity-panel" id="activity">
                    <div class="panel-heading"><div><div class="eyebrow">YOUR RECORDS, YOUR CONTEXT</div><h2>Financial activity</h2><p>Manual records are not bank-connected. Atlas never executes or imports transactions.</p></div><button class="text-button" type="button" data-open-form="activity-form" aria-controls="activity-form" aria-expanded="false">+ Add activity</button></div>
                    <div class="activity-controls"><span>SHOW</span><a class="{{ $filter === 'all' ? 'active' : '' }}" href="{{ route('dashboard', ['type' => 'all']) }}#activity">All</a><a class="{{ $filter === 'expense' ? 'active' : '' }}" href="{{ route('dashboard', ['type' => 'expense']) }}#activity">Expenses</a><a class="{{ $filter === 'income' ? 'active' : '' }}" href="{{ route('dashboard', ['type' => 'income']) }}#activity">Income</a></div>
                    @if ($transactions->isEmpty())
                        <div class="empty-state"><span class="empty-icon">◷</span><b>No activity matches this view.</b><p>Add an expense or income record to build your own picture.</p></div>
                    @else
                        <div class="table-scroll"><table class="activity-table"><thead><tr><th>ACTIVITY</th><th>TYPE</th><th>DATE</th><th>AMOUNT</th><th>RECORD</th></tr></thead><tbody>@foreach ($transactions as $transaction)<tr><td><div class="activity-description"><span class="transaction-icon {{ $transaction->kind }}">{{ $transaction->kind === 'income' ? '↙' : '↗' }}</span><div><b>{{ $transaction->description }}</b><small>{{ $transaction->category }}</small></div></div></td><td><span class="type-pill {{ $transaction->kind }}">{{ ucfirst($transaction->kind) }}</span></td><td>{{ $transaction->occurred_on->format('M j, Y') }}</td><td class="amount-cell">{{ $transaction->kind === 'income' ? '+' : '−' }} {{ $transaction->currency }} {{ $transaction->amount }}</td><td><span class="record-pill {{ $transaction->is_simulated ? 'simulated' : 'manual' }}">{{ $transaction->is_simulated ? 'Simulated' : 'Manual record' }}</span></td></tr>@endforeach</tbody></table></div>
                    @endif
                    <form method="post" action="{{ route('transactions.store') }}" class="inline-form hidden-form" id="activity-form">
                        @csrf
                        <div class="form-grid"><label>Type<select name="kind"><option value="expense">Expense</option><option value="income">Income</option></select></label><label>Category<input name="category" required maxlength="60" placeholder="Housing"></label><label>Description<input name="description" required maxlength="160" placeholder="Monthly rent"></label><label>Amount (<span class="currency-name" data-currency-name-for="currency">Choose a currency</span>)<input name="amount" type="number" min="0.01" max="99999999.99" step="0.01" required placeholder="800.00"></label><label>Activity currency<select name="currency" required>@if (old('currency', $profile?->home_currency) === null)<option value="" selected disabled>Choose a currency</option>@endif @foreach ($currencies as $currency)<option value="{{ $currency }}" @selected(old('currency', $profile?->home_currency) === $currency)>{{ $currency }} — {{ $currencyNames[$currency] }}</option>@endforeach</select></label><label>Date<input type="date" name="occurred_on" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" required></label><label class="check-row wide-field"><input name="is_simulated" type="checkbox" value="1"><span>Mark this record as simulated (not an actual expense/income). If unchecked, this remains a user-entered manual record.</span></label></div>
                        <button class="button button-primary" type="submit">Add activity record</button>
                    </form>
                </section>

                <section class="panel profile-panel" id="profile-settings">
                    <div class="panel-heading"><div><div class="eyebrow">YOUR CONTEXT &amp; PRIVACY</div><h2>Profile &amp; settings</h2><p>These details are private to your account and help make planning relevant.</p></div></div>
                    <form method="post" action="{{ route('profile.save') }}" class="form-grid profile-form">
                        @csrf @method('PUT')
                        <label>Study destination<input name="destination_country" value="{{ old('destination_country', $profile?->destination_country) }}" required maxlength="80"></label>
                        <label>Home currency<select name="home_currency" required>@if (old('home_currency', $profile?->home_currency) === null)<option value="" selected disabled>Choose your home currency</option>@endif @foreach ($currencies as $currency)<option value="{{ $currency }}" @selected(old('home_currency', $profile?->home_currency) === $currency)>{{ $currency }} — {{ $currencyNames[$currency] }}</option>@endforeach</select></label>
                        <label>Monthly budget (<span class="currency-name" data-currency-name-for="home_currency" data-empty-label="Choose home currency">Choose home currency</span>, per month)<input name="monthly_budget" type="number" min="0" max="99999999.99" step="0.01" value="{{ old('monthly_budget', $profile?->monthly_budget) }}" required></label>
                        <label>Monthly income (<span class="currency-name" data-currency-name-for="home_currency" data-empty-label="Choose home currency">Choose home currency</span>, per month)<input name="monthly_income" type="number" min="0" max="99999999.99" step="0.01" value="{{ old('monthly_income', $profile?->monthly_income) }}"></label>
                        <label class="wide-field">Financial goals<textarea name="financial_goals" maxlength="1000" rows="2">{{ old('financial_goals', $profile?->financial_goals) }}</textarea></label>
                        <label class="check-row wide-field"><input name="memory_consent" type="checkbox" value="1" @checked(old('memory_consent', $profile?->memory_consent ?? false))><span>Let Atlas use recent messages as context for future answers. Conversation history remains visible when this is off.</span></label>
                        <button class="button button-outline wide-field" type="submit">Save profile &amp; privacy settings</button>
                    </form>
                    <div class="privacy-note"><span>i</span><p>{{ $aiProviderName }} receives your current message and tool results when configured. Recent conversation messages are sent as context only when you turn on memory consent. Never include bank passwords, card numbers or identity documents.</p></div>
                </section>
                <footer class="app-footer"><span>GlobeNest Atlas · Student finance planning prototype</span><span>Demonstration rates · No real-money transfers · Not financial advice</span></footer>
            </div>
        </main>
    </div>
</body>
</html>
