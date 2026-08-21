<?php

namespace Tests\Feature;

use App\Jobs\PruneExpiredPendingRegistrations;
use App\Models\OtpChallenge;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.mode' => 'password']);
    }

    public function test_password_login_works_when_password_mode_is_enabled(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com', 'password' => 'password', 'email_verified_at' => now(),
        ]);

        $this->postJson('/api/authentication/password/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertOk()->assertJsonPath('code', 'AUTHENTICATED')->assertJsonStructure(['token', 'user']);
    }

    public function test_password_login_is_blocked_in_otp_mode(): void
    {
        config(['auth.mode' => 'otp']);

        $this->postJson('/api/authentication/password/login', [
            'email' => 'user@example.com', 'password' => 'password',
        ])->assertForbidden()->assertJsonPath('code', 'AUTHENTICATION_METHOD_DISABLED');
    }

    public function test_password_login_returns_account_not_found_for_unknown_email(): void
    {
        $this->postJson('/api/authentication/password/login', [
            'email' => 'missing@example.com', 'password' => 'password',
        ])->assertNotFound()->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');
    }

    public function test_password_login_returns_invalid_credentials_for_wrong_password(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com', 'password' => 'password', 'email_verified_at' => now(),
        ]);

        $this->postJson('/api/authentication/password/login', [
            'email' => $user->email, 'password' => 'wrong-password',
        ])->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_password_registration_creates_pending_attempt_then_verified_user(): void
    {
        Mail::fake();
        $response = $this->postJson('/api/authentication/password/register', $this->registrationPayload())
            ->assertAccepted()
            ->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED')
            ->assertJsonStructure(['registrationId', 'email', 'retryAfter', 'resendAvailableAt', 'expiresAt']);

        $registrationId = $response->json('registrationId');
        $this->assertDatabaseHas('pending_registrations', ['id' => $registrationId, 'email' => 'new@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);

        OtpChallenge::where('pending_registration_id', $registrationId)
            ->update(['code_hash' => Hash::make('123456')]);

        $this->postJson('/api/authentication/password/register/verify', [
            'registrationId' => $registrationId, 'code' => '123456',
        ])->assertCreated()->assertJsonPath('code', 'REGISTERED');

        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
        $this->assertDatabaseMissing('pending_registrations', ['id' => $registrationId]);
        $this->assertDatabaseMissing('otp_challenges', ['pending_registration_id' => $registrationId]);

        $this->postJson('/api/authentication/password/register/resend', [
            'registrationId' => $registrationId,
        ])->assertGone()->assertJson([
            'code' => 'REGISTRATION_NOT_ACTIVE',
            'message' => 'This registration is no longer active. Please start a new registration.',
        ]);
    }

    public function test_same_email_creates_a_new_registration_attempt(): void
    {
        Mail::fake();
        $first = $this->postJson('/api/authentication/password/register', $this->registrationPayload())->json('registrationId');
        $second = $this->postJson('/api/authentication/password/register', $this->registrationPayload())->json('registrationId');

        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('pending_registrations', 2);
    }

    public function test_resend_updates_last_sent_time_without_extending_registration_expiry(): void
    {
        Mail::fake();
        $registrationId = $this->postJson('/api/authentication/password/register', $this->registrationPayload())
            ->json('registrationId');
        $pending = PendingRegistration::findOrFail($registrationId);
        $expiresAt = $pending->expires_at->toISOString();
        $pending->otpChallenges()->update(['last_sent_at' => now()->subMinutes(2)]);

        $this->postJson('/api/authentication/password/register/resend', [
            'registrationId' => $registrationId,
        ])->assertOk()->assertJsonPath('code', 'OTP_RESENT');

        $this->assertSame($expiresAt, $pending->fresh()->expires_at->toISOString());
        $this->assertNotNull($pending->fresh()->last_otp_sent_at);
    }

    public function test_expired_registration_returns_not_active(): void
    {
        $pending = PendingRegistration::create([
            ...$this->registrationPayload(false),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/authentication/password/register/verify', [
            'registrationId' => $pending->id, 'code' => '123456',
        ])->assertGone()->assertJsonPath('code', 'REGISTRATION_NOT_ACTIVE');
    }

    public function test_cleanup_job_deletes_only_expired_pending_registrations(): void
    {
        $expired = PendingRegistration::create([
            ...$this->registrationPayload(false), 'email' => 'expired@example.com', 'expires_at' => now()->subMinute(),
        ]);
        $active = PendingRegistration::create([
            ...$this->registrationPayload(false), 'email' => 'active@example.com', 'expires_at' => now()->addMinutes(30),
        ]);
        $expired->otpChallenges()->create([
            'identifier' => $expired->email, 'purpose' => 'password_register',
            'code_hash' => Hash::make('123456'), 'expires_at' => now()->subMinute(),
        ]);

        (new PruneExpiredPendingRegistrations)->handle();

        $this->assertDatabaseMissing('pending_registrations', ['id' => $expired->id]);
        $this->assertDatabaseHas('pending_registrations', ['id' => $active->id]);
        $this->assertDatabaseMissing('otp_challenges', ['pending_registration_id' => $expired->id]);
    }

    public function test_password_registration_is_blocked_in_otp_mode(): void
    {
        config(['auth.mode' => 'otp']);

        $this->postJson('/api/authentication/password/register', $this->registrationPayload())
            ->assertForbidden()->assertJsonPath('code', 'AUTHENTICATION_METHOD_DISABLED');
    }

    private function registrationPayload(bool $confirmed = true): array
    {
        $data = [
            'email' => 'new@example.com', 'password' => 'password',
            'fname' => 'New', 'mname' => null, 'lname' => 'User',
        ];

        if ($confirmed) {
            $data['password_confirmation'] = 'password';
        }

        return $data;
    }
}
