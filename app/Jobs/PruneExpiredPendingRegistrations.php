<?php

namespace App\Jobs;

use App\Models\PendingRegistration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PruneExpiredPendingRegistrations implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(): void
    {
        $deleted = 0;

        PendingRegistration::query()
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($registrations) use (&$deleted): void {
                foreach ($registrations as $registration) {
                    $registration->delete();
                    $deleted++;
                }
            });

        if ($deleted > 0) {
            Log::info('Expired pending registrations pruned.', ['count' => $deleted]);
        }
    }
}
