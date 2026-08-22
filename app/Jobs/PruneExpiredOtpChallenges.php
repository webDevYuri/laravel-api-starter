<?php

namespace App\Jobs;

use App\Models\OtpChallenge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PruneExpiredOtpChallenges implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(): void
    {
        $deleted = OtpChallenge::query()
            ->where('expires_at', '<', now())
            ->orWhere('consumed_at', '<', now()->subHours(config('otp.consumed_retention_hours')))
            ->delete();

        Log::info('Expired OTP challenges cleanup completed.', ['deleted' => $deleted]);
    }
}
