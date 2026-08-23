<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RbacActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_log_in_with_password_regardless_of_client_auth_mode(): void
    {
        config(['auth.mode' => 'otp']);
        $admin = User::factory()->create([
            'email' => 'superadmin@example.com',
            'password' => 'admin1234',
            'is_platform_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/admin/authentication/login', [
            'email' => $admin->email,
            'password' => 'admin1234',
        ])->assertOk()
            ->assertJsonPath('code', 'ADMIN_AUTHENTICATED')
            ->assertJsonPath('data.action', 'admin_login')
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_non_platform_admin_cannot_use_admin_login(): void
    {
        $user = User::factory()->create([
            'password' => 'password123',
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/admin/authentication/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_ADMIN_CREDENTIALS');
    }

    public function test_platform_admin_can_log_out_from_the_admin_session(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $token = $admin->createToken('admin-api', ['admin'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/admin/authentication/logout')
            ->assertOk()
            ->assertJsonPath('code', 'ADMIN_LOGGED_OUT');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_users_can_be_assigned_api_roles_and_permissions(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'admin', 'guard_name' => 'api']);
        $permission = Permission::create(['name' => 'users.view', 'guard_name' => 'api']);

        $role->givePermissionTo($permission);
        $user->assignRole($role);

        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->can('users.view'));
    }

    public function test_user_changes_are_recorded_in_the_activity_log(): void
    {
        $user = User::factory()->create(['name' => 'Initial Name']);

        $user->update(['name' => 'Updated Name']);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'log_name' => 'users',
            'event' => 'updated',
        ]);

        $this->assertSame(2, Activity::query()->where('subject_id', $user->id)->count());
    }

    public function test_platform_admin_can_manage_roles_and_permissions(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        Permission::firstOrCreate(['name' => 'roles.view', 'guard_name' => 'api']);
        $existingRole = Role::create(['name' => 'existing', 'guard_name' => 'api']);
        $existingRole->givePermissionTo('roles.view');

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->getJson('/api/admin/roles')
            ->assertOk()
            ->assertJsonPath('code', 'ROLES_RETRIEVED')
            ->assertJsonPath('data.roles.0.id', $existingRole->id)
            ->assertJsonPath('data.roles.0.permissions.0', 'roles.view');

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->postJson('/api/admin/roles', ['name' => 'viewer', 'permissions' => ['roles.view']])
            ->assertCreated()
            ->assertJsonPath('code', 'ROLE_CREATED')
            ->assertJsonPath('data.role.name', 'viewer');
    }

    public function test_non_platform_admin_cannot_manage_roles(): void
    {
        $user = User::factory()->create(['is_platform_admin' => false]);

        $this->withToken($user->createToken('api')->plainTextToken)
            ->getJson('/api/admin/roles')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_rbac_permissions_sync_from_config_without_deleting_stale_permissions(): void
    {
        config(['rbac.resources.employees' => [
            'label' => 'Employees',
            'actions' => ['view', 'approve'],
        ]]);
        Permission::firstOrCreate(['name' => 'legacy.action', 'guard_name' => 'api']);

        Artisan::call('rbac:sync-permissions');

        $this->assertDatabaseHas('permissions', ['name' => 'employees.view', 'guard_name' => 'api']);
        $this->assertDatabaseHas('permissions', ['name' => 'employees.approve', 'guard_name' => 'api']);
        $this->assertDatabaseHas('permissions', ['name' => 'legacy.action', 'guard_name' => 'api']);
    }

    public function test_platform_admin_can_assign_a_role_to_an_existing_user(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $user = User::factory()->create(['is_platform_admin' => true]);
        $role = Role::create(['name' => 'employee_manager', 'guard_name' => 'api']);

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->patchJson("/api/admin/platform-admins/{$user->id}/role", ['role_id' => $role->id])
            ->assertOk()
            ->assertJsonPath('code', 'PLATFORM_ADMIN_ROLE_ASSIGNED')
            ->assertJsonPath('data.user.roles.0', 'employee_manager');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_platform_admin' => true]);
    }

    public function test_role_assignment_requires_an_existing_platform_admin(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $user = User::factory()->create(['is_platform_admin' => false]);
        $role = Role::create(['name' => 'viewer', 'guard_name' => 'api']);

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->patchJson("/api/admin/platform-admins/{$user->id}/role", ['role_id' => $role->id])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'USER_NOT_PLATFORM_ADMIN');
    }

    public function test_super_admin_role_and_access_are_protected(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $superAdminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'api']);
        $admin->assignRole($superAdminRole);
        $token = $admin->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->deleteJson("/api/admin/roles/{$superAdminRole->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'SYSTEM_ROLE_PROTECTED');

        $this->withToken($token)
            ->deleteJson("/api/admin/platform-admins/{$admin->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'SYSTEM_ROLE_PROTECTED');
    }

    public function test_platform_admin_crud_endpoints_manage_admin_details_and_roles(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $role = Role::create(['name' => 'manager', 'guard_name' => 'api']);
        $token = $admin->createToken('api')->plainTextToken;

        $created = $this->withToken($token)->postJson('/api/admin/platform-admins', [
            'email' => 'manager@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'fname' => 'Manager',
            'mname' => null,
            'lname' => 'User',
            'role_id' => $role->id,
        ])->assertCreated()
            ->assertJsonPath('code', 'PLATFORM_ADMIN_CREATED');

        $userId = $created->json('data.user.id');

        $this->withToken($token)
            ->getJson("/api/admin/platform-admins/{$userId}")
            ->assertOk()
            ->assertJsonPath('data.user.email', 'manager@example.com');

        $this->withToken($token)
            ->patchJson("/api/admin/platform-admins/{$userId}", [
                'email' => 'updated-manager@example.com',
                'password' => 'updated123',
                'password_confirmation' => 'updated123',
                'fname' => 'Updated',
                'lname' => 'Manager',
                'role_id' => $role->id,
            ])
            ->assertOk()
            ->assertJsonPath('code', 'PLATFORM_ADMIN_UPDATED')
            ->assertJsonPath('data.user.email', 'updated-manager@example.com');
    }

    public function test_platform_admin_cannot_remove_themselves_or_the_last_admin(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $token = $admin->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->deleteJson("/api/admin/platform-admins/{$admin->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CANNOT_REMOVE_SELF');

        $other = User::factory()->create(['is_platform_admin' => true]);
        $this->withToken($token)
            ->deleteJson("/api/admin/platform-admins/{$other->id}")
            ->assertOk()
            ->assertJsonPath('code', 'PLATFORM_ADMIN_REMOVED');

        $this->withToken($token)
            ->deleteJson("/api/admin/platform-admins/{$admin->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CANNOT_REMOVE_SELF');
    }
}
