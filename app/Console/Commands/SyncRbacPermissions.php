<?php

namespace App\Console\Commands;

use App\Models\Permission;
use Illuminate\Console\Command;

class SyncRbacPermissions extends Command
{
    protected $signature = 'rbac:sync-permissions';

    protected $description = 'Register configured RBAC permissions without deleting stale permissions';

    public function handle(): int
    {
        $configured = collect(config('rbac.resources', []))
            ->flatMap(fn (array $resource, string $name) => collect($resource['actions'] ?? [])
                ->map(fn (string $action) => "{$name}.{$action}"));
        $created = 0;

        foreach ($configured as $permission) {
            $created += Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'api',
            ])->wasRecentlyCreated ? 1 : 0;
        }

        $stale = Permission::query()->where('guard_name', 'api')
            ->whereNotIn('name', $configured->all())->pluck('name');
        $this->info("RBAC permissions synchronized. Created: {$created}.");

        if ($stale->isNotEmpty()) {
            $this->warn('Stale permissions were kept because automatic deletion is disabled:');
            $stale->each(fn (string $permission) => $this->line("- {$permission}"));
        }

        return self::SUCCESS;
    }
}
