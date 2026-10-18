<?php

namespace App\Http\Controllers;

use App\Mail\PasswordRecoveryCode;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class AuthController extends Controller
{
    public function loginForm(): View
    {
        return view('auth', ['mode' => 'login']);
    }

    public function registerForm(): View
    {
        return view('auth', ['mode' => 'register']);
    }

    public function passwordRecoveryForm(): View
    {
        return view('auth.password-recovery');
    }

    public function sendPasswordRecoveryCode(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);
        $email = mb_strtolower(trim($validated['email']));
        $user = User::where('email', $email)->first();

        if ($user) {
            $code = (string) random_int(100000, 999999);
            $codeHash = Hash::make($code);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => $codeHash, 'created_at' => now()],
            );

            try {
                if ($this->configuredMailerLogsMessages()) {
                    throw new \RuntimeException('OTP delivery cannot use a logging mailer.');
                }

                Mail::to($user->email)->send(new PasswordRecoveryCode($code));
            } catch (Throwable $exception) {
                DB::table('password_reset_tokens')->where('email', $email)->where('token', $codeHash)->delete();
                Log::warning('Password recovery email could not be delivered.', [
                    'exception_type' => $exception::class,
                ]);
            }
        }

        $request->session()->put([
            'recovery_started' => true,
            'recovery_email' => $email,
        ]);

        return redirect()->route('password.request')
            ->with('status', 'If an account matches that email, recovery instructions can be sent. If no message arrives, check spam or try again later.');
    }

    public function verifyPasswordRecoveryCode(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'recovery_code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'string', 'max:4096', Password::min(12)->mixedCase()->numbers()->symbols(), 'confirmed'],
        ]);

        $email = $validated['email'];
        $result = DB::transaction(function () use ($email, $validated): string {
            $token = DB::table('password_reset_tokens')->where('email', $email)->lockForUpdate()->first();

            if (! $token) {
                return 'invalid';
            }

            if (! $token->created_at || now()->greaterThanOrEqualTo(Carbon::parse($token->created_at)->addMinutes(15))) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();

                return 'expired';
            }

            if (! Hash::check($validated['recovery_code'], $token->token)) {
                return 'invalid';
            }

            $user = User::where('email', $email)->lockForUpdate()->first();

            if (! $user) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();

                return 'invalid';
            }

            $user->password = Hash::make($this->prehashPassword($validated['password']));
            $user->setRememberToken(Str::random(60));
            $user->save();
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            if (config('session.driver') === 'database') {
                DB::table(config('session.table'))->where('user_id', $user->id)->delete();
            }

            return 'updated';
        });

        if ($result === 'updated') {
            $request->session()->forget(['recovery_started', 'recovery_email']);

            return redirect()->route('login')->with('status', 'Your password has been updated. Please sign in.');
        }

        $request->session()->put([
            'recovery_started' => true,
            'recovery_email' => $email,
        ]);

        return redirect()->route('password.request')
            ->withErrors(['recovery_code' => 'That recovery code has expired or is invalid. Request a new code and try again.']);
    }

    public function login(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
        ]);

        $user = User::where('email', $credentials['email'])->first();
        $password = $credentials['password'];
        $prehashedPassword = $this->prehashPassword($password);
        $isPrehashedPassword = $user !== null && Hash::check($prehashedPassword, $user->password);
        $isLegacyPassword = $user !== null
            && ! $isPrehashedPassword
            && Hash::check($password, $user->password);

        if (! $user || (! $isPrehashedPassword && ! $isLegacyPassword)) {
            throw ValidationException::withMessages([
                'email' => 'Those sign-in details could not be verified.',
            ]);
        }

        if ($isLegacyPassword && strlen($password) <= 72) {
            $user->password = Hash::make($prehashedPassword);
            $user->save();
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function register(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'max:4096', Password::min(12)->mixedCase()->numbers()->symbols(), 'confirmed'],
        ]);

        $code = (string) random_int(100000, 999999);

        $request->session()->put('pending_registration', [
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => Hash::make($this->prehashPassword($data['password'])),
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(15)->timestamp,
        ]);

        $emailSent = $this->sendVerificationCode(
            $data['email'],
            $code,
            'Your GlobeNest Atlas Verification Code',
            'This code will expire in 15 minutes. Enter it to verify your email address and activate your private planning workspace.',
        );

        return redirect()->route('register')
            ->with('verification_pending', true)
            ->with('pending_email', $data['email'])
            ->with(
                $emailSent ? 'status' : 'mail_error',
                $emailSent
                    ? 'A 6-digit verification code has been sent. Please check your inbox.'
                    : 'We could not send a verification code. Please try again later.',
            );
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        $request->validate([
            'verification_code' => ['required', 'string', 'size:6'],
        ]);

        $pending = $request->session()->get('pending_registration');

        if (! $pending) {
            return redirect()->route('register')->withErrors(['verification_code' => 'No registration session found. Please fill in the registration form.']);
        }

        $verificationLimiter = $this->registrationVerificationLimiterKey($pending['email']);

        try {
            return Cache::lock($verificationLimiter.':lock', 10)->block(5, function () use ($request, $pending, $verificationLimiter): RedirectResponse {
                if (RateLimiter::tooManyAttempts($verificationLimiter, 5)) {
                    return $this->verificationError(
                        $pending,
                        sprintf('Too many incorrect codes. Please try again in %d seconds.', max(1, RateLimiter::availableIn($verificationLimiter))),
                    );
                }

                if (now()->timestamp > ($pending['expires_at'] ?? 0)) {
                    $request->session()->forget('pending_registration');

                    return redirect()->route('register')->withErrors([
                        'verification_code' => 'The verification code has expired. Please request a new code.',
                    ]);
                }

                if (! Hash::check(trim($request->input('verification_code')), $pending['code_hash'] ?? '')) {
                    RateLimiter::hit($verificationLimiter, 900);

                    return $this->verificationError($pending, 'The verification code is incorrect. Please try again.');
                }

                $user = User::create([
                    'name' => $pending['name'],
                    'email' => $pending['email'],
                    'password' => $pending['password_hash'],
                ]);
                $user->email_verified_at = now();
                $user->save();

                RateLimiter::clear($verificationLimiter);
                $request->session()->forget('pending_registration');
                Auth::login($user);
                $request->session()->regenerate();

                return redirect()->route('dashboard')->with('status', 'Email verified successfully! Welcome to your Atlas workspace.');
            });
        } catch (LockTimeoutException) {
            return $this->verificationError($pending, 'Verification is temporarily busy. Please try again.');
        }
    }

    public function resendCode(Request $request): RedirectResponse
    {
        $pending = $request->session()->get('pending_registration');

        if (! $pending) {
            return redirect()->route('register');
        }

        $newCode = (string) random_int(100000, 999999);
        $pending['code_hash'] = Hash::make($newCode);
        $pending['expires_at'] = now()->addMinutes(15)->timestamp;
        $request->session()->put('pending_registration', $pending);

        $emailSent = $this->sendVerificationCode(
            $pending['email'],
            $newCode,
            'Your New GlobeNest Atlas Verification Code',
            'This code expires in 15 minutes.',
        );

        return redirect()->route('register')
            ->with('verification_pending', true)
            ->with('pending_email', $pending['email'])
            ->with(
                $emailSent ? 'status' : 'mail_error',
                $emailSent ? 'A new verification code has been sent.' : 'We could not send a verification code. Please try again later.',
            );
    }

    public function cancelRegistration(Request $request): RedirectResponse
    {
        $request->session()->forget('pending_registration');

        return redirect()->route('register');
    }

    public function redirectToGoogle(): RedirectResponse
    {
        return $this->getGoogleDriver()->redirect();
    }

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        try {
            $googleUser = $this->getGoogleDriver()->user();
        } catch (Throwable $exception) {
            Log::warning('Google OAuth failed.', ['exception_type' => $exception::class]);

            return redirect()->route('login')->withErrors([
                'email' => 'Google authentication failed or was cancelled. Please try again.',
            ]);
        }

        if (($googleUser->getRaw()['email_verified'] ?? null) !== true) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google could not verify this email address. Please use a verified Google account.',
            ]);
        }

        $email = $googleUser->getEmail();
        if (! $email) {
            return redirect()->route('login')->withErrors([
                'email' => 'Unable to retrieve your email address from Google.',
            ]);
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'name' => $googleUser->getName() ?: ($googleUser->getNickname() ?: 'Atlas User'),
                'email' => $email,
                'password' => Hash::make(Str::random(32)),
                'email_verified_at' => now(),
            ]);
        } elseif (! $user->email_verified_at) {
            $user->email_verified_at = now();
            $user->save();
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    protected function getGoogleDriver()
    {
        $driver = Socialite::driver('google');

        if (method_exists($driver, 'setHttpClient') && is_file(base_path('certs/cacert.pem'))) {
            $driver->setHttpClient(new Client([
                'verify' => base_path('certs/cacert.pem'),
                'timeout' => 25,
            ]));
        }

        return $driver;
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function prehashPassword(string $password): string
    {
        return hash('sha256', $password);
    }

    private function registrationVerificationLimiterKey(string $email): string
    {
        $normalizedEmail = mb_strtolower(trim($email));

        return 'registration:verification:'.hash_hmac('sha256', $normalizedEmail, (string) config('app.key'));
    }

    private function verificationError(array $pending, string $message): RedirectResponse
    {
        return redirect()->route('register')
            ->with('verification_pending', true)
            ->with('pending_email', $pending['email'])
            ->withErrors(['verification_code' => $message]);
    }

    private function sendVerificationCode(string $email, string $code, string $subject, string $instructions): bool
    {
        try {
            if ($this->configuredMailerLogsMessages()) {
                throw new \RuntimeException('OTP delivery cannot use a logging mailer.');
            }

            Mail::raw(
                "Your GlobeNest Atlas verification code is: {$code}\n\n{$instructions}",
                function ($message) use ($email, $subject): void {
                    $message->to($email)->subject($subject);
                }
            );
        } catch (Throwable $exception) {
            Log::warning('Registration verification email could not be delivered.', [
                'exception_type' => $exception::class,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, true>  $visited
     */
    private function mailerLogsMessages(string $mailerName, array $visited = []): bool
    {
        if (isset($visited[$mailerName])) {
            return false;
        }

        $visited[$mailerName] = true;
        $mailer = config('mail.mailers.'.$mailerName, []);

        if (! is_array($mailer)) {
            return false;
        }

        if (($mailer['transport'] ?? null) === 'log') {
            return true;
        }

        foreach ($mailer['mailers'] ?? [] as $nestedMailer) {
            if (is_string($nestedMailer) && $this->mailerLogsMessages($nestedMailer, $visited)) {
                return true;
            }
        }

        return false;
    }

    private function configuredMailerLogsMessages(): bool
    {
        return $this->mailerLogsMessages((string) config('mail.default'));
    }
}
