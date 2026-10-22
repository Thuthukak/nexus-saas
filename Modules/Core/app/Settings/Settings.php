<?php

declare(strict_types=1);

namespace Modules\Core\Settings;

use App\Kernel\Dtos\SettingsUpdated;
use App\Kernel\Dtos\SettingsUpdatedDTO;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;

/**
 * The shared Settings service (facade: Modules\Core\Facades\Settings). Other modules read and
 * write settings through it, never by querying the table.
 *
 * Cache: one entry per (tenant, scope) holding that scope's whole key=>value map. The tenant id
 * is part of every cache key, taken from the TenantContext, so a cache hit can never cross tenants.
 */
final class Settings
{
    public function __construct(
        private readonly SettingRegistry $registry,
        private readonly TenantContext $context,
        private readonly Cache $cache,
    ) {
    }

    public function get(string $scope, string $key): mixed
    {
        $def = $this->registry->definition($scope, $key);
        $all = $this->scopeValues($this->context->requireId(), $scope);

        return array_key_exists($key, $all) ? $all[$key] : $def['default'];
    }

    /** All registered keys of a scope with defaults filled in. @return array<string, mixed> */
    public function all(string $scope): array
    {
        $stored = $this->scopeValues($this->context->requireId(), $scope);

        return array_merge($this->registry->defaults($scope), array_intersect_key($stored, $this->registry->defaults($scope)));
    }

    public function set(string $scope, string $key, mixed $value): void
    {
        $this->setMany($scope, [$key => $value]);
    }

    /**
     * Validates every key first, then writes them all in one transaction (all or nothing).
     *
     * @param array<string, mixed> $values
     */
    public function setMany(string $scope, array $values): void
    {
        $tenantId = $this->context->requireId();

        foreach ($values as $key => $value) {
            $def = $this->registry->definition($scope, (string) $key); // throws if unregistered
            if (! SettingRegistry::matchesType($def['type'], $value)) {
                throw new SettingsException("Setting [{$scope}.{$key}] expects {$def['type']}.");
            }
        }

        DB::transaction(function () use ($tenantId, $scope, $values): void {
            foreach ($values as $key => $value) {
                // Explicit ?::jsonb cast: the driver sends the JSON as text.
                DB::statement(
                    'INSERT INTO tenant_settings (tenant_id, scope, key, value, created_at, updated_at)
                     VALUES (?, ?, ?, ?::jsonb, ?, ?)
                     ON CONFLICT (tenant_id, scope, key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
                    [$tenantId, $scope, (string) $key, json_encode($value, JSON_THROW_ON_ERROR), now(), now()],
                );
            }
        });

        // Clear now, and again once the outermost transaction commits (a concurrent reader may
        // have re-cached the old value between our write and our commit).
        $this->forget($tenantId, $scope);
        DB::afterCommit(fn () => $this->forget($tenantId, $scope));

        foreach ($values as $key => $value) {
            event(new SettingsUpdated(new SettingsUpdatedDTO(
                $tenantId, $scope, (string) $key, json_encode($value, JSON_THROW_ON_ERROR),
            )));
        }
    }

    public function forget(string $tenantId, string $scope): void
    {
        $this->cache->forget(self::cacheKey($tenantId, $scope));
    }

    public static function cacheKey(string $tenantId, string $scope): string
    {
        return "tenant:{$tenantId}:settings:{$scope}";
    }

    /** @return array<string, mixed> */
    private function scopeValues(string $tenantId, string $scope): array
    {
        return $this->cache->remember(self::cacheKey($tenantId, $scope), 3600, function () use ($tenantId, $scope): array {
            // Explicit tenant_id filter on top of RLS (never rely on one layer).
            return DB::table('tenant_settings')
                ->where('tenant_id', $tenantId)->where('scope', $scope)
                ->pluck('value', 'key')
                ->map(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR))
                ->all();
        });
    }
}
