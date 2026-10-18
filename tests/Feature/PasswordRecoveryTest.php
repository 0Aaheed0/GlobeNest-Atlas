<?php

namespace Tests\Feature;

use App\Mail\PasswordRecoveryCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_page_is_linked_from_login(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('password.request'));
        $this->get(route('password.request'))->assertOk()->assertSee('Recover your password');
    }

    public function test_existing_account_receives_a_hashed_one_time_recovery_code(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'recover@example.test']);

        $response = $this->post(route('password.email'), ['email' => $user->email]);
        $response
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', 'If an account matches that email, recovery instructions can be sent. If no message arrives, check spam or try again later.');

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $storedCodeHash = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        $this->assertNotSame('123456', $storedCodeHash);

        $recoveryCode = null;
        Mail::assertSent(PasswordRecoveryCode::class, function (PasswordRecoveryCode $mail) use (&$recoveryCode): bool {
            $recoveryCode = $mail->code;

            return true;
        });

        $this->assertNotNull($recoveryCode);
        $this->assertTrue(Hash::check($recoveryCode, $storedCodeHash));
        $this->assertStringNotContainsString($recoveryCode, $response->getContent());
        $this->assertArrayNotHasKey('recovery_code', session()->all());
    }

    public function test_unknown_accounts_receive_the_same_response_without_sending_mail(): void
    {
        Mail::fake();

        $known = User::factory()->create(['email' => 'known@example.test']);
        $knownResponse = $this->post(route('password.email'), ['email' => $known->email]);
        $knownResponse->assertSessionHas('status', 'If an account matches that email, recovery instructions can be sent. If no message arrives, check spam or try again later.');
        $unknownResponse = $this->post(route('password.email'), ['email' => 'missing@example.test']);

        $knownResponse->assertRedirect(route('password.request'));
        $unknownResponse->assertRedirect(route('password.request'))
            ->assertSessionHas('status', 'If an account matches that email, recovery instructions can be sent. If no message arrives, check spam or try again later.');
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
        Mail::assertSent(PasswordRecoveryCode::class, 1);
    }

    public function test_valid_recovery_code_changes_password_once_and_invalidates_database_sessions(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'reset@example.test']);
        $oldRememberToken = $user->getRememberToken();
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            'id' => 'old-session-id',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->post(route('password.email'), ['email' => $user->email]);
        $recoveryCode = $this->sentRecoveryCode();
        $newPassword = 'New-Secure-Pass2!';

        $this->post(route('password.recovery.verify'), [
            'email' => $user->email,
            'recovery_code' => $recoveryCode,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your password has been updated. Please sign in.');

        $user->refresh();
        $this->assertTrue(Hash::check(hash('sha256', $newPassword), $user->password));
        $this->assertNotSame($oldRememberToken, $user->getRememberToken());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session-id']);
    }

    public function test_invalid_recovery_code_does_not_change_password(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'wrong-code@example.test']);
        $originalPasswordHash = $user->password;
        $this->post(route('password.email'), ['email' => $user->email]);

        $this->post(route('password.recovery.verify'), [
            'email' => $user->email,
            'recovery_code' => '000000',
            'password' => 'New-Secure-Pass2!',
            'password_confirmation' => 'New-Secure-Pass2!',
        ])->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('recovery_code');

        $this->assertSame($originalPasswordHash, $user->fresh()->password);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_recovery_rejects_passwords_that_do_not_meet_the_composition_policy(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'weak-reset@example.test']);
        $originalPasswordHash = $user->password;
        $this->post(route('password.email'), ['email' => $user->email]);
        $recoveryCode = $this->sentRecoveryCode();

        $this->post(route('password.recovery.verify'), [
            'email' => $user->email,
            'recovery_code' => $recoveryCode,
            'password' => 'alllowercase123!',
            'password_confirmation' => 'alllowercase123!',
        ])->assertSessionHasErrors('password');

        $this->assertSame($originalPasswordHash, $user->fresh()->password);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_expired_recovery_code_is_rejected_and_removed(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'expired@example.test']);
        $originalPasswordHash = $user->password;
        $this->post(route('password.email'), ['email' => $user->email]);
        $recoveryCode = $this->sentRecoveryCode();
        $this->travel(16)->minutes();

        $this->post(route('password.recovery.verify'), [
            'email' => $user->email,
            'recovery_code' => $recoveryCode,
            'password' => 'New-Secure-Pass2!',
            'password_confirmation' => 'New-Secure-Pass2!',
        ])->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('recovery_code');

        $this->assertSame($originalPasswordHash, $user->fresh()->password);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_recovery_code_cannot_be_reused(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'single-use@example.test']);
        $this->post(route('password.email'), ['email' => $user->email]);
        $recoveryCode = $this->sentRecoveryCode();
        $firstPassword = 'First-Secure-Pass2!';

        $this->post(route('password.recovery.verify'), [
            'email' => $user->email,
            'recovery_code' => $recoveryCode,
            'password' => $firstPassword,
            'password_confirmation' => $firstPassword,
        ])->assertRedirect(route('login'));

        $secondPassword = 'Second-Secure-Pass3!';
        $this->post(route('password.recovery.verify'), [
            'email' => $user->email,
            'recovery_code' => $recoveryCode,
            'password' => $secondPassword,
            'password_confirmation' => $secondPassword,
        ])->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('recovery_code');

        $this->assertTrue(Hash::check(hash('sha256', $firstPassword), $user->fresh()->password));
    }

    public function test_recovery_requests_and_verification_attempts_are_throttled(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'throttled@example.test']);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('password.email'), ['email' => $user->email]);
        }

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHasErrors('email');
        Mail::assertSent(PasswordRecoveryCode::class, 3);

        $recoveryCode = $this->sentRecoveryCode();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('password.recovery.verify'), [
                'email' => $user->email,
                'recovery_code' => '000000',
                'password' => 'New-Secure-Pass2!',
                'password_confirmation' => 'New-Secure-Pass2!',
            ]);
        }

        $this->post(route('password.recovery.verify'), [
            'email' => $user->email,
            'recovery_code' => $recoveryCode,
            'password' => 'New-Secure-Pass2!',
            'password_confirmation' => 'New-Secure-Pass2!',
        ])->assertSessionHasErrors('recovery_code');
    }

    public function test_failed_recovery_email_does_not_leave_a_token_or_log_the_code(): void
    {
        $user = User::factory()->create(['email' => 'mail-failure@example.test']);
        $recoveryCode = null;
        $pendingMail = Mockery::mock(PendingMail::class);
        $pendingMail->shouldReceive('send')->once()->with(Mockery::on(function (PasswordRecoveryCode $mail) use (&$recoveryCode): bool {
            $recoveryCode = $mail->code;

            return true;
        }))
            ->andThrow(new \RuntimeException('Mail transport failed'));
        Mail::shouldReceive('to')->once()->with($user->email)->andReturn($pendingMail);
        Log::spy();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', 'If an account matches that email, recovery instructions can be sent. If no message arrives, check spam or try again later.');

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertNotNull($recoveryCode);
        Log::shouldHaveReceived('warning')->with(
            'Password recovery email could not be delivered.',
            Mockery::on(fn (array $context): bool => array_keys($context) === ['exception_type']
                && ! str_contains(json_encode($context), $recoveryCode)),
        )->once();
    }

    public function test_recovery_never_sends_codes_through_a_logging_mailer(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'logging-mailer@example.test']);
        config([
            'mail.default' => 'unsafe-failover',
            'mail.mailers.unsafe-failover' => [
                'transport' => 'failover',
                'mailers' => ['smtp', 'log'],
            ],
        ]);
        Log::spy();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status', 'If an account matches that email, recovery instructions can be sent. If no message arrives, check spam or try again later.');

        Mail::assertNothingOutgoing();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        Log::shouldHaveReceived('warning')->with(
            'Password recovery email could not be delivered.',
            Mockery::on(fn (array $context): bool => array_keys($context) === ['exception_type']),
        )->once();
    }

    private function sentRecoveryCode(): string
    {
        $code = null;

        Mail::assertSent(PasswordRecoveryCode::class, function (PasswordRecoveryCode $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        return (string) $code;
    }
}
