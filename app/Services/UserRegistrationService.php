<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserRegistrationService
{
    public function register(array $data, string $email, $challenge): User
    {
        return DB::transaction(function () use ($data, $email, $challenge) {
            $user = User::create([
                'name' => trim($data['fname'].' '.($data['mname'] ?? '').' '.$data['lname']),
                'email' => $email,
                'password' => null,
                'email_verified_at' => now(),
                'is_platform_admin' => false,
            ]);

            Profile::create([
                'user_id' => $user->id,
                'fname' => $data['fname'],
                'mname' => $data['mname'] ?? null,
                'lname' => $data['lname'],
            ]);

            $challenge->update(['user_id' => $user->id, 'consumed_at' => now()]);

            return $user;
        });
    }
}
