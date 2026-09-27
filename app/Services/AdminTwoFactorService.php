<?php

namespace App\Services;

use App\Models\AdminTwoFactorRecoveryCode;
use App\Models\User;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class AdminTwoFactorService
{
    public function setup(User $user): array
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();
        $user->forceFill(['two_factor_secret' => Crypt::encryptString($secret), 'two_factor_enabled' => false])->save();

        $codes = collect(range(1, 8))->map(fn (): string => Str::upper(Str::random(10)))->all();
        AdminTwoFactorRecoveryCode::where('user_id', $user->id)->delete();
        foreach ($codes as $code) {
            AdminTwoFactorRecoveryCode::create(['user_id' => $user->id, 'code_hash' => Hash::make($code)]);
        }

        $uri = $google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret);

        $qrCode = (new Builder(writer: new SvgWriter, data: $uri, size: 300, margin: 10))->build();

        return ['qr_code' => $qrCode->getDataUri(), 'otpauth_url' => $uri, 'recovery_codes' => $codes];
    }

    public function verifySetup(User $user, string $code): bool
    {
        $valid = $this->validOtp($user, $code);
        if ($valid) {
            $user->forceFill(['two_factor_enabled' => true, 'two_factor_confirmed_at' => now()])->save();
        }
        return $valid;
    }

    public function verifyLogin(User $user, string $code): bool
    {
        if ($this->validOtp($user, $code)) {
            return true;
        }

        $recovery = AdminTwoFactorRecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->get()
            ->first(fn (AdminTwoFactorRecoveryCode $item): bool => Hash::check($code, $item->code_hash));
        if (! $recovery) {
            return false;
        }
        $recovery->update(['used_at' => now()]);
        return true;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_enabled' => false, 'two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        AdminTwoFactorRecoveryCode::where('user_id', $user->id)->delete();
    }

    private function validOtp(User $user, string $code): bool
    {
        if (! $user->two_factor_secret) {
            return false;
        }
        return (new Google2FA)->verifyKey(Crypt::decryptString($user->two_factor_secret), $code);
    }
}
