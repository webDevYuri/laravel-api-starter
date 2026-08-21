<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RbacActivityTest extends TestCase
{
    use RefreshDatabase;

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
}
