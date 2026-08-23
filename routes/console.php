<?php

use App\Console\Commands\SyncRbacPermissions;
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

// Run `php artisan rbac:sync-permissions` after adding or changing resources/actions
// in config/rbac.php, before assigning the new permissions to a role.
// Docker: `docker compose exec app php artisan rbac:sync-permissions`
Artisan::command('rbac:sync-permissions', function (): void {
    $this->call(SyncRbacPermissions::class);
})->purpose('Register configured RBAC permissions safely');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
