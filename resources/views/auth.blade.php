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
    <header class="auth-top-nav">
        <a class="back-pill" href="{{ route('home') }}">
            <span aria-hidden="true">←</span>
            <span>Back to GlobeNest</span>
        </a>
        <button class="theme-toggle-icon" type="button" data-theme-toggle aria-label="Toggle theme" title="Toggle theme">
            <span class="theme-icon-dark">☾</span>
            <span class="theme-icon-light">☼</span>
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
            @if (session('verification_pending'))
                {{-- Step 2: Verification Code Entry --}}
                <div class="auth-header-text">
                    <h1>Verify your email</h1>
                    <p>Enter the 6-digit confirmation code sent to <b>{{ session('pending_email') }}</b></p>
                </div>

                @if ($errors->any())
                    <div class="alert alert-error" role="alert">{{ $errors->first() }}</div>
                @endif
                @if (session('status'))
                    <div class="alert alert-success" role="status">{{ session('status') }}</div>
                @endif

                <form method="post" action="{{ route('register.verify') }}" class="form-stack">
                    @csrf
                    <label>
                        <span>Verification code</span>
                        <input name="verification_code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="123456" class="verify-code-input" required autofocus>
                    </label>

                    <button class="button button-primary button-full" type="submit">
                        <span>Verify &amp; create account</span>
                        <span aria-hidden="true">→</span>
                    </button>
                </form>

                <div class="auth-resend-row">
                    <form method="post" action="{{ route('register.resend') }}">
                        @csrf
                        <button class="text-button" type="submit">Didn't get a code? Resend code</button>
                    </form>
                    <form method="post" action="{{ route('register.cancel') }}">
                        @csrf
                        <button class="text-button text-muted" type="submit">Change email</button>
                    </form>
                </div>
            @else
                {{-- Standard Login / Register Form --}}
                <div class="auth-header-text">
                    <h1>{{ $mode === 'login' ? 'Welcome back' : 'Create your account' }}</h1>
                    <p>{{ $mode === 'login' ? 'Sign in to continue.' : 'Start your cross-border financial planning.' }}</p>
                </div>

                {{-- Continue with Google Button --}}
                <div class="social-auth-box">
                    <a href="{{ route('auth.google') }}" class="google-auth-btn">
                        <svg class="google-svg-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                            <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.665-5.17 3.665-9.17z"/>
                            <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.33 24 12 24z"/>
                            <path fill="#FBBC05" d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.18 0 9.98 0 12s.45 3.82 1.25 5.42l4.03-3.15z"/>
                            <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.33 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98z"/>
                        </svg>
                        <span>Continue with Google</span>
                    </a>
                </div>

                <div class="auth-divider">
                    <span>OR CONTINUE WITH EMAIL</span>
                </div>

                @if ($errors->any())
                    <div class="alert alert-error" role="alert">{{ $errors->first() }}</div>
                @endif
                @if (session('status'))
                    <div class="alert alert-success" role="status">{{ session('status') }}</div>
                @endif

                <form method="post" action="{{ $mode === 'login' ? route('login.store') : route('register.store') }}" class="form-stack">
                    @csrf
                    @if ($mode === 'register')
                        <label>
                            <span>Full name</span>
                            <input name="name" type="text" autocomplete="name" value="{{ old('name') }}" placeholder="Alex Morgan" required maxlength="100" autofocus>
                        </label>
                    @endif
                    <label>
                        <span>Email</span>
                        <input name="email" type="email" autocomplete="email" value="{{ old('email') }}" placeholder="you@example.com" required maxlength="255" @if ($mode === 'login') autofocus @endif>
                    </label>
                    <label>
                        <span class="label-row">
                            <span>Password</span>
                            @if ($mode === 'login')
                                <a href="javascript:void(0)" class="forgot-link" onclick="alert('Password recovery is available via admin or workspace reset.');">Forgot password?</a>
                            @endif
                        </span>
                        <div class="password-input-wrap">
                            <input id="auth-password" name="password" type="password" autocomplete="{{ $mode === 'login' ? 'current-password' : 'new-password' }}" placeholder="Enter your password" required minlength="{{ $mode === 'register' ? 8 : 1 }}">
                            <button type="button" class="toggle-password-btn" data-toggle-password="auth-password" aria-label="Toggle password visibility">
                                👁
                            </button>
                        </div>
                    </label>
                    @if ($mode === 'register')
                        <label>
                            <span>Confirm password</span>
                            <input name="password_confirmation" type="password" autocomplete="new-password" placeholder="Confirm your password" required minlength="8">
                        </label>
                        <p class="field-hint">Use at least 8 characters. An email confirmation code will be sent to verify your address.</p>
                    @else
                        <label class="check-row">
                            <input name="remember" type="checkbox" value="1">
                            <span>Remember me on this device</span>
                        </label>
                    @endif
                    <button class="button button-primary button-full auth-submit-btn" type="submit">
                        <span>{{ $mode === 'login' ? 'Sign in' : 'Create account' }}</span>
                    </button>
                </form>

                <p class="auth-switch">
                    {{ $mode === 'login' ? "Don't have an account?" : 'Already have an account?' }}
                    <a href="{{ $mode === 'login' ? route('register') : route('login') }}">{{ $mode === 'login' ? 'Sign up' : 'Sign in' }}</a>
                </p>
            @endif
        </div>
    </div>
</body>
</html>
