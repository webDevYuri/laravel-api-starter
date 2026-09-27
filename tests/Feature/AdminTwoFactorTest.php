<?php

namespace Tests\Feature;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;
    public function test_admin_can_setup_verify_and_use_two_factor_authentication(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'email_verified_at' => now()]);
        $this->actingAs($admin, 'sanctum');

        $setup = $this->postJson('/api/admin/authentication/2fa/setup');
        $setup->assertOk()->assertJsonPath('code', 'ADMIN_2FA_SETUP_READY');
        $secret = $setup->json('data.otpauth_url');
        $this->assertNotEmpty($secret);

        preg_match('/secret=([^&]+)/', $secret, $matches);
        $code = (new Google2FA)->getCurrentOtp($matches[1]);
        $this->postJson('/api/admin/authentication/2fa/setup/verify', ['code' => $code])
            ->assertOk()->assertJsonPath('code', 'ADMIN_2FA_ENABLED');

        $login = $this->postJson('/api/admin/authentication/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('code', 'ADMIN_2FA_REQUIRED');

        $pendingToken = $login->json('data.token');
        $loginCode = (new Google2FA)->getCurrentOtp($matches[1]);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$pendingToken)
            ->postJson('/api/admin/authentication/2fa/verify', ['code' => $loginCode])
            ->assertOk()->assertJsonPath('code', 'ADMIN_AUTHENTICATED');
    }

    public function test_admin_can_use_a_recovery_code_only_once(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'email_verified_at' => now()]);
        $this->actingAs($admin, 'sanctum');
        $setup = $this->postJson('/api/admin/authentication/2fa/setup');
        $recoveryCode = $setup->json('data.recovery_codes.0');

        preg_match('/secret=([^&]+)/', $setup->json('data.otpauth_url'), $matches);
        $code = (new Google2FA)->getCurrentOtp($matches[1]);
        $this->postJson('/api/admin/authentication/2fa/setup/verify', ['code' => $code]);

        $login = $this->postJson('/api/admin/authentication/login', ['email' => $admin->email, 'password' => 'password']);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$login->json('data.token'))
            ->postJson('/api/admin/authentication/2fa/verify', ['code' => $recoveryCode])
            ->assertOk();

        $login = $this->postJson('/api/admin/authentication/login', ['email' => $admin->email, 'password' => 'password']);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$login->json('data.token'))
            ->postJson('/api/admin/authentication/2fa/verify', ['code' => $recoveryCode])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_ADMIN_2FA_CODE');
    }
}
