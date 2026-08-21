<?php

namespace Tests\Feature;

use App\Mail\PasswordResetLinkMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'auth.mode' => 'password',
            'app.frontend_url' => 'http://frontend.test',
        ]);
    }

    public function test_existing_user_receives_a_secure_password_reset_link(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->postJson('/api/authentication/password/forgot-password', [
            'email' => $user->email,
        ])->assertOk()->assertJson([
            'code' => 'PASSWORD_RESET_LINK_SENT',
            'message' => 'If an account exists for this email, a password reset link has been sent.',
        ]);

        Mail::assertSent(PasswordResetLinkMail::class);
        $query = $this->resetLinkQuery();
        $storedReset = DB::table('password_reset_tokens')->where('email', $user->email)->first();

        $this->assertSame('user@example.com', $query['email']);
        $this->assertNotSame($query['token'], $storedReset->token);
        $this->assertTrue(Hash::check($query['token'], $storedReset->token));
        $this->assertSame('http://frontend.test/reset-password', $this->resetLinkBaseUrl());
    }

    public function test_unknown_email_receives_the_same_generic_response(): void
    {
        Mail::fake();

        $this->postJson('/api/authentication/password/forgot-password', [
            'email' => 'missing@example.com',
        ])->assertOk()->assertJson([
            'code' => 'PASSWORD_RESET_LINK_SENT',
            'message' => 'If an account exists for this email, a password reset link has been sent.',
        ]);

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'missing@example.com']);
        Mail::assertNothingSent();
    }

    public function test_user_can_reset_password_with_the_emailed_token(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'old-password',
            'email_verified_at' => now(),
        ]);
        $user->createToken('existing-session');

        $this->postJson('/api/authentication/password/forgot-password', ['email' => $user->email])->assertOk();
        $token = $this->resetLinkQuery()['token'];

        $payload = [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ];

        $this->postJson('/api/authentication/password/reset-password', $payload)
            ->assertOk()->assertJsonPath('code', 'PASSWORD_RESET');

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);

        $this->postJson('/api/authentication/password/reset-password', $payload)
            ->assertGone()->assertJsonPath('code', 'PASSWORD_RESET_NOT_ACTIVE');

        $this->postJson('/api/authentication/password/login', [
            'email' => $user->email,
            'password' => 'new-password',
        ])->assertOk()->assertJsonPath('code', 'AUTHENTICATED');
    }

    public function test_new_request_replaces_the_previous_reset_token(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->postJson('/api/authentication/password/forgot-password', ['email' => $user->email])->assertOk();
        $firstToken = $this->resetLinkQuery()['token'];

        $this->postJson('/api/authentication/password/forgot-password', ['email' => $user->email])->assertOk();
        $secondMail = Mail::sent(PasswordResetLinkMail::class)->last();
        parse_str(parse_url($secondMail->resetUrl, PHP_URL_QUERY), $secondQuery);
        $storedToken = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');

        $this->assertNotSame($firstToken, $secondQuery['token']);
        $this->assertFalse(Hash::check($firstToken, $storedToken));
        $this->assertTrue(Hash::check($secondQuery['token'], $storedToken));
    }

    public function test_invalid_or_expired_reset_token_is_not_accepted(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('valid-token'),
            'created_at' => now()->subMinutes(16),
        ]);

        $this->postJson('/api/authentication/password/reset-password', [
            'email' => $user->email,
            'token' => 'invalid-token',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertGone()->assertJsonPath('code', 'PASSWORD_RESET_NOT_ACTIVE');

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_authenticated_user_can_change_password(): void
    {
        $user = User::factory()->create([
            'password' => 'old-password',
            'email_verified_at' => now(),
        ]);
        $token = $user->createToken('api');

        $this->withToken($token->plainTextToken)
            ->patchJson('/api/authentication/password/change-password', [
                'currentPassword' => 'old-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])->assertOk()->assertJsonPath('code', 'PASSWORD_CHANGED');

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_change_password_rejects_an_incorrect_current_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);

        $this->actingAs($user)
            ->patchJson('/api/authentication/password/change-password', [
                'currentPassword' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])->assertUnprocessable()->assertJsonPath('code', 'CURRENT_PASSWORD_INCORRECT');
    }

    public function test_password_reset_and_change_routes_are_blocked_in_otp_mode(): void
    {
        config(['auth.mode' => 'otp']);

        $this->postJson('/api/authentication/password/forgot-password', [
            'email' => 'user@example.com',
        ])->assertForbidden()->assertJsonPath('code', 'AUTHENTICATION_METHOD_DISABLED');

        $this->patchJson('/api/authentication/password/change-password', [
            'currentPassword' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertForbidden()->assertJsonPath('code', 'AUTHENTICATION_METHOD_DISABLED');
    }

    private function resetLinkQuery(): array
    {
        $mail = Mail::sent(PasswordResetLinkMail::class)->first();
        parse_str(parse_url($mail->resetUrl, PHP_URL_QUERY), $query);

        return $query;
    }

    private function resetLinkBaseUrl(): string
    {
        $mail = Mail::sent(PasswordResetLinkMail::class)->first();

        return parse_url($mail->resetUrl, PHP_URL_SCHEME).'://'
            .parse_url($mail->resetUrl, PHP_URL_HOST)
            .parse_url($mail->resetUrl, PHP_URL_PATH);
    }
}
