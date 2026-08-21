<?php

namespace App\Services;

use App\Mail\OtpCodeMail;
use App\Models\OtpChallenge;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class OtpService
{
    public function send(string $email, string $action, ?User $user = null): array
    {
        $recent = OtpChallenge::where('identifier', $email)
            ->where('purpose', $action)
            ->whereNull('consumed_at')
            ->latest('last_sent_at')
            ->first();
        if ($recent?->last_sent_at?->gt(now()->subSeconds(config('otp.resend_after')))) {
            $retryAfter = max(1, (int) now()->diffInSeconds($recent->last_sent_at->addSeconds(config('otp.resend_after'))));
            throw new TooManyRequestsHttpException(
                $retryAfter,
                'Please wait before requesting another OTP.',
                null,
                0,
                ['X-Rate-Limit-Reason' => 'otp-cooldown']
            );
        }

        $code = str_pad((string) random_int(0, (10 ** config('otp.length')) - 1), config('otp.length'), '0', STR_PAD_LEFT);
        $challenge = OtpChallenge::create([
            'user_id' => $user?->id,
            'identifier' => $email,
            'purpose' => $action,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(config('otp.expires')),
            'last_sent_at' => now(),
        ]);

        Mail::to($email)->send(new OtpCodeMail($code, $action));

        return compact('challenge', 'code');
    }

    public function verify(string $email, string $purpose, string $code): OtpChallenge
    {
        $challenge = OtpChallenge::where('identifier', $email)->where('purpose', $purpose)
            ->whereNull('consumed_at')->latest()->first();

        if (! $challenge || $challenge->expires_at->isPast() || $challenge->attempts >= config('otp.max_attempts')) {
            throw ValidationException::withMessages(['code' => ['The OTP is invalid or expired.']]);
        }

        $challenge->increment('attempts');
        if (! Hash::check($code, $challenge->code_hash)) {
            throw ValidationException::withMessages(['code' => ['The OTP is invalid or expired.']]);
        }

        return $challenge;
    }

    public function sendForRegistration(PendingRegistration $pending): array
    {
        $recent = $pending->otpChallenges()->whereNull('consumed_at')->latest('last_sent_at')->first();
        if ($recent?->last_sent_at?->gt(now()->subSeconds(config('otp.resend_after')))) {
            $retryAfter = max(1, (int) now()->diffInSeconds($recent->last_sent_at->addSeconds(config('otp.resend_after'))));
            throw new TooManyRequestsHttpException(
                $retryAfter,
                'Please wait before requesting another OTP.',
                null,
                0,
                ['X-Rate-Limit-Reason' => 'otp-cooldown']
            );
        }

        $code = str_pad((string) random_int(0, (10 ** config('otp.length')) - 1), config('otp.length'), '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(config('otp.expires'));
        if ($expiresAt->gt($pending->expires_at)) {
            $expiresAt = $pending->expires_at->copy();
        }

        $challenge = $pending->otpChallenges()->create([
            'identifier' => $pending->email,
            'purpose' => 'password_register',
            'code_hash' => Hash::make($code),
            'expires_at' => $expiresAt,
            'last_sent_at' => now(),
        ]);
        $pending->update(['last_otp_sent_at' => $challenge->last_sent_at]);

        Mail::to($pending->email)->send(new OtpCodeMail($code, 'register'));

        return compact('challenge', 'code');
    }

    public function verifyForRegistration(PendingRegistration $pending, string $code): OtpChallenge
    {
        $challenge = $pending->otpChallenges()->where('purpose', 'password_register')
            ->whereNull('consumed_at')->latest()->first();

        if (! $challenge) {
            throw ValidationException::withMessages(['code' => ['The verification code is invalid.']]);
        }

        if ($challenge->expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => ['The verification code has expired.']]);
        }

        if ($challenge->attempts >= config('otp.max_attempts')) {
            throw ValidationException::withMessages(['code' => ['The verification code is invalid.']]);
        }

        $challenge->increment('attempts');
        if (! Hash::check($code, $challenge->code_hash)) {
            throw ValidationException::withMessages(['code' => ['The verification code is incorrect.']]);
        }

        return $challenge;
    }
}
