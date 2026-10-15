<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#09111f">
    <title>GlobeNest Atlas — clarity for money across borders</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="landing-page">
    <nav class="landing-nav wrap">
        <a class="brand" href="{{ route('home') }}"><span class="brand-mark">A</span><span>GlobeNest <b>Atlas</b></span></a>
        <div class="landing-links"><a href="#how-it-works">How it works</a><a href="#principles">Our principles</a><button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch to light theme">Light</button><a class="button button-quiet" href="{{ route('login') }}">Sign in</a><a class="button button-primary" href="{{ route('register') }}">Create your Atlas <span aria-hidden="true">↗</span></a></div>
    </nav>
    <main>
        <section class="landing-hero wrap">
            <div class="hero-copy">
                <div class="eyebrow"><span class="live-dot"></span> FINANCIAL CLARITY, WITHOUT BORDERS</div>
                <h1>Your next chapter.<br><span>On steadier ground.</span></h1>
                <p>One thoughtful space to plan tuition, understand your spending and make cross-border money decisions with more confidence.</p>
                <div class="hero-actions"><a class="button button-primary button-large" href="{{ route('register') }}">Build your financial picture <span aria-hidden="true">↗</span></a><a class="text-link" href="#how-it-works">Explore the workspace <span aria-hidden="true">↓</span></a></div>
                <div class="trust-line"><span class="trust-icon">✓</span> Planning tools, not payment rails. Your money stays yours.</div>
            </div>
            <div class="hero-visual" aria-label="Illustration of a financial planning dashboard">
                <div class="orb orb-one"></div><div class="orb orb-two"></div>
                <div class="preview-card preview-top">
                    <div class="preview-label">ILLUSTRATIVE TUITION EXAMPLE <span class="mini-spark">↗</span></div>
                    <div class="preview-amount">8,400 <small>USD · example</small></div>
                    <div class="preview-meter"><i></i></div>
                    <div class="preview-meta"><span>Illustration only</span><span>Example progress: 72%</span></div>
                </div>
                <div class="preview-card preview-bottom">
                    <div class="preview-label">ONE CLEARER CONVERSION</div>
                    <div class="conversion-row"><span class="currency-chip">USD</span><span>1,250.00</span><span class="conversion-arrow">→</span><span class="currency-chip accent-chip">EUR</span><b>1,154.25</b></div>
                    <div class="preview-note"><span class="demo-dot"></span> Demonstration rate · not live</div>
                </div>
                <div class="orbit orbit-a"></div><div class="orbit orbit-b"></div>
                <div class="visual-caption"><span class="caption-dot"></span> A calmer view across currencies</div>
            </div>
        </section>
        <section class="value-strip wrap" id="how-it-works">
            <div><span class="value-number">01</span><h3>See the whole picture</h3><p>Bring budgets, tuition deadlines and savings goals into one personal view.</p></div>
            <div><span class="value-number">02</span><h3>Explore the what-ifs</h3><p>Change rates, fees and monthly contributions to understand possible outcomes.</p></div>
            <div><span class="value-number">03</span><h3>Choose with context</h3><p>Get clear assumptions and estimates, with no pretend transfers or hidden certainty.</p></div>
        </section>
        <section class="principles-section" id="principles"><div class="wrap principles-inner"><div><div class="eyebrow">BUILT FOR REAL-LIFE COMPLEXITY</div><h2>Useful guidance.<br><span>Honest boundaries.</span></h2></div><div class="principles-copy"><p>GlobeNest Atlas is a student finance planning prototype. It does not move money, provide regulated advice or guarantee an exchange rate.</p><div class="principle-tags"><span>Private by default</span><span>Human-confirmed actions</span><span>Estimates labeled clearly</span></div><a class="button button-outline" href="{{ route('register') }}">Start planning <span aria-hidden="true">↗</span></a></div></div></section>
    </main>
    <footer class="landing-footer wrap"><a class="brand" href="{{ route('home') }}"><span class="brand-mark">A</span><span>GlobeNest <b>Atlas</b></span></a><span>A student finance planning prototype. No payments are made.</span></footer>
</body>
</html>
