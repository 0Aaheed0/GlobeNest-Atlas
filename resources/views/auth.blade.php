<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $mode === 'login' ? 'Sign in' : 'Create your account' }} · GlobeNest Atlas</title>
    @include('partials.theme-init')
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="auth-page">
    <div class="auth-shell">
        <div class="auth-brand-row"><a class="brand" href="{{ route('home') }}"><span class="brand-mark">A</span><span>GlobeNest <b>Atlas</b></span></a><button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch to light theme">Light</button></div>
        <div class="auth-card">
            <div class="eyebrow">YOUR FINANCIAL SPACE, ACROSS BORDERS</div>
            <h1>{{ $mode === 'login' ? 'Welcome back.' : 'A clearer start.' }}</h1>
            <p class="muted">{{ $mode === 'login' ? 'Sign in to pick up where you left off.' : 'Create a private workspace for the financial details that matter to you.' }}</p>
            @if ($errors->any())
                <div class="alert alert-error" role="alert">{{ $errors->first() }}</div>
            @endif
            <form method="post" action="{{ $mode === 'login' ? route('login.store') : route('register.store') }}" class="form-stack">
                @csrf
                @if ($mode === 'register')
                    <label>Your name<input name="name" type="text" autocomplete="name" value="{{ old('name') }}" required maxlength="100" autofocus></label>
                @endif
                <label>Email address<input name="email" type="email" autocomplete="email" value="{{ old('email') }}" required maxlength="255" @if ($mode === 'login') autofocus @endif></label>
                <label>Password<input name="password" type="password" autocomplete="{{ $mode === 'login' ? 'current-password' : 'new-password' }}" required minlength="{{ $mode === 'register' ? 12 : 1 }}"></label>
                @if ($mode === 'register')
                    <label>Confirm password<input name="password_confirmation" type="password" autocomplete="new-password" required minlength="12"></label>
                    <p class="field-hint">Use at least 12 characters. Your account is for planning only; Atlas never handles payments.</p>
                @else
                    <label class="check-row"><input name="remember" type="checkbox" value="1"><span>Keep me signed in on this device</span></label>
                @endif
                <button class="button button-primary button-full" type="submit">{{ $mode === 'login' ? 'Sign in securely' : 'Create account' }} <span aria-hidden="true">↗</span></button>
            </form>
            <p class="auth-switch">{{ $mode === 'login' ? 'New to Atlas?' : 'Already have an account?' }} <a href="{{ $mode === 'login' ? route('register') : route('login') }}">{{ $mode === 'login' ? 'Create an account' : 'Sign in' }}</a></p>
        </div>
        <a class="back-home" href="{{ route('home') }}">← Back to GlobeNest Atlas</a>
    </div>
</body>
</html>
