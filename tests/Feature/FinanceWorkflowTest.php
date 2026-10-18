<?php

namespace Tests\Feature;

use App\Models\StudentProfile;
use App\Models\User;
use App\Services\CurrencyCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class FinanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_and_authentication_pages_render(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('Your next chapter.')->assertSee('data-theme-toggle');
        $this->get(route('login'))->assertOk()->assertSee('Welcome back')->assertSee('Remember me on this device')->assertSee('data-theme-toggle');
        $this->get(route('register'))->assertOk()->assertSee('Create your account')->assertSee('data-theme-toggle');
    }

    public function test_google_oauth_redirect(): void
    {
        $response = $this->get(route('auth.google'));
        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', (string) $response->headers->get('Location'));
    }

    public function test_google_oauth_callback_creates_and_authenticates_user(): void
    {
        $abstractUser = \Mockery::mock(\Laravel\Socialite\Two\User::class);
        $abstractUser->shouldReceive('getId')->andReturn('1234567890');
        $abstractUser->shouldReceive('getEmail')->andReturn('googleuser@example.test');
        $abstractUser->shouldReceive('getName')->andReturn('Google Explorer');
        $abstractUser->shouldReceive('getNickname')->andReturn('explorer');
        $abstractUser->shouldReceive('getRaw')->andReturn(['email_verified' => true]);

        $provider = \Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($abstractUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));
        $response->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'googleuser@example.test',
            'name' => 'Google Explorer',
        ]);
        $user = User::where('email', 'googleuser@example.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_dashboard_requires_authentication_and_registration_starts_onboarding(): void
    {
        $this->get('/app')->assertRedirect(route('login'));
        $sessionIdBeforeRegistration = session()->getId();
        $password = 'Very-secure-pass1!';

        $this->post(route('register.store'), [
            'name' => 'Student One',
            'email' => 'student@example.test',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertRedirect(route('register'));

        $this->assertTrue(session()->has('pending_registration'));
        $code = $this->lastVerificationCode();
        $this->assertArrayHasKey('code_hash', session('pending_registration'));
        $this->assertArrayNotHasKey('code', session('pending_registration'));

        $this->post(route('register.verify'), [
            'verification_code' => $code,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertNotSame($sessionIdBeforeRegistration, session()->getId());
        $registeredUser = User::where('email', 'student@example.test')->firstOrFail();
        $this->assertNotSame($password, $registeredUser->password);
        $this->assertTrue(Hash::check(hash('sha256', $password), $registeredUser->password));
        $this->get(route('dashboard'))->assertOk()->assertSee('Welcome to your financial map.');
        $sessionIdBeforeLogout = session()->getId();
        $this->post(route('logout'))->assertRedirect(route('home'));
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertNotSame($sessionIdBeforeLogout, session()->getId());
        $sessionIdBeforeLogin = session()->getId();
        $this->post(route('login.store'), [
            'email' => 'student@example.test',
            'password' => $password,
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertNotSame($sessionIdBeforeLogin, session()->getId());
    }

    public function test_invalid_login_is_rejected_and_repeated_attempts_are_rate_limited(): void
    {
        User::factory()->create([
            'email' => 'rate-limit@example.test',
            'password' => 'a-different-password',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), [
                'email' => 'rate-limit@example.test',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $blockedResponse = $this->post(route('login.store'), [
            'email' => 'rate-limit@example.test',
            'password' => 'wrong-password',
        ]);
        $blockedResponse->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many sign-in attempts', session('errors')->first('email'));

        $this->assertGuest();
    }

    public function test_login_remember_me_can_restore_a_session_and_logout_revokes_the_old_cookie(): void
    {
        config([
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);

        $user = User::factory()->create([
            'email' => 'remember-login@example.test',
            'password' => 'remember-test-password',
            'remember_token' => null,
        ]);
        $recallerName = Auth::guard('web')->getRecallerName();

        $loginResponse = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'remember-test-password',
            'remember' => '1',
        ]);

        $loginResponse->assertRedirect(route('dashboard'))
            ->assertCookieNotExpired($recallerName);

        $rememberCookie = $loginResponse->getCookie($recallerName);
        $this->assertNotNull($rememberCookie);
        $this->assertTrue($rememberCookie->isSecure());
        $this->assertTrue($rememberCookie->isHttpOnly());
        $this->assertSame('lax', $rememberCookie->getSameSite());

        $user->refresh();
        $rotatedRememberToken = $user->getRememberToken();
        $this->assertNotEmpty($rotatedRememberToken);

        $this->flushSession();
        Auth::forgetGuards();
        $this->withCookie($recallerName, $rememberCookie->getValue())
            ->get(route('dashboard'))
            ->assertOk();
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'))
            ->assertRedirect(route('home'))
            ->assertCookieExpired($recallerName);
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $user->refresh();
        $this->assertNotSame($rotatedRememberToken, $user->getRememberToken());

        $this->flushSession();
        Auth::forgetGuards();
        $this->withCookie($recallerName, $rememberCookie->getValue())
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_without_remember_me_does_not_issue_a_persistent_cookie(): void
    {
        $user = User::factory()->create([
            'email' => 'no-remember-login@example.test',
            'password' => 'remember-test-password',
            'remember_token' => null,
        ]);
        $recallerName = Auth::guard('web')->getRecallerName();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'remember-test-password',
        ])->assertRedirect(route('dashboard'))
            ->assertCookieMissing($recallerName);

        $user->refresh();
        $this->assertSame('', $user->getRememberToken());
    }

    public function test_authenticated_workspace_logout_control_requires_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-confirm-logout', false)
            ->assertSee('aria-label="Sign out"', false);
    }

    public function test_registration_rejects_passwords_missing_each_required_character_group(): void
    {
        $invalidPasswords = [
            'Short1!a',
            'lowercasepass1!',
            'UPPERCASEPASS1!',
            'MixedCasePass!!',
            'MixedCasePass1',
        ];

        foreach ($invalidPasswords as $index => $password) {
            $this->post(route('register.store'), [
                'name' => 'Student One',
                'email' => "invalid-password-{$index}@example.test",
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertSessionHasErrors('password');
        }
    }

    public function test_google_rejects_false_and_missing_email_verified_claims(): void
    {
        foreach ([['email_verified' => false], []] as $index => $claim) {
            $email = "unverified-{$index}@example.test";
            $abstractUser = \Mockery::mock(\Laravel\Socialite\Two\User::class);
            $abstractUser->shouldReceive('getEmail')->andReturn($email);
            $abstractUser->shouldReceive('getRaw')->andReturn($claim);

            $provider = \Mockery::mock(Provider::class);
            $provider->shouldReceive('user')->andReturn($abstractUser);
            Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

            $this->get(route('auth.google.callback'))
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors('email');
            $this->assertGuest();
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }

    public function test_registration_otp_expires(): void
    {
        $this->startValidRegistration('expired@example.test');
        $this->travel(16)->minutes();

        $this->post(route('register.verify'), ['verification_code' => $this->lastVerificationCode()])
            ->assertSessionHasErrors('verification_code');

        $this->assertDatabaseMissing('users', ['email' => 'expired@example.test']);
        $this->assertFalse(session()->has('pending_registration'));
    }

    public function test_incorrect_registration_otps_are_throttled(): void
    {
        $this->startValidRegistration('incorrect-code@example.test');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('register.verify'), ['verification_code' => '000000'])
                ->assertSessionHasErrors('verification_code');
        }

        $blockedResponse = $this->post(route('register.verify'), ['verification_code' => '000000']);
        $blockedResponse->assertSessionHasErrors('verification_code');
        $this->assertStringContainsString('Too many incorrect codes', session('errors')->first('verification_code'));
        $this->assertDatabaseMissing('users', ['email' => 'incorrect-code@example.test']);
    }

    public function test_registration_resends_are_throttled(): void
    {
        $this->startValidRegistration('resend-limit@example.test');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('register.resend'))->assertRedirect(route('register'));
        }

        $blockedResponse = $this->post(route('register.resend'));
        $blockedResponse->assertSessionHasErrors('verification_code');
        $this->assertStringContainsString('Too many code requests', session('errors')->first('verification_code'));
    }

    public function test_failed_verification_email_does_not_log_the_otp(): void
    {
        $otp = null;
        Mail::shouldReceive('raw')->once()->andReturnUsing(function (string $message) use (&$otp): void {
            preg_match('/(?<!\\d)(\\d{6})(?!\\d)/', $message, $matches);
            $otp = $matches[1] ?? null;
            throw new \RuntimeException('Mail transport failed');
        });
        Log::spy();

        $this->post(route('register.store'), [
            'name' => 'Mail Failure',
            'email' => 'mail-failure@example.test',
            'password' => 'Very-secure-pass1!',
            'password_confirmation' => 'Very-secure-pass1!',
        ])->assertRedirect(route('register'));

        $this->assertNotNull($otp);
        Log::shouldHaveReceived('warning')->with(
            'Registration verification email could not be delivered.',
            \Mockery::on(fn (array $context): bool => ! str_contains(json_encode($context), $otp)),
        )->once();
    }

    public function test_login_ip_limit_allows_twenty_attempts_and_throttles_the_next_request(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->post(route('login.store'), [
                'email' => "ip-limit-{$attempt}@example.test",
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $blockedResponse = $this->post(route('login.store'), [
            'email' => 'ip-limit-final@example.test',
            'password' => 'wrong-password',
        ]);
        $blockedResponse->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many sign-in attempts', session('errors')->first('email'));
    }

    public function test_session_encryption_is_enabled(): void
    {
        $this->assertTrue((bool) config('session.encrypt'));
    }

    public function test_dashboard_resilience_snapshot_keeps_foreign_tuition_separate_and_explains_the_projection(): void
    {
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'CAD',
            'monthly_budget' => '2000.00',
            'monthly_income' => '3500.00',
        ]);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Housing',
            'description' => 'Current month rent',
            'amount' => '1500.00',
            'currency' => 'CAD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Books',
            'description' => 'Foreign currency books',
            'amount' => '100.00',
            'currency' => 'EUR',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $user->tuitionPlans()->create([
            'title' => 'Overseas tuition',
            'amount' => '12000.00',
            'saved_amount' => '2000.00',
            'currency' => 'USD',
            'due_date' => today()->addDays(120),
            'installments' => 4,
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Cross-border financial resilience')
            ->assertSee('Study destination: Canada')
            ->assertSee('Home currency: CAD')
            ->assertSee('Outstanding: USD')
            ->assertSee('10000.00')
            ->assertSee('3420.50')
            ->assertSee('FX +10% illustration')
            ->assertSee('Atlas demonstration rate table; not a live market quote')
            ->assertSee('First projected financial shortfall: tuition contribution gap in month 1.')
            ->assertSee('Monthly budget − current-month recorded expenses')
            ->assertSee('Current-month recorded home-currency expenses repeat as the monthly spending baseline');
    }

    public function test_dashboard_flight_plan_compares_supported_stresses_and_shows_only_owned_context(): void
    {
        $this->travelTo(today()->startOfDay());
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '2000.00',
            'monthly_income' => '1000.00',
        ]);
        $user->transactions()->createMany([
            [
                'kind' => 'expense',
                'category' => 'Housing',
                'description' => 'Current rent',
                'amount' => '400.00',
                'currency' => 'USD',
                'occurred_on' => today(),
                'is_simulated' => false,
            ],
            [
                'kind' => 'expense',
                'category' => 'Food',
                'description' => 'Groceries',
                'amount' => '400.00',
                'currency' => 'USD',
                'occurred_on' => today(),
                'is_simulated' => false,
            ],
        ]);
        $user->tuitionPlans()->create([
            'title' => 'Winter tuition',
            'amount' => '6000.00',
            'saved_amount' => '1000.00',
            'currency' => 'USD',
            'due_date' => today()->addDays(120),
            'installments' => 4,
        ]);
        $user->savingsGoals()->create([
            'title' => 'Textbook fund',
            'target_amount' => '1000.00',
            'current_amount' => '250.00',
            'currency' => 'USD',
            'target_date' => today()->addDays(90),
        ]);
        $user->budgets()->createMany([
            [
                'category' => 'Housing',
                'amount' => '450.00',
                'currency' => 'USD',
                'period_start' => today()->startOfMonth(),
                'period_end' => today()->endOfMonth(),
            ],
            [
                'category' => 'Food',
                'amount' => '350.00',
                'currency' => 'USD',
                'period_start' => today()->startOfMonth(),
                'period_end' => today()->endOfMonth(),
            ],
        ]);
        $user->fundingOpportunities()->create([
            'title' => 'University award',
            'provider' => 'Student support office',
            'potential_amount' => '10000.00',
            'currency' => 'USD',
            'deadline' => today()->addDays(30),
            'status' => 'awarded',
        ]);
        $other = User::factory()->create();
        $other->savingsGoals()->create([
            'title' => 'Private other-user goal',
            'target_amount' => '9000.00',
            'current_amount' => '0.00',
            'currency' => 'USD',
        ]);
        $other->fundingOpportunities()->create([
            'title' => 'Private other-user award',
            'provider' => 'Other account',
            'potential_amount' => '8000.00',
            'currency' => 'USD',
            'deadline' => today()->addDays(20),
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Student Financial Flight Plan')
            ->assertSee('As of '.today()->toDateString())
            ->assertSee('across 4 monthly periods')
            ->assertSee('across 18 weekly periods')
            ->assertSee('Saved expected monthly income: USD 1000.00 (already included in the monthly budget assumption).')
            ->assertSee('Housing: USD 400.00 recorded against USD 450.00')
            ->assertSee('Food: USD 400.00 recorded against USD 350.00')
            ->assertSee('Textbook fund')
            ->assertSee('USD 750.00 remaining')
            ->assertSee('University award')
            ->assertSee('Potential value: USD 10000.00. Not counted as confirmed funding.')
            ->assertSee('Tuition shortfall: USD 200.00 · change Baseline.')
            ->assertSee('Tuition shortfall: USD 360.00 · change +160.00.')
            ->assertSee('Tuition shortfall: USD 1200.00 · change +1000.00.')
            ->assertSee('Current recorded spending leaves 1200.00 USD before tuition saving, against a scheduled 1250.00 USD per month. The difference is 50.00 USD per month;')
            ->assertSee('Discuss this flight plan with Atlas')
            ->assertDontSee('Private other-user goal')
            ->assertDontSee('Private other-user award')
            ->assertDontSee('8000.00 USD');

        $this->assertDatabaseCount('tuition_plans', 1);
        $this->assertDatabaseCount('savings_goals', 2);
        $this->assertDatabaseCount('budgets', 2);
    }

    public function test_dashboard_flight_plan_omits_unavailable_rent_stress_without_inventing_rent(): void
    {
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1800.00',
            'monthly_income' => '500.00',
        ]);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Food',
            'description' => 'Groceries only',
            'amount' => '500.00',
            'currency' => 'USD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $user->tuitionPlans()->create([
            'title' => 'Tuition plan without rent data',
            'amount' => '3000.00',
            'saved_amount' => '500.00',
            'currency' => 'USD',
            'due_date' => today()->addDays(120),
            'installments' => 2,
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No current-month home-currency Rent or Housing expense is recorded, so a rent-increase stress scenario is not calculated.')
            ->assertSee('Income delayed 1 month')
            ->assertDontSee('Rent +10%');
    }

    public function test_dashboard_shows_insufficient_data_and_never_reads_another_users_readiness_records(): void
    {
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'CAD',
            'monthly_budget' => '1800.00',
            'monthly_income' => '0.00',
        ]);
        $other = User::factory()->create();
        $other->tuitionPlans()->create([
            'title' => 'Private other-user tuition',
            'amount' => '9000.00',
            'saved_amount' => '1000.00',
            'currency' => 'USD',
            'due_date' => today()->addDays(90),
            'installments' => 3,
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Insufficient data for a baseline forecast.')
            ->assertSee('No current-month expense records in the home currency')
            ->assertSee('No tuition plans are recorded.')
            ->assertDontSee('Private other-user tuition')
            ->assertDontSee('9000.00 USD');
    }

    public function test_first_time_dashboard_requires_currency_choice_and_displays_currency_names_in_help(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('HELP & FAQ')
            ->assertSee('First-time guide')
            ->assertSee('BDT — Bangladeshi taka')
            ->assertSee('<option value="" selected disabled>Choose your home currency</option>', false)
            ->assertSee('<option value="USD">USD — United States dollar</option>', false);
    }

    public function test_profile_requires_valid_supported_currency_and_stores_privacy_consent(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.save'), [
            'destination_country' => 'Canada',
            'home_currency' => 'XYZ',
            'monthly_budget' => '1800.00',
        ])->assertSessionHasErrors('home_currency');

        $this->actingAs($user)->put(route('profile.save'), [
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1800.00',
            'monthly_income' => '2500.00',
            'financial_goals' => 'Build an emergency fund',
            'memory_consent' => '1',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $user->id,
            'home_currency' => 'USD',
            'memory_consent' => 1,
        ]);
    }

    public function test_dashboard_and_financial_activity_are_isolated_per_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        StudentProfile::create([
            'user_id' => $owner->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1800.00',
        ]);
        $owner->transactions()->create([
            'kind' => 'expense',
            'category' => 'Housing',
            'description' => 'Owner rent',
            'amount' => '800.00',
            'currency' => 'USD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $other->transactions()->create([
            'kind' => 'expense',
            'category' => 'Tuition',
            'description' => 'Other person record',
            'amount' => '999.00',
            'currency' => 'USD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Scenario lab')
            ->assertSee('name="monthly_rent"', false)
            ->assertSee('name="scholarship_month"', false)
            ->assertSee('name="rate_stress_percent"', false)
            ->assertSee('Owner rent')
            ->assertDontSee('Other person record')
            ->assertSee('800.00');
    }

    public function test_dashboard_escapes_user_controlled_financial_and_conversation_text(): void
    {
        $user = User::factory()->create();
        $payload = '<script>alert(1)</script>';
        $user->savingsGoals()->create([
            'title' => $payload,
            'target_amount' => '500.00',
            'current_amount' => '0.00',
            'currency' => 'USD',
        ]);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Housing',
            'description' => $payload,
            'amount' => '100.00',
            'currency' => 'USD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Escaping check']);
        $conversation->messages()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $payload,
        ]);

        $this->actingAs($user)->get(route('dashboard', ['conversation_id' => $conversation->id]))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee($payload, false);
    }

    public function test_dashboard_derives_financial_warnings_from_owned_records_and_restores_pending_approvals(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'USD',
            'monthly_budget' => '1000.00',
        ]);
        $user->transactions()->create([
            'kind' => 'expense',
            'category' => 'Housing',
            'description' => 'Rent entry',
            'amount' => '900.00',
            'currency' => 'USD',
            'occurred_on' => today(),
            'is_simulated' => false,
        ]);
        $user->tuitionPlans()->create([
            'title' => 'Upcoming tuition',
            'amount' => '5000.00',
            'saved_amount' => '0.00',
            'currency' => 'USD',
            'due_date' => today()->addDays(20),
            'installments' => 2,
        ]);
        $other->tuitionPlans()->create([
            'title' => 'Other account overdue tuition',
            'amount' => '9000.00',
            'saved_amount' => '0.00',
            'currency' => 'USD',
            'due_date' => today()->subDays(3),
            'installments' => 1,
        ]);
        $conversation = $user->agentConversations()->create(['title' => 'Review my plan']);
        $pending = $user->agentPendingActions()->create([
            'conversation_id' => $conversation->id,
            'tool_name' => 'create_savings_goal',
            'arguments' => ['title' => 'Emergency fund', 'target_amount' => '1200.00', 'currency' => 'USD'],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);
        $conversation->messages()->create([
            'user_id' => $user->id,
            'role' => 'assistant',
            'content' => 'Review this draft before saving.',
            'tool_result' => [[
                'name' => 'create_savings_goal',
                'result' => [
                    'confirmation_required' => true,
                    'pending_action' => [
                        'id' => $pending->id,
                        'name' => 'create_savings_goal',
                        'arguments' => $pending->arguments,
                    ],
                ],
            ]],
        ]);

        $this->actingAs($user)
            ->get(route('dashboard', ['conversation_id' => $conversation->id]))
            ->assertOk()
            ->assertSee('Financial signals')
            ->assertSee('used at least 80%')
            ->assertSee('Upcoming tuition is due soon')
            ->assertSee('Pending approvals')
            ->assertSee('Emergency fund')
            ->assertSee('data-display-tool-results')
            ->assertSee('Confirm and save')
            ->assertDontSee('Other account overdue tuition');
    }

    public function test_manual_activity_is_saved_as_a_record_and_never_claimed_as_a_transfer(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('transactions.store'), [
            'kind' => 'expense',
            'category' => 'Housing',
            'description' => 'Rent record',
            'amount' => '800.00',
            'currency' => 'USD',
            'occurred_on' => today()->toDateString(),
            'is_simulated' => '1',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'description' => 'Rent record',
            'is_simulated' => 1,
        ]);
        $this->get(route('dashboard'))->assertSee('Simulated');
    }

    public function test_unchecked_simulation_flag_creates_a_manual_record_not_a_simulated_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('transactions.store'), [
            'kind' => 'income',
            'category' => 'Scholarship',
            'description' => 'Scholarship record',
            'amount' => '1000.00',
            'currency' => 'USD',
            'occurred_on' => today()->toDateString(),
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'description' => 'Scholarship record',
            'is_simulated' => 0,
        ]);
    }

    public function test_tuition_goal_and_category_budget_are_saved_with_validation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('tuition.store'), [
            'title' => 'Autumn tuition',
            'amount' => '1200.00',
            'saved_amount' => '150.00',
            'currency' => 'USD',
            'due_date' => today()->addDays(90)->toDateString(),
            'installments' => 2,
        ])->assertSessionHas('status');

        $this->actingAs($user)->post(route('goals.store'), [
            'title' => 'Travel buffer',
            'target_amount' => '500.00',
            'current_amount' => '100.00',
            'currency' => 'USD',
            'target_date' => today()->addMonths(4)->toDateString(),
        ])->assertSessionHas('status');

        $this->actingAs($user)->post(route('budgets.store'), [
            'category' => 'Books',
            'amount' => '200.00',
            'currency' => 'USD',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('tuition_plans', ['user_id' => $user->id, 'installments' => 2]);
        $this->assertDatabaseHas('savings_goals', ['user_id' => $user->id, 'title' => 'Travel buffer']);
        $this->assertDatabaseHas('budgets', ['user_id' => $user->id, 'category' => 'Books']);
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('View generated monthly installment dates')
            ->assertSee('Record savings progress');

        $this->actingAs($user)->post(route('tuition.store'), [
            'title' => 'Invalid tuition',
            'amount' => '100.00',
            'saved_amount' => '101.00',
            'currency' => 'USD',
            'due_date' => today()->addDays(90)->toDateString(),
            'installments' => 1,
        ])->assertSessionHasErrors('saved_amount');
    }

    public function test_tuition_and_goal_progress_are_user_scoped_and_cannot_exceed_targets(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $tuition = $user->tuitionPlans()->create([
            'title' => 'Term tuition',
            'amount' => '1000.00',
            'saved_amount' => '200.00',
            'currency' => 'USD',
            'due_date' => today()->addMonths(4),
            'installments' => 2,
        ]);
        $goal = $user->savingsGoals()->create([
            'title' => 'Travel buffer',
            'target_amount' => '500.00',
            'current_amount' => '100.00',
            'currency' => 'USD',
        ]);
        $foreignTuition = $other->tuitionPlans()->create([
            'title' => 'Private tuition',
            'amount' => '1000.00',
            'saved_amount' => '0.00',
            'currency' => 'USD',
            'due_date' => today()->addMonths(4),
            'installments' => 1,
        ]);
        $foreignGoal = $other->savingsGoals()->create([
            'title' => 'Private goal',
            'target_amount' => '500.00',
            'current_amount' => '0.00',
            'currency' => 'USD',
        ]);

        $this->actingAs($user)->post(route('tuition.progress', $tuition), [
            'additional_amount' => '300.00',
        ])->assertSessionHas('status');
        $this->assertDatabaseHas('tuition_plans', ['id' => $tuition->id, 'saved_amount' => '500.00']);

        $this->actingAs($user)->post(route('tuition.progress', $tuition), [
            'additional_amount' => '600.00',
        ])->assertSessionHasErrors('additional_amount');
        $this->assertDatabaseHas('tuition_plans', ['id' => $tuition->id, 'saved_amount' => '500.00']);

        $this->actingAs($user)->post(route('goals.progress', $goal), [
            'additional_amount' => '50.00',
        ])->assertSessionHas('status');
        $this->assertDatabaseHas('savings_goals', ['id' => $goal->id, 'current_amount' => '150.00']);

        $this->actingAs($user)->post(route('tuition.progress', $foreignTuition), [
            'additional_amount' => '100.00',
        ])->assertNotFound();
        $this->actingAs($user)->post(route('goals.progress', $foreignGoal), [
            'additional_amount' => '100.00',
        ])->assertNotFound();
        $this->assertDatabaseHas('savings_goals', [
            'id' => $foreignGoal->id,
            'user_id' => $other->id,
            'current_amount' => '0.00',
        ]);
    }

    public function test_funding_opportunities_are_user_entered_validated_and_owner_scoped(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->post(route('funding-opportunities.store'), [
            'title' => 'International student grant',
            'provider' => 'University funding office',
            'potential_amount' => '2500.00',
            'currency' => 'USD',
            'deadline' => today()->addMonths(2)->toDateString(),
            'status' => 'preparing',
            'source_url' => 'https://example.edu/funding',
        ])->assertSessionHas('status');

        $opportunity = $user->fundingOpportunities()->firstOrFail();
        $this->assertSame('2500.00', $opportunity->potential_amount);
        $this->assertDatabaseHas('funding_opportunities', [
            'user_id' => $user->id,
            'title' => 'International student grant',
            'status' => 'preparing',
        ]);

        $this->actingAs($user)->patch(route('funding-opportunities.status', $opportunity), [
            'status' => 'submitted',
        ])->assertSessionHas('status');
        $this->assertDatabaseHas('funding_opportunities', ['id' => $opportunity->id, 'status' => 'submitted']);

        $foreignOpportunity = $other->fundingOpportunities()->create([
            'title' => 'Private award',
            'provider' => 'Other account',
            'potential_amount' => null,
            'currency' => 'USD',
            'deadline' => null,
            'status' => 'researching',
            'source_url' => null,
        ]);
        $this->actingAs($user)->patch(route('funding-opportunities.status', $foreignOpportunity), [
            'status' => 'awarded',
        ])->assertNotFound();

        $this->actingAs($user)->post(route('funding-opportunities.store'), [
            'title' => 'Unsupported award',
            'provider' => 'Unknown',
            'potential_amount' => '200.00',
            'currency' => 'XYZ',
            'status' => 'approved',
        ])->assertSessionHasErrors(['currency', 'status']);

        $this->actingAs($user)->post(route('funding-opportunities.store'), [
            'title' => 'Unsafe link',
            'provider' => 'Unknown',
            'currency' => 'USD',
            'status' => 'researching',
            'source_url' => 'javascript://example.test',
        ])->assertSessionHasErrors('source_url');

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Scholarship & funding planner')
            ->assertSee('International student grant')
            ->assertSee('User-entered; not verified');
    }

    public function test_currency_and_simulator_endpoints_validate_inputs_and_return_explicit_estimates(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('currency.convert'), [
            'amount' => '100.00',
            'from_currency' => 'USD',
            'to_currency' => 'EUR',
        ])->assertOk()
            ->assertJsonPath('converted_amount', '92.34')
            ->assertJsonPath('live', false);

        $this->actingAs($user)->postJson(route('remittance.compare'), [
            'amount' => '100.00',
            'from_currency' => 'USD',
            'to_currency' => 'EUR',
        ])->assertOk()->assertJsonPath('quotes.0.simulated', true);

        $this->actingAs($user)->postJson(route('currency.convert'), [
            'amount' => '100.00',
            'from_currency' => 'USD',
            'to_currency' => 'XYZ',
        ])->assertUnprocessable();

        $scenarioInputs = [
            'monthly_budget' => '1800.00',
            'monthly_spending' => '1500.00',
            'tuition_contribution' => '200.00',
            'tuition_amount' => '1200.00',
            'saved_amount' => '0.00',
            'transfer_fee' => '5.00',
            'installments' => '3',
            'due_date' => today()->addDays(90)->toDateString(),
            'from_currency' => 'EUR',
            'budget_currency' => 'USD',
            'to_currency' => 'CNY',
            'exchange_rate_change' => '10',
            'monthly_rent' => '500.00',
            'monthly_income' => '500.00',
            'rent_change_percent' => '10',
            'income_delay_months' => '2',
            'scholarship_amount' => '1000.00',
            'scholarship_month' => '3',
            'forecast_months' => '4',
            'rate_stress_percent' => '10',
        ];

        $this->actingAs($user)->postJson(route('scenario.run'), $scenarioInputs)->assertOk()
            ->assertJsonPath('budget.projected_remaining', '100.00')
            ->assertJsonPath('budget.required_tuition_savings', '433.18')
            ->assertJsonPath('budget.tuition_savings_gap', '233.18')
            ->assertJsonPath('exchange.simulated', true)
            ->assertJsonPath('forecasts.0.name', 'Favorable')
            ->assertJsonPath('forecasts.1.rate_change_percent', 0)
            ->assertJsonPath('forecasts.2.rate_change_percent', 10)
            ->assertJsonPath('forecast_assumptions.scholarship_is_unconfirmed', true)
            ->assertJsonPath('budget_input_currency', 'USD');

        $this->actingAs($user)->postJson(route('scenario.run'), [
            ...$scenarioInputs,
            'saved_amount' => '1200.01',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Saved progress cannot exceed the tuition amount.');
    }

    public function test_currency_conversion_shows_supported_bdt_estimate_source_and_calculation_time(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('currency.convert'), [
            'amount' => '100.00',
            'from_currency' => 'USD',
            'to_currency' => 'BDT',
        ])->assertOk()
            ->assertJsonPath('converted_amount', '11920.00')
            ->assertJsonPath('to_currency', 'BDT')
            ->assertJsonPath('source', 'Atlas demonstration rate table; not a live market quote')
            ->assertJsonPath('live', false)
            ->assertJsonStructure(['retrieved_at']);
    }

    public function test_currency_conversion_rejects_invalid_amounts_and_guest_requests(): void
    {
        $this->postJson(route('currency.convert'), [
            'amount' => '100.001',
            'from_currency' => 'USD',
            'to_currency' => 'EUR',
        ])->assertUnauthorized();

        $this->actingAs(User::factory()->create())->postJson(route('currency.convert'), [
            'amount' => '100.001',
            'from_currency' => 'USD',
            'to_currency' => 'EUR',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    public function test_agent_confirmation_endpoint_accepts_only_an_owned_pending_change(): void
    {
        $user = User::factory()->create();
        $conversation = $user->agentConversations()->create(['title' => 'Confirmation']);
        $otherUser = User::factory()->create();
        $otherConversation = $otherUser->agentConversations()->create(['title' => 'Other']);
        $user->agentPendingActions()->create([
            'conversation_id' => $conversation->id,
            'tool_name' => 'save_plan',
            'arguments' => ['title' => 'A plan', 'summary' => 'Review monthly savings against tuition.'],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);
        $foreignAction = $otherUser->agentPendingActions()->create([
            'conversation_id' => $otherConversation->id,
            'tool_name' => 'save_plan',
            'arguments' => ['title' => 'Private plan', 'summary' => 'This belongs to another account.'],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->actingAs($user)->postJson(route('agent.confirm'), [
            'conversation_id' => $conversation->id,
            'pending_action_id' => $foreignAction->id,
            'confirmed' => true,
        ])->assertNotFound();
        $this->actingAs($user)->postJson(route('agent.cancel'), [
            'conversation_id' => $conversation->id,
            'pending_action_id' => $foreignAction->id,
            'discarded' => true,
        ])->assertNotFound();
        $this->assertDatabaseHas('agent_pending_actions', [
            'id' => $foreignAction->id,
            'status' => 'pending',
        ]);

        $this->actingAs($user)->postJson(route('agent.confirm'), [
            'conversation_id' => $conversation->id,
            'pending_action_id' => 999999,
            'confirmed' => false,
        ])->assertUnprocessable();

        $pending = $user->agentPendingActions()->create([
            'conversation_id' => $conversation->id,
            'tool_name' => 'save_plan',
            'arguments' => ['title' => 'Confirmed plan', 'summary' => 'Review the savings target monthly.'],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);
        $this->actingAs($user)->postJson(route('agent.confirm'), [
            'conversation_id' => $conversation->id,
            'pending_action_id' => $pending->id,
            'confirmed' => true,
        ])->assertOk()->assertJsonPath('saved.saved', true);

        $this->assertDatabaseHas('financial_plans', ['user_id' => $user->id, 'title' => 'Confirmed plan']);
    }

    public function test_scenario_converts_home_currency_inputs_to_the_selected_budget_currency(): void
    {
        $user = User::factory()->create();
        StudentProfile::create([
            'user_id' => $user->id,
            'destination_country' => 'Canada',
            'home_currency' => 'CNY',
            'monthly_budget' => '1800.00',
            'monthly_income' => '500.00',
        ]);
        $expectedBudget = app(CurrencyCalculator::class)->convert('1800.00', 'CNY', 'EUR');

        $this->actingAs($user)->postJson(route('scenario.run'), [
            'monthly_budget' => '1800.00',
            'monthly_spending' => '1000.00',
            'tuition_contribution' => '100.00',
            'tuition_amount' => '100.00',
            'saved_amount' => '0.00',
            'transfer_fee' => '1.00',
            'installments' => '1',
            'due_date' => today()->addDays(90)->toDateString(),
            'from_currency' => 'USD',
            'budget_currency' => 'EUR',
            'to_currency' => 'CNY',
            'exchange_rate_change' => '0',
            'monthly_rent' => '200.00',
            'monthly_income' => '500.00',
            'rent_change_percent' => '0',
            'income_delay_months' => '0',
            'scholarship_amount' => '0.00',
            'scholarship_month' => '0',
            'forecast_months' => '3',
            'rate_stress_percent' => '5',
        ])->assertOk()
            ->assertJsonPath('budget.monthly_budget', $expectedBudget['converted_amount'])
            ->assertJsonPath('budget_currency', 'EUR')
            ->assertJsonPath('budget_input_currency', 'CNY')
            ->assertJsonPath('forecast_assumptions.budget_input_conversion.rate', $expectedBudget['rate']);
    }

    private function startValidRegistration(string $email): void
    {
        $password = 'Very-secure-pass1!';

        $this->post(route('register.store'), [
            'name' => 'OTP Test User',
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertRedirect(route('register'));
    }

    private function lastVerificationCode(): string
    {
        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last();
        preg_match('/(?<!\\d)(\\d{6})(?!\\d)/', $message->getOriginalMessage()->getTextBody(), $matches);

        return $matches[1] ?? '';
    }
}
