<?php

declare(strict_types=1);

namespace Modules\Core\Permissions;

/** Process-wide list of the permission keys each module offers to tenants. */
final class PermissionCatalogue
{
    /** @var array<string, list<string>> */
    private array $byModule = [];

    /** @param list<string> $keys module.resource.action keys */
    public function register(string $module, array $keys): void
    {
        foreach ($keys as $key) {
            if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $key) !== 1) {
                throw new \InvalidArgumentException("Invalid permission key '{$key}' for {$module}");
            }
        }
        $this->byModule[$module] = array_values(array_unique([...($this->byModule[$module] ?? []), ...$keys]));
    }

    /**
     * @param list<string> $enabledModules
     * @return list<string>
     */
    public function forModules(array $enabledModules): array
    {
        $keys = [];
        foreach ($enabledModules as $m) {
            foreach ($this->byModule[$m] ?? [] as $k) {
                $keys[$k] = true;
            }
        }

        return array_keys($keys);
    }
}
