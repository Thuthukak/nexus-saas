<?php

declare(strict_types=1);

namespace App\Platform\Modules;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pure representation of CoreModuleDependencyRegistry.
 * Rules: only Core may have an empty requires list; every other module must
 * require at least Core; unknown modules are rejected; cycles are rejected.
 */
final class DependencyGraph
{
    /** @var array<string, list<string>> */
    private array $requires;

    /** @param array<string, list<string>> $requires module => modules it requires */
    public function __construct(array $requires)
    {
        self::validate($requires);
        $this->requires = $requires;
    }

    public static function fromDatabase(): self
    {
        $requires = array_fill_keys(array_map(fn (ModuleKey $k) => $k->value, ModuleKey::cases()), []);

        foreach (DB::table('module_dependencies')->get(['module_key', 'requires_key']) as $row) {
            $requires[$row->module_key][] = $row->requires_key;
        }

        return new self($requires);
    }

    /** @param array<string, list<string>> $requires */
    public static function validate(array $requires): void
    {
        $known = array_map(fn (ModuleKey $k) => $k->value, ModuleKey::cases());

        foreach ($requires as $module => $deps) {
            if (! in_array($module, $known, true)) {
                throw new InvalidArgumentException("Unknown module '{$module}'.");
            }
            foreach ($deps as $dep) {
                if (! in_array($dep, $known, true)) {
                    throw new InvalidArgumentException("Module '{$module}' requires unknown module '{$dep}'.");
                }
            }
            if ($module === ModuleKey::Core->value) {
                if ($deps !== []) {
                    throw new InvalidArgumentException('Core must not require any module.');
                }
                continue;
            }
            if (! in_array(ModuleKey::Core->value, $deps, true)) {
                throw new InvalidArgumentException("Module '{$module}' must declare Core as a prerequisite.");
            }
        }

        self::assertAcyclic($requires);
    }

    /** @param array<string, list<string>> $requires */
    private static function assertAcyclic(array $requires): void
    {
        $state = []; // 1 = visiting, 2 = done

        $visit = function (string $node, array $path) use (&$visit, &$state, $requires): void {
            if (($state[$node] ?? 0) === 2) {
                return;
            }
            if (($state[$node] ?? 0) === 1) {
                throw new InvalidArgumentException('Circular module dependency: '.implode(' -> ', [...$path, $node]));
            }
            $state[$node] = 1;
            foreach ($requires[$node] ?? [] as $dep) {
                $visit($dep, [...$path, $node]);
            }
            $state[$node] = 2;
        };

        foreach (array_keys($requires) as $module) {
            $visit($module, []);
        }
    }

    /** @return list<string> */
    public function requiresOf(string $module): array
    {
        return $this->requires[$module] ?? [];
    }

    /** @return list<string> modules that declare $module as a prerequisite */
    public function dependentsOf(string $module): array
    {
        $out = [];
        foreach ($this->requires as $m => $deps) {
            if (in_array($module, $deps, true)) {
                $out[] = $m;
            }
        }

        return $out;
    }
}
