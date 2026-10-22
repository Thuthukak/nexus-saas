<?php

declare(strict_types=1);

namespace App\Platform\Modules;

use App\Kernel\Dtos\ModuleStateChanged;
use App\Kernel\Dtos\ModuleStateChangedDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Enables and disables modules for ONE tenant, enforcing: plan/add-on entitlement,
 * Core always on, declared prerequisites, and no disabling a module another enabled
 * module still requires. Disabling never deletes data (rows stay in place).
 */
final class ModuleManager
{
    public function __construct(private readonly Entitlements $entitlements)
    {
    }

    public static function cacheKey(string $tenantId): string
    {
        return "tenant:{$tenantId}:active_modules"; // tenant id in every cache key (rule 16)
    }

    /** @return list<string> enabled module keys, cached per tenant */
    public function activeModules(string $tenantId): array
    {
        return Cache::remember(self::cacheKey($tenantId), 300, fn () => DB::table('tenant_modules')
            ->where('tenant_id', $tenantId)
            ->where('is_enabled', true)
            ->orderBy('module_key')
            ->pluck('module_key')
            ->all());
    }

    public function enable(string $tenantId, string $module): void
    {
        $event = DB::transaction(function () use ($tenantId, $module): ?ModuleStateChanged {
            if (! $this->entitlements->isEntitled($tenantId, $module)) {
                throw ModuleException::notEntitled($module);
            }

            $enabled = $this->lockedEnabledSet($tenantId);

            if (in_array($module, $enabled, true)) {
                return null; // already on: idempotent
            }

            $graph = DependencyGraph::fromDatabase();
            $missing = array_values(array_diff($graph->requiresOf($module), $enabled));
            if ($missing !== []) {
                throw ModuleException::missingPrerequisites($module, $missing);
            }

            $existing = DB::table('tenant_modules')->where('tenant_id', $tenantId)->where('module_key', $module)->first();
            $firstTime = $existing === null || $existing->first_enabled_at === null;
            $now = now();

            DB::table('tenant_modules')->updateOrInsert(
                ['tenant_id' => $tenantId, 'module_key' => $module],
                [
                    'is_enabled' => true,
                    'enabled_at' => $now,
                    'first_enabled_at' => $existing->first_enabled_at ?? $now,
                    'disabled_at' => null,
                    'updated_at' => $now,
                ],
            );

            return new ModuleStateChanged(new ModuleStateChangedDTO($tenantId, $module, true, $firstTime));
        });

        $this->afterChange($tenantId, $event);
    }

    public function disable(string $tenantId, string $module): void
    {
        if ($module === ModuleKey::Core->value) {
            throw ModuleException::coreAlwaysEnabled();
        }

        $event = DB::transaction(function () use ($tenantId, $module): ?ModuleStateChanged {
            $enabled = $this->lockedEnabledSet($tenantId);

            if (! in_array($module, $enabled, true)) {
                return null; // already off: idempotent
            }

            $blocking = array_values(array_intersect(DependencyGraph::fromDatabase()->dependentsOf($module), $enabled));
            if ($blocking !== []) {
                throw ModuleException::hasEnabledDependents($module, $blocking);
            }

            DB::table('tenant_modules')
                ->where('tenant_id', $tenantId)->where('module_key', $module)
                ->update(['is_enabled' => false, 'disabled_at' => now(), 'updated_at' => now()]);

            return new ModuleStateChanged(new ModuleStateChangedDTO($tenantId, $module, false, false));
        });

        $this->afterChange($tenantId, $event);
    }

    /** @return list<string> enabled modules, with this tenant's rows locked against concurrent toggles */
    private function lockedEnabledSet(string $tenantId): array
    {
        return DB::table('tenant_modules')
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->get()
            ->where('is_enabled', true)
            ->pluck('module_key')
            ->values()
            ->all();
    }

    private function afterChange(string $tenantId, ?ModuleStateChanged $event): void
    {
        Cache::forget(self::cacheKey($tenantId));

        if ($event !== null) {
            // Modules listening for this deregister hooks/widgets or seed first-time data.
            event($event);
        }
    }
}
