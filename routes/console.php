<?php

use App\Jobs\PruneExpiredOtpChallenges;
use App\Jobs\PruneExpiredPendingRegistrations;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new PruneExpiredPendingRegistrations)
    ->name('prune-expired-pending-registrations')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new PruneExpiredOtpChallenges)
    ->name('prune-expired-otp-challenges')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
