<?php

namespace Tests\Feature;

use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.mode' => 'otp']);
    }

    public function test_otp_request_creates_a_hashed_challenge_and_sends_mail(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'user@example.com']);

        $response = $this->postJson('/api/authentication/otp', [
            'email' => $user->email,
        ]);

        $response->assertOk()->assertJsonPath('data.action', 'login');
        $challenge = OtpChallenge::firstOrFail();

        $this->assertSame($user->id, $challenge->user_id);
        $this->assertFalse(Hash::check('123456', $challenge->code_hash));
        Mail::assertSentCount(1);
    }

    public function test_valid_otp_marks_email_verified_and_issues_a_token(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        OtpChallenge::create([
            'user_id' => $user->id,
            'identifier' => $user->email,
            'purpose' => 'login',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
            'last_sent_at' => now(),
        ]);

        $response = $this->postJson('/api/authentication/login', [
            'email' => $user->email,
            'code' => '123456',
        ]);

        $response->assertOk()->assertJsonStructure(['data' => ['token']]);
        $this->assertNotNull(OtpChallenge::first()->consumed_at);
    }

    public function test_invalid_otp_increments_attempts_and_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $challenge = OtpChallenge::create([
            'user_id' => $user->id,
            'identifier' => $user->email,
            'purpose' => 'login',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/authentication/login', [
            'email' => $user->email,
            'code' => '000000',
        ])->assertUnprocessable();

        $this->assertSame(1, $challenge->fresh()->attempts);
    }

    public function test_otp_cannot_be_verified_after_expiration(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        OtpChallenge::create([
            'user_id' => $user->id,
            'identifier' => $user->email,
            'purpose' => 'login',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/authentication/login', [
            'email' => $user->email,
            'code' => '123456',
        ])->assertUnprocessable();
    }

    public function test_otp_resend_is_throttled_by_cooldown(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->postJson('/api/authentication/otp', ['email' => $user->email])->assertOk();
        $this->postJson('/api/authentication/otp', ['email' => $user->email])
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'OTP_RESEND_NOT_READY')
            ->assertJsonStructure(['message', 'meta' => ['retryAfter', 'resendAvailableAt']]);
    }

    public function test_new_user_can_register_after_otp_verification(): void
    {
        $email = 'new@example.com';
        OtpChallenge::create([
            'identifier' => $email, 'purpose' => 'register', 'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/api/authentication/register', [
            'email' => $email, 'code' => '123456', 'fname' => 'New', 'mname' => 'Test', 'lname' => 'User',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.action', 'register')
            ->assertJsonStructure(['code', 'message', 'data' => ['action', 'token', 'user' => ['id', 'email', 'profile']]]);
        $this->assertDatabaseHas('profiles', ['fname' => 'New', 'lname' => 'User']);
    }

    public function test_registered_email_cannot_register_again(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.com']);

        $this->postJson('/api/authentication/register', [
            'email' => $user->email,
            'code' => '123456',
            'fname' => 'Existing',
            'lname' => 'User',
        ])->assertConflict()
            ->assertJson([
                'code' => 'EMAIL_ALREADY_REGISTERED',
                'message' => 'This email is already registered.',
            ]);
    }

    public function test_verified_registration_can_request_login_otp_immediately(): void
    {
        $email = 'registered@example.com';
        User::factory()->create(['email' => $email]);
        $challenge = OtpChallenge::create([
            'identifier' => $email, 'purpose' => 'register', 'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'last_sent_at' => now(), 'consumed_at' => now(),
        ]);

        $this->assertNotNull($challenge->consumed_at);

        $this->postJson('/api/authentication/otp', ['email' => $email])
            ->assertOk()
            ->assertJsonPath('data.action', 'login');
    }
}
