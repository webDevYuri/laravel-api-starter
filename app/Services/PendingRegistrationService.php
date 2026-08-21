<?php

namespace App\Services;

use App\Models\PendingRegistration;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PendingRegistrationService
{
    public function create(array $data, string $email): PendingRegistration
    {
        return PendingRegistration::create([
            'email' => $email,
            'password' => $data['password'],
            'fname' => $data['fname'],
            'mname' => $data['mname'] ?? null,
            'lname' => $data['lname'],
            'expires_at' => now()->addMinutes(config('otp.registration_expires')),
        ]);
    }

    public function complete(PendingRegistration $pending): User
    {
        return DB::transaction(function () use ($pending) {
            $pending = PendingRegistration::query()->lockForUpdate()->findOrFail($pending->id);

            $user = User::create([
                'name' => trim($pending->fname.' '.($pending->mname ?? '').' '.$pending->lname),
                'email' => $pending->email,
                'password' => $pending->password,
                'email_verified_at' => now(),
                'is_platform_admin' => false,
            ]);

            Profile::create([
                'user_id' => $user->id,
                'fname' => $pending->fname,
                'mname' => $pending->mname,
                'lname' => $pending->lname,
            ]);

            $pending->delete();

            return $user;
        });
    }
}
