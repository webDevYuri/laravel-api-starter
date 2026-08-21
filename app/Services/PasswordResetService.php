<?php

namespace App\Services;

use App\Mail\PasswordResetLinkMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PasswordResetService
{
    public function sendLink(string $email): ?array
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return null;
        }

        $token = Str::random(64);
        $expiresAt = now()->addMinutes(config('auth.passwords.users.expire'));
        $resetUrl = rtrim(config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
            'email' => $email,
            'token' => $token,
        ]);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );
        Mail::to($email)->send(new PasswordResetLinkMail($resetUrl));

        return compact('token', 'resetUrl', 'expiresAt');
    }

    public function reset(string $email, string $token, string $password): bool
    {
        return DB::transaction(function () use ($email, $token, $password): bool {
            $reset = DB::table('password_reset_tokens')
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if (! $reset || ! $reset->created_at) {
                return false;
            }

            $expiresAt = Carbon::parse($reset->created_at)
                ->addMinutes(config('auth.passwords.users.expire'));

            if ($expiresAt->isPast() || ! Hash::check($token, $reset->token)) {
                if ($expiresAt->isPast()) {
                    DB::table('password_reset_tokens')->where('email', $email)->delete();
                }

                return false;
            }

            $user = User::where('email', $email)->lockForUpdate()->first();

            if (! $user) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();

                return false;
            }

            $user->forceFill([
                'password' => $password,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
            $user->tokens()->delete();

            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return true;
        });
    }
}
