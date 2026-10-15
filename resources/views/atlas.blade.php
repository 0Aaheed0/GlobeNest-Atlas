<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#09111f">
    <title>Workspace · GlobeNest Atlas</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="app-page" data-currency-endpoint="{{ route('currency.convert') }}" data-remittance-endpoint="{{ route('remittance.compare') }}" data-scenario-endpoint="{{ route('scenario.run') }}" data-agent-endpoint="{{ route('agent.chat') }}" data-confirm-endpoint="{{ route('agent.confirm') }}" data-cancel-endpoint="{{ route('agent.cancel') }}">
    <div class="app-shell">
        <aside class="sidebar" id="sidebar">
            <a class="brand sidebar-brand" href="{{ route('dashboard') }}"><span class="brand-mark">A</span><span>GlobeNest <b>Atlas</b></span></a>
            <div class="workspace-label">YOUR WORKSPACE</div>
            <nav class="side-nav" aria-label="Main navigation">
                <a class="nav-item active" href="#overview"><span class="nav-icon">◫</span> Overview</a>
                <a class="nav-item" href="#assistant"><span class="nav-icon">✳</span> Atlas assistant <span class="nav-tag">AI</span></a>
                <a class="nav-item" href="#tuition"><span class="nav-icon">▤</span> Tuition planner</a>
                <a class="nav-item" href="#currency"><span class="nav-icon">↔</span> Currency &amp; transfers</a>
                <a class="nav-item" href="#simulator"><span class="nav-icon">⌁</span> Scenario lab</a>
                <a class="nav-item" href="#alerts"><span class="nav-icon">!</span> Financial alerts</a>
                <a class="nav-item" href="#activity"><span class="nav-icon">◷</span> Activity</a>
                <a class="nav-item" href="#funding"><span class="nav-icon">+</span> Funding planner</a>
                <a class="nav-item" href="#profile-settings"><span class="nav-icon">⚙</span> Profile &amp; settings</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-note"><span class="note-icon">i</span><span>Atlas helps you plan. It never moves money or guarantees an outcome.</span></div>
                <div class="sidebar-user"><div class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</div><div class="user-copy"><b>{{ auth()->user()->name }}</b><span>{{ auth()->user()->email }}</span></div><form method="post" action="{{ route('logout') }}">@csrf<button class="icon-button" type="submit" aria-label="Sign out" title="Sign out">↗</button></form></div>
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
                            <label>Home currency<select name="home_currency" required>@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected(old('home_currency', 'USD') === $currency)>{{ $currency }}</option>@endforeach</select></label>
                            <label>Monthly budget<input name="monthly_budget" inputmode="decimal" placeholder="1800.00" value="{{ old('monthly_budget') }}" required></label>
                            <label>Monthly income (optional)<input name="monthly_income" inputmode="decimal" placeholder="0.00" value="{{ old('monthly_income') }}"></label>
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
                        <article class="metric-card metric-primary"><div class="metric-top"><span>AVAILABLE THIS MONTH</span><span class="metric-icon">↗</span></div><div class="metric-value">{{ $profile->home_currency }} {{ $availableFunds }}</div><div class="metric-foot">@if ($overBudget > 0) Over budget by {{ $profile->home_currency }} {{ \App\Services\CurrencyCalculator::formatMinorUnits($overBudget) }} @else {{ $profile->home_currency }} budget less same-currency recorded expenses @endif</div><div class="metric-rule"><i style="width: {{ $budgetUsagePercent }}%"></i></div><div class="metric-foot metric-bottom">Budget {{ $profile->home_currency }} {{ $profile->monthly_budget }} <span>{{ $profile->destination_country }}</span></div></article>
                        <article class="metric-card"><div class="metric-top"><span>SPENT THIS MONTH</span><span class="metric-icon">⌁</span></div><div class="metric-value">{{ $profile->home_currency }} {{ $monthlySpending }}</div><div class="metric-foot">Recorded expenses in your home currency</div><div class="metric-mini"><span class="mini-dot green"></span> {{ $categoryBreakdown->count() }} spending {{ \Illuminate\Support\Str::plural('category', $categoryBreakdown->count()) }} tracked</div></article>
                        <article class="metric-card"><div class="metric-top"><span>TUITION PLANS</span><span class="metric-icon">▤</span></div><div class="metric-value">{{ $tuitionPlans->count() }} <small>{{ \Illuminate\Support\Str::plural('plan', $tuitionPlans->count()) }}</small></div><div class="metric-foot">{{ $firstTuition?->due_date?->format('M j, Y') ?? 'Add a tuition deadline to begin' }}</div><a class="metric-link" href="#tuition">View tuition plan <span>→</span></a></article>
                        <article class="metric-card"><div class="metric-top"><span>SAVINGS GOALS</span><span class="metric-icon">◎</span></div><div class="metric-value">{{ $goals->count() }} <small>{{ \Illuminate\Support\Str::plural('goal', $goals->count()) }}</small></div><div class="metric-foot">{{ $goals->first()['goal']->title ?? 'Small steps, clearly measured' }}</div><a class="metric-link" href="#goals">View savings goals <span>→</span></a></article>
                    </section>
                @endif

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
                            <div class="commitment-list">@foreach ($tuitionPlans as $item)<article class="commitment"><div class="commitment-date"><b>{{ $item['plan']->due_date->format('d') }}</b><small>{{ $item['plan']->due_date->format('M') }}</small></div><div class="commitment-info"><b>{{ $item['plan']->title }}</b><span>{{ $item['plan']->currency }} {{ $item['plan']->amount }} · {{ $item['plan']->installments }} {{ \Illuminate\Support\Str::plural('installment', $item['plan']->installments) }}</span><div class="commitment-progress"><i style="width: {{ $item['progress'] }}%"></i></div><small>{{ $item['schedule']['required_monthly'] }} {{ $item['plan']->currency }}/month needed · {{ $item['schedule']['due_in_days'] }} days left</small><details class="installment-detail"><summary>View generated monthly installment dates</summary><div>@foreach ($item['installments'] as $installment)<p><span>Installment {{ $installment['number'] }} · {{ \Illuminate\Support\Carbon::parse($installment['due_date'])->format('M j, Y') }}</span><b>{{ $item['plan']->currency }} {{ $installment['amount'] }}</b><small>{{ $installment['saved'] }} saved · {{ $installment['remaining'] }} remaining · {{ $installment['status'] }}</small></p>@endforeach</div><small>Equal monthly estimates ending on the selected deadline; saved progress is allocated to earlier installments first.</small></details><details class="progress-update"><summary>Record savings progress</summary><form method="post" action="{{ route('tuition.progress', $item['plan']) }}">@csrf<label>Additional amount saved ({{ $item['plan']->currency }})<input name="additional_amount" inputmode="decimal" required placeholder="100.00"></label><button class="button button-outline" type="submit">Update progress</button><small>Manual planning entry only. Atlas does not move money.</small></form></details></div><span class="status-dot {{ $item['schedule']['status'] === 'funded' ? 'done' : (in_array($item['schedule']['status'], ['overdue', 'due_today'], true) ? 'urgent' : '') }}"></span></article>@endforeach</div>
                        @endif
                        <form method="post" action="{{ route('tuition.store') }}" class="inline-form hidden-form" id="tuition-form">
                            @csrf
                            <div class="form-grid"><label>Payment name<input name="title" required maxlength="120" placeholder="Fall semester tuition"></label><label>Amount<input name="amount" required inputmode="decimal" placeholder="12000.00"></label><label>Already saved<input name="saved_amount" inputmode="decimal" placeholder="0.00"></label><label>Currency<select name="currency">@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($currency === ($profile?->home_currency ?? 'USD'))>{{ $currency }}</option>@endforeach</select></label><label>Due date<input name="due_date" type="date" min="{{ today()->toDateString() }}" required></label><label>Installments<input name="installments" type="number" min="1" max="24" value="1" required></label></div>
                            <button class="button button-primary" type="submit">Add tuition plan</button>
                        </form>
                    </section>
                    <section class="panel goals-panel" id="goals">
                        <div class="panel-heading"><div><div class="eyebrow">YOUR NEXT MILESTONE</div><h2>Savings goals</h2></div><button class="text-button" type="button" data-open-form="goal-form" aria-controls="goal-form" aria-expanded="false">+ Add goal</button></div>
                        @if ($goals->isEmpty())
                            <div class="empty-state compact"><span class="empty-icon">◎</span><b>Make room for a goal.</b><p>Set a target and track the progress you choose to record.</p></div>
                        @else
                            <div class="goals-list">@foreach ($goals as $item)<article class="goal-item"><div class="goal-heading"><b>{{ $item['goal']->title }}</b><span>{{ $item['progress'] }}%</span></div><div class="goal-track"><i style="width: {{ $item['progress'] }}%"></i></div><div class="goal-meta"><span>{{ $item['goal']->currency }} {{ $item['goal']->current_amount }} saved</span><span>of {{ $item['goal']->currency }} {{ $item['goal']->target_amount }}</span></div><details class="progress-update"><summary>Record savings progress</summary><form method="post" action="{{ route('goals.progress', $item['goal']) }}">@csrf<label>Additional amount saved ({{ $item['goal']->currency }})<input name="additional_amount" inputmode="decimal" required placeholder="50.00"></label><button class="button button-outline" type="submit">Update progress</button><small>Manual planning entry only. Atlas does not move money.</small></form></details></article>@endforeach</div>
                        @endif
                        <form method="post" action="{{ route('goals.store') }}" class="inline-form hidden-form" id="goal-form">
                            @csrf
                            <div class="form-grid"><label>Goal name<input name="title" required maxlength="120" placeholder="Emergency fund"></label><label>Target amount<input name="target_amount" required inputmode="decimal" placeholder="1500.00"></label><label>Already saved<input name="current_amount" inputmode="decimal" placeholder="0.00"></label><label>Currency<select name="currency">@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($currency === ($profile?->home_currency ?? 'USD'))>{{ $currency }}</option>@endforeach</select></label><label class="wide-field">Target date (optional)<input name="target_date" type="date" min="{{ today()->toDateString() }}"></label></div>
                            <button class="button button-primary" type="submit">Save goal</button>
                        </form>
                    </section>
                </div>

                <section class="panel assistant-panel" id="assistant">
                    <div class="assistant-header"><div class="assistant-mark">✳</div><div><div class="eyebrow">ATLAS AI · {{ strtoupper(config('services.gemini.model', 'gemini-2.5-flash')) }}</div><h2>Think it through, together.</h2><p>Ask about your budget, a currency conversion or a tuition plan. Actions that change records always wait for your confirmation.</p></div><span class="ai-status {{ config('services.gemini.key') ? 'unverified' : 'needs-key' }}"><i></i> {{ config('services.gemini.key') ? 'API key set · model access not verified' : 'Needs server API key' }}</span></div>
                    <section class="approval-center" aria-labelledby="approval-center-title">
                        <div class="approval-heading"><div><div class="eyebrow">TRUST &amp; PERMISSIONS</div><h3 id="approval-center-title">Pending approvals</h3><p>Review a proposed change before it is saved. Drafts expire after 15 minutes.</p></div><span class="subtle-pill">{{ $pendingActions->count() }} pending</span></div>
                        @if ($pendingActions->isEmpty())
                            <p class="approval-empty">No pending changes. Atlas can read only your own records; saving a planning change always requires your explicit confirmation.</p>
                        @else
                            <div class="pending-approval-list">
                                @foreach ($pendingActions as $action)
                                    <article class="pending-approval" data-pending-approval>
                                        <div><b>{{ \Illuminate\Support\Str::headline($action->tool_name) }}</b><small>Draft for conversation #{{ $action->conversation_id }} · expires {{ $action->expires_at->diffForHumans() }}</small></div>
                                        <pre>{{ json_encode($action->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        <div class="approval-actions">
                                            <button class="button button-primary" type="button" data-approve-pending="{{ $action->id }}" data-conversation-id="{{ $action->conversation_id }}">Confirm and save</button>
                                            <button class="button button-quiet" type="button" data-reject-pending="{{ $action->id }}" data-conversation-id="{{ $action->conversation_id }}">Not now</button>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </section>
                    <div class="assistant-layout">
                        <aside class="conversation-rail"><div class="rail-heading"><span>RECENT CONVERSATIONS</span><button type="button" data-new-chat aria-label="Start a new conversation">+</button></div>
                            @forelse ($conversations as $conversation)<button class="conversation-item {{ $selectedConversation?->id === $conversation->id ? 'selected' : '' }}" type="button" data-load-conversation="{{ $conversation->id }}"><b>{{ \Illuminate\Support\Str::limit($conversation->title, 34) }}</b><small>{{ $conversation->updated_at->diffForHumans() }}</small></button>@empty<p class="conversation-empty">Your saved conversations will appear here.</p>@endforelse
                        </aside>
                        <div class="chat-workspace">
                            <div class="chat-messages" id="chat-messages" aria-live="polite" data-conversation-id="{{ $selectedConversation?->id ?? '' }}">
                                @if ($selectedConversation)
                                    @foreach ($selectedConversation->messages as $message)
                                        <article class="chat-message {{ $message->role === 'assistant' ? 'assistant-message' : 'user-message' }}" @if ($message->role === 'assistant') data-tool-results="{{ json_encode($message->tool_result ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}" @endif><span class="message-avatar">{{ $message->role === 'assistant' ? 'A' : mb_substr(auth()->user()->name, 0, 1) }}</span><div><b>{{ $message->role === 'assistant' ? 'Atlas' : 'You' }}</b><p>{{ $message->content }}</p></div></article>
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
                            <label>Amount<input name="amount" type="number" min="0.01" max="99999999.99" step="0.01" value="500.00" required></label>
                            <label>From<select name="from_currency">@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($currency === ($profile?->home_currency ?? 'USD'))>{{ $currency }}</option>@endforeach</select></label>
                            <label>To<select name="to_currency">@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($currency === 'EUR')>{{ $currency }}</option>@endforeach</select></label>
                            <button class="button button-primary wide-field" type="submit">Calculate estimate <span>→</span></button>
                        </form>
                        <div class="result-box hidden" id="convert-result" aria-live="polite"></div>
                        <p class="data-caption">Reference rates are fixed demonstration data, not live quotes. No money is exchanged.</p>
                    </section>
                    <section class="panel tool-panel">
                        <div class="panel-heading"><div><div class="eyebrow">COMPARE THE ASSUMPTIONS</div><h2>Transfer estimates</h2></div><span class="tool-symbol">⌁</span></div>
                        <form class="form-grid tool-form" id="remittance-form">
                            <label>Send amount<input name="amount" type="number" min="0.01" max="99999999.99" step="0.01" value="500.00" required></label>
                            <label>From<select name="from_currency">@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($currency === ($profile?->home_currency ?? 'USD'))>{{ $currency }}</option>@endforeach</select></label>
                            <label>To<select name="to_currency">@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($currency === 'EUR')>{{ $currency }}</option>@endforeach</select></label>
                            <button class="button button-outline wide-field" type="submit">Compare illustrative models <span>→</span></button>
                        </form>
                        <div class="result-box hidden" id="remittance-result" aria-live="polite"></div>
                        <p class="data-caption">Fee models and delivery windows are simulated. Not provider offers or guarantees.</p>
                    </section>
                </div>

                <section class="panel simulator-panel" id="simulator">
                    <div class="panel-heading"><div><div class="eyebrow">CHANGE THE ASSUMPTIONS</div><h2>Scenario lab</h2><p>See how a different month, contribution or exchange-rate assumption changes the picture.</p></div><span class="simulator-stamp">HYPOTHETICAL ONLY</span></div>
                    <form class="scenario-form" id="scenario-form">
                        <div class="scenario-fields">
                            <label>Monthly budget ({{ $profile?->home_currency ?? 'USD' }})<input name="monthly_budget" type="number" min="0" step="0.01" value="{{ $profile?->monthly_budget ?? '1800.00' }}" required></label>
                            <label>Monthly spending ({{ $profile?->home_currency ?? 'USD' }})<input name="monthly_spending" type="number" min="0" step="0.01" value="{{ $monthlySpending }}" required></label>
                            <label>Tuition contribution / month ({{ $profile?->home_currency ?? 'USD' }})<input name="tuition_contribution" type="number" min="0" step="0.01" value="200.00" required></label>
                            <label>Tuition target (from currency)<input name="tuition_amount" type="number" min="0.01" step="0.01" value="{{ $firstTuition?->amount ?? '12000.00' }}" required></label>
                            <label>Already saved<input name="saved_amount" type="number" min="0" step="0.01" value="{{ $firstTuition?->saved_amount ?? '0.00' }}" required></label>
                            <label>Tuition due date<input name="due_date" type="date" value="{{ $firstTuition?->due_date?->toDateString() ?? today()->addMonths(10)->toDateString() }}" required></label>
                            <label>Installment count<input name="installments" type="number" min="1" max="24" value="{{ $firstTuition?->installments ?? 4 }}" required></label>
                            <label>Transfer fee (assumption)<input name="transfer_fee" type="number" min="0" step="0.01" value="5.00" required></label>
                            <label>Recipient rate change <span class="inline-value" id="rate-value">0%</span><input name="exchange_rate_change" type="range" min="-50" max="50" value="0" data-rate-slider></label>
                            <label>From<select name="from_currency">@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($currency === ($profile?->home_currency ?? 'USD'))>{{ $currency }}</option>@endforeach</select></label>
                            <label>Budget currency<select name="budget_currency">@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($currency === ($profile?->home_currency ?? 'USD'))>{{ $currency }}</option>@endforeach</select></label>
                            <label>To<select name="to_currency">@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($currency === 'EUR')>{{ $currency }}</option>@endforeach</select></label>
                            <label>Monthly rent included in spending<input name="monthly_rent" type="number" min="0" step="0.01" value="0.00" required></label>
                            <label>Rent change at forecast start (%)<input name="rent_change_percent" type="number" min="-50" max="100" step="1" value="10" required></label>
                            <label>Monthly income included in budget<input name="monthly_income" type="number" min="0" step="0.01" value="{{ $profile?->monthly_income ?? '0.00' }}" required></label>
                            <label>Income delayed by (months)<input name="income_delay_months" type="number" min="0" max="12" step="1" value="0" required></label>
                            <label>Expected scholarship ({{ $profile?->home_currency ?? 'USD' }})<input name="scholarship_amount" type="number" min="0" step="0.01" value="0.00" required></label>
                            <label>Scholarship arrives in month<input name="scholarship_month" type="number" min="0" max="24" step="1" value="0" required><small>0 means not received within the forecast.</small></label>
                            <label>Forecast length (months)<input name="forecast_months" type="number" min="1" max="24" step="1" value="10" required></label>
                            <label>FX stress range <span class="inline-value" id="stress-value">±5%</span><input name="rate_stress_percent" type="range" min="0" max="50" value="5" data-stress-slider></label>
                        </div>
                        <div class="scenario-actions"><p>Monthly budget inputs use your home currency and are converted to the selected budget currency. Rent is included in spending, income in the budget, and any scholarship is unconfirmed. FX stress applies symmetrically to the demonstration tuition conversion.</p><button class="button button-primary" type="submit">Run three forecasts <span>→</span></button></div>
                    </form>
                    <div class="scenario-result hidden" id="scenario-result" aria-live="polite"></div>
                </section>

                <div class="tools-grid settings-grid">
                    <section class="panel budgets-panel">
                        <div class="panel-heading"><div><div class="eyebrow">A SIMPLE GUARDRAIL</div><h2>Monthly category budgets</h2></div><button class="text-button" type="button" data-open-form="budget-form" aria-controls="budget-form" aria-expanded="false">+ Add budget</button></div>
                        @if ($budgets->isEmpty())<div class="empty-state compact"><span class="empty-icon">⊞</span><b>No category budgets yet.</b><p>Create a monthly spending target for a category you choose.</p></div>@else
                            <div class="budget-list">@foreach ($budgets as $item)<div class="budget-line"><span class="budget-icon">{{ mb_substr($item['budget']->category, 0, 1) }}</span><div><b>{{ $item['budget']->category }}</b><small>{{ $item['budget']->currency }} {{ $item['spent'] }} spent of {{ $item['budget']->amount }} · {{ $item['budget']->period_start->format('M j') }} — {{ $item['budget']->period_end->format('M j') }}</small><div class="budget-track"><i class="{{ $item['over_budget'] > 0 ? 'over' : '' }}" style="width: {{ $item['progress'] }}%"></i></div></div><strong>{{ $item['over_budget'] > 0 ? 'Over' : $item['budget']->currency.' '.$item['remaining'].' left' }}</strong></div>@endforeach</div>
                        @endif
                        <form method="post" action="{{ route('budgets.store') }}" class="inline-form hidden-form" id="budget-form">
                            @csrf
                            <div class="form-grid"><label>Category<input name="category" required maxlength="60" placeholder="Food & groceries"></label><label>Monthly limit<input name="amount" inputmode="decimal" required placeholder="300.00"></label><label class="wide-field">Currency<select name="currency">@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($currency === ($profile?->home_currency ?? 'USD'))>{{ $currency }}</option>@endforeach</select></label></div>
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
                                        <span>Potential amount: {{ $opportunity->potential_amount === null ? 'Not provided' : $opportunity->currency.' '.$opportunity->potential_amount }}</span>
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
                            <label>Potential value (optional)<input name="potential_amount" inputmode="decimal" placeholder="0.00"></label>
                            <label>Currency<select name="currency">@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($currency === ($profile?->home_currency ?? 'USD'))>{{ $currency }}</option>@endforeach</select></label>
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
                        <div class="form-grid"><label>Type<select name="kind"><option value="expense">Expense</option><option value="income">Income</option></select></label><label>Category<input name="category" required maxlength="60" placeholder="Housing"></label><label>Description<input name="description" required maxlength="160" placeholder="Monthly rent"></label><label>Amount<input name="amount" inputmode="decimal" required placeholder="800.00"></label><label>Currency<select name="currency">@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($currency === ($profile?->home_currency ?? 'USD'))>{{ $currency }}</option>@endforeach</select></label><label>Date<input type="date" name="occurred_on" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" required></label>                        <label class="check-row wide-field"><input name="is_simulated" type="checkbox" value="1"><span>Mark this record as simulated (not an actual expense/income). If unchecked, this remains a user-entered manual record.</span></label></div>
                        <button class="button button-primary" type="submit">Add activity record</button>
                    </form>
                </section>

                <section class="panel profile-panel" id="profile-settings">
                    <div class="panel-heading"><div><div class="eyebrow">YOUR CONTEXT &amp; PRIVACY</div><h2>Profile &amp; settings</h2><p>These details are private to your account and help make planning relevant.</p></div></div>
                    <form method="post" action="{{ route('profile.save') }}" class="form-grid profile-form">
                        @csrf @method('PUT')
                        <label>Study destination<input name="destination_country" value="{{ old('destination_country', $profile?->destination_country) }}" required maxlength="80"></label>
                        <label>Home currency<select name="home_currency">@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected(old('home_currency', $profile?->home_currency ?? 'USD') === $currency)>{{ $currency }}</option>@endforeach</select></label>
                        <label>Monthly budget<input name="monthly_budget" value="{{ old('monthly_budget', $profile?->monthly_budget ?? '1800.00') }}" required inputmode="decimal"></label>
                        <label>Monthly income<input name="monthly_income" value="{{ old('monthly_income', $profile?->monthly_income ?? '0.00') }}" inputmode="decimal"></label>
                        <label class="wide-field">Financial goals<textarea name="financial_goals" maxlength="1000" rows="2">{{ old('financial_goals', $profile?->financial_goals) }}</textarea></label>
                        <label class="check-row wide-field"><input name="memory_consent" type="checkbox" value="1" @checked(old('memory_consent', $profile?->memory_consent ?? false))><span>Let Atlas use recent messages as context for future answers. Conversation history remains visible when this is off.</span></label>
                        <button class="button button-outline wide-field" type="submit">Save profile &amp; privacy settings</button>
                    </form>
                    <div class="privacy-note"><span>i</span><p>Gemini receives your current message and tool results when enabled. Recent conversation messages are sent as context only when you turn on memory consent. Never include bank passwords, card numbers or identity documents.</p></div>
                </section>
                <footer class="app-footer"><span>GlobeNest Atlas · Student finance planning prototype</span><span>Demonstration rates · No real-money transfers · Not financial advice</span></footer>
            </div>
        </main>
    </div>
</body>
</html>
