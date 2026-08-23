<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'superadmin@gmail.com';
        $password = 'admin1234';

        $permissionNames = collect(config('rbac.resources', []))
            ->flatMap(fn (array $resource, string $name) => collect($resource['actions'] ?? [])
                ->map(fn (string $action) => "{$name}.{$action}"))
            ->values();

        $permissions = $permissionNames->map(fn (string $name) => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'api',
        ]));

        $role = Role::firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => 'api',
        ]);
        $role->syncPermissions($permissions);

        $user = User::firstOrNew(['email' => $email]);
        $user->name = 'Super Admin';
        $user->is_platform_admin = true;
        $user->email_verified_at ??= now();

        if (! $user->exists) {
            $user->password = $password;
        }

        $user->save();

        $user->assignRole($role);

        Profile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'fname' => 'Super',
                'mname' => null,
                'lname' => 'Admin',
            ],
        );

        $this->command?->info("Super admin ready: {$user->email}");
    }
}
