<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;

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

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Those sign-in details could not be verified.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $code = (string) random_int(100000, 999999);

        // Store registration payload and code securely in session with expiration
        $request->session()->put('pending_registration', [
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'code' => $code,
            'expires_at' => now()->addMinutes(15)->timestamp,
        ]);

        // Attempt sending via Laravel Mailer, logging fallback for verification code
        try {
            Mail::raw(
                "Your GlobeNest Atlas verification code is: {$code}\n\nThis code will expire in 15 minutes. Enter this code to verify your email address and activate your private planning workspace.",
                function ($message) use ($data): void {
                    $message->to($data['email'])->subject('Your GlobeNest Atlas Verification Code');
                }
            );
        } catch (\Throwable $e) {
            Log::warning('Registration email could not be sent: '.$e->getMessage()." Code: {$code}");
        }

        return redirect()->route('register')
            ->with('verification_pending', true)
            ->with('pending_email', $data['email'])
            ->with('status', 'A 6-digit confirmation code has been generated. Please check your inbox (or mail logs).');
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

        if (now()->timestamp > ($pending['expires_at'] ?? 0)) {
            $request->session()->forget('pending_registration');

            return redirect()->route('register')->withErrors(['verification_code' => 'The verification code has expired. Please sign up again.']);
        }

        if (trim($request->input('verification_code')) !== (string) $pending['code']) {
            return redirect()->route('register')
                ->with('verification_pending', true)
                ->with('pending_email', $pending['email'])
                ->withErrors(['verification_code' => 'The verification code is incorrect. Please try again.']);
        }

        // Code is verified: create user account and mark email verified
        $user = User::create([
            'name' => $pending['name'],
            'email' => $pending['email'],
            'password' => $pending['password_hash'],
        ]);
        $user->email_verified_at = now();
        $user->save();

        $request->session()->forget('pending_registration');

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Email verified successfully! Welcome to your Atlas workspace.');
    }

    public function resendCode(Request $request): RedirectResponse
    {
        $pending = $request->session()->get('pending_registration');

        if (! $pending) {
            return redirect()->route('register');
        }

        $newCode = (string) random_int(100000, 999999);
        $pending['code'] = $newCode;
        $pending['expires_at'] = now()->addMinutes(15)->timestamp;
        $request->session()->put('pending_registration', $pending);

        try {
            Mail::raw(
                "Your new GlobeNest Atlas verification code is: {$newCode}\n\nThis code expires in 15 minutes.",
                function ($message) use ($pending): void {
                    $message->to($pending['email'])->subject('Your New GlobeNest Atlas Verification Code');
                }
            );
        } catch (\Throwable $e) {
            Log::warning('Resend email failed: '.$e->getMessage()." Code: {$newCode}");
        }

        return redirect()->route('register')
            ->with('verification_pending', true)
            ->with('pending_email', $pending['email'])
            ->with('status', 'A new verification code has been sent.');
    }

    public function cancelRegistration(Request $request): RedirectResponse
    {
        $request->session()->forget('pending_registration');

        return redirect()->route('register');
    }

    public function redirectToGoogle(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            Log::warning('Google OAuth failed: '.$e->getMessage());

            return redirect()->route('login')->withErrors([
                'email' => 'Google authentication failed or was cancelled. Please try again.',
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

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
