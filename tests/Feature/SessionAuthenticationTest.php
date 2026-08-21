<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_request_returns_standard_json_error_without_accept_header(): void
    {
        $this->get('/api/auth/me')
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'code' => 'UNAUTHENTICATED',
                'message' => 'You must be authenticated to access this endpoint.',
            ]);
    }

    public function test_user_can_update_profile_without_changing_email(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $token = $user->createToken('browser')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/auth/me/profile', [
                'fname' => 'Updated',
                'mname' => null,
                'lname' => 'Name',
                'email' => 'changed@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('code', 'PROFILE_UPDATED')
            ->assertJsonPath('data.user.email', 'user@example.com')
            ->assertJsonPath('data.user.profile.fname', 'Updated')
            ->assertJsonPath('data.user.profile.lname', 'Name');

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'fname' => 'Updated',
            'lname' => 'Name',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'changed@example.com']);
    }

    public function test_user_can_logout_from_all_sessions(): void
    {
        $user = User::factory()->create();
        $firstToken = $user->createToken('browser');
        $user->createToken('mobile');
        $user->createToken('other-browser');

        $this->withToken($firstToken->plainTextToken)
            ->postJson('/api/auth/logout-all')
            ->assertOk()
            ->assertJson([
                'code' => 'LOGGED_OUT_ALL_SESSIONS',
                'message' => 'You have been logged out from all sessions.',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();

        $this->withToken($firstToken->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }
}
