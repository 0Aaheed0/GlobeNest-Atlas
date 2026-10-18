<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Password recovery · GlobeNest Atlas</title>
    @include('partials.theme-init')
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="auth-page">
    <header class="auth-top-nav">
        <a class="back-pill" href="{{ route('login') }}">Back to sign in</a>
        <button class="theme-toggle-icon" type="button" data-theme-toggle aria-label="Toggle theme" title="Toggle theme">
            <span class="theme-icon-dark">◐</span>
            <span class="theme-icon-light">◑</span>
        </button>
    </header>

    <div class="auth-shell">
        <div class="auth-brand-center">
            <a class="brand brand-lg" href="{{ route('home') }}">
                <span class="brand-mark">A</span>
                <span>GlobeNest <b>Atlas</b></span>
            </a>
        </div>

        <div class="auth-card">
            <div class="auth-header-text">
                <h1>Recover your password</h1>
                <p>Request a one-time code by email, then choose a new password.</p>
            </div>

            @if ($errors->any())
                <div class="alert alert-error" role="alert">{{ $errors->first() }}</div>
            @endif
            @if (session('status'))
                <div class="alert alert-success" role="status">{{ session('status') }}</div>
            @endif

            <form method="post" action="{{ route('password.email') }}" class="form-stack">
                @csrf
                <label>
                    <span>Email</span>
                    <input name="email" type="email" autocomplete="email" value="{{ old('email', session('recovery_email')) }}" required maxlength="255">
                </label>
                <button class="button button-primary button-full" type="submit">Send recovery code</button>
            </form>

            @if (session('recovery_started'))
                <hr>
                <p class="field-hint">If an account matches that email, recovery instructions can be sent. Check your inbox and spam folder.</p>
                <form method="post" action="{{ route('password.recovery.verify') }}" class="form-stack">
                    @csrf
                    <label>
                        <span>Email</span>
                        <input name="email" type="email" autocomplete="email" value="{{ old('email', session('recovery_email')) }}" required maxlength="255">
                    </label>
                    <label>
                        <span>6-digit recovery code</span>
                        <input name="recovery_code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                    </label>
                    <label>
                        <span>New password</span>
                        <input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="4096" required>
                    </label>
                    <label>
                        <span>Confirm new password</span>
                        <input name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="4096" required>
                    </label>
                    <p class="field-hint">Use at least 12 characters, including uppercase and lowercase letters, a number, and a special character.</p>
                    <button class="button button-primary button-full" type="submit">Update password</button>
                </form>
            @endif
        </div>
    </div>
</body>
</html>
