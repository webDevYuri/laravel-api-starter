<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformAdminResource;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RbacController extends Controller
{
    public function indexRoles(): JsonResponse
    {
        return ApiResponse::success('ROLES_RETRIEVED', 'Roles retrieved successfully.', [
            'roles' => RoleResource::collection(Role::query()->with('permissions')->latest()->get()),
        ]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('guard_name', 'api')],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'api')],
        ]);
        $role = Role::create(['name' => $data['name'], 'guard_name' => 'api']);
        $role->syncPermissions($data['permissions'] ?? []);

        return ApiResponse::success('ROLE_CREATED', 'Role created successfully.', ['role' => RoleResource::make($role->load('permissions'))], status: 201);
    }

    public function updateRole(Request $request, Role $role): JsonResponse
    {
        abort_unless($role->guard_name === 'api', 404);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('roles', 'name')->where('guard_name', 'api')->ignore($role->id)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'api')],
        ]);
        $role->update(['name' => $data['name'] ?? $role->name]);
        if (array_key_exists('permissions', $data)) {
            $role->syncPermissions($data['permissions']);
        }

        return ApiResponse::success('ROLE_UPDATED', 'Role updated successfully.', ['role' => RoleResource::make($role->fresh()->load('permissions'))]);
    }

    public function destroyRole(Role $role): JsonResponse
    {
        abort_unless($role->guard_name === 'api', 404);

        if ($role->name === 'super_admin') {
            return ApiResponse::error(
                'SYSTEM_ROLE_PROTECTED',
                'The super admin role cannot be deleted.',
                status: 422,
            );
        }

        $role->delete();

        return ApiResponse::success('ROLE_DELETED', 'Role deleted successfully.');
    }

    public function platformAdmins(): JsonResponse
    {
        return ApiResponse::success('PLATFORM_ADMINS_RETRIEVED', 'Platform admins retrieved successfully.', [
            'users' => PlatformAdminResource::collection(
                User::query()->where('is_platform_admin', true)->with(['roles', 'profile'])->latest()->get()
            ),
        ]);
    }

    public function showPlatformAdmin(User $user): JsonResponse
    {
        if (! $user->is_platform_admin) {
            return $this->notPlatformAdminResponse();
        }

        return ApiResponse::success('PLATFORM_ADMIN_RETRIEVED', 'Platform admin retrieved successfully.', [
            'user' => PlatformAdminResource::make($user->load(['roles', 'profile'])),
        ]);
    }

    public function storePlatformAdmin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'fname' => ['required', 'string', 'max:255'],
            'mname' => ['nullable', 'string', 'max:255'],
            'lname' => ['required', 'string', 'max:255'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'api')],
        ]);

        $role = Role::query()->where('guard_name', 'api')->find($data['role_id']);
        $email = strtolower($data['email']);

        $user = DB::transaction(function () use ($data, $email, $role): User {
            $user = User::create([
                'name' => trim("{$data['fname']} {$data['lname']}"),
                'email' => $email,
                'password' => $data['password'],
                'is_platform_admin' => true,
                'email_verified_at' => now(),
            ]);
            $user->assignRole($role);
            $user->profile()->create([
                'fname' => $data['fname'],
                'mname' => $data['mname'] ?? null,
                'lname' => $data['lname'],
            ]);

            return $user;
        });

        return ApiResponse::success('PLATFORM_ADMIN_CREATED', 'Platform admin created successfully.', [
            'user' => PlatformAdminResource::make($user->load(['roles', 'profile'])),
        ], status: 201);
    }

    public function updatePlatformAdmin(Request $request, User $user): JsonResponse
    {
        if (! $user->is_platform_admin) {
            return $this->notPlatformAdminResponse();
        }

        $data = $request->validate([
            'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'required', 'string', 'min:8', 'confirmed'],
            'fname' => ['sometimes', 'required', 'string', 'max:255'],
            'mname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lname' => ['sometimes', 'required', 'string', 'max:255'],
            'role_id' => ['sometimes', 'required', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'api')],
        ]);

        if ($user->hasRole('super_admin') && isset($data['role_id'])) {
            $role = Role::query()->whereKey($data['role_id'])->where('guard_name', 'api')->first();
            if ($role?->name !== 'super_admin') {
                return ApiResponse::error('SYSTEM_ROLE_PROTECTED', 'The super admin access cannot be downgraded.', status: 422);
            }
        }

        DB::transaction(function () use ($data, $user): void {
            $userData = array_filter([
                'email' => isset($data['email']) ? strtolower($data['email']) : null,
                'password' => $data['password'] ?? null,
            ], fn ($value) => $value !== null);
            if ($userData !== []) {
                $user->update($userData);
            }

            $profileData = array_intersect_key($data, array_flip(['fname', 'mname', 'lname']));
            if ($profileData !== []) {
                $user->profile()->updateOrCreate(['user_id' => $user->id], $profileData);
                $user->update(['name' => trim(($profileData['fname'] ?? $user->profile->fname).' '.($profileData['lname'] ?? $user->profile->lname))]);
            }

            if (isset($data['role_id'])) {
                $user->syncRoles([(int) $data['role_id']]);
            }
        });

        return ApiResponse::success('PLATFORM_ADMIN_UPDATED', 'Platform admin updated successfully.', [
            'user' => PlatformAdminResource::make($user->fresh()->load(['roles', 'profile'])),
        ]);
    }

    public function assignPlatformAdminRole(Request $request, User $user): JsonResponse
    {
        if (! $user->is_platform_admin) {
            return $this->notPlatformAdminResponse();
        }

        $roleId = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'api')],
        ])['role_id'];
        $role = Role::query()->where('guard_name', 'api')->find($roleId);

        if (! $role) {
            return ApiResponse::error('ROLE_NOT_FOUND', 'The selected role was not found.', status: 404);
        }

        $user->syncRoles([$role]);

        return ApiResponse::success('PLATFORM_ADMIN_ROLE_ASSIGNED', 'Platform admin role assigned successfully.', [
            'user' => PlatformAdminResource::make($user->fresh()->load('roles')),
        ]);
    }

    public function removePlatformAdmin(Request $request, User $user): JsonResponse
    {
        if ($user->hasRole('super_admin')) {
            return ApiResponse::error(
                'SYSTEM_ROLE_PROTECTED',
                'The super admin access cannot be removed.',
                status: 422,
            );
        }

        if ($request->user()->is($user)) {
            return ApiResponse::error('CANNOT_REMOVE_SELF', 'You cannot remove your own platform-admin access.', status: 422);
        }

        if (User::query()->where('is_platform_admin', true)->count() <= 1) {
            return ApiResponse::error('LAST_PLATFORM_ADMIN', 'At least one platform admin must remain.', status: 422);
        }

        $user->update(['is_platform_admin' => false]);
        $user->syncRoles([]);

        return ApiResponse::success('PLATFORM_ADMIN_REMOVED', 'Platform-admin access removed successfully.');
    }

    private function notPlatformAdminResponse(): JsonResponse
    {
        return ApiResponse::error('USER_NOT_PLATFORM_ADMIN', 'The selected user is not a platform admin.', status: 422);
    }
}
