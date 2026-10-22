<?php

declare(strict_types=1);

namespace App\Platform\Modules;

use InvalidArgumentException;

/**
 * Pure validation of the declared module prerequisites
 * (CoreModuleDependencyRegistry / ModuleManager rules).
 *
 * The declarations themselves live in the module_dependencies table (data, not code).
 */
final class ModuleDependencyGraph
{
    public const CORE = 'Core';

    /** @param array<string, list<string>> $requires module => hard prerequisites */
    public function __construct(private readonly array $requires)
    {
        $this->assertValid();
    }

    /** @return list<string> prerequisites of $module that are not enabled */
    public function missingPrerequisites(string $module, array $enabled): array
    {
        return array_values(array_diff($this->requires[$module] ?? [], $enabled));
    }

    /** @return list<string> enabled modules that still require $module */
    public function enabledDependents(string $module, array $enabled): array
    {
        $dependents = [];
        foreach ($this->requires as $other => $needs) {
            if ($other !== $module && in_array($module, $needs, true) && in_array($other, $enabled, true)) {
                $dependents[] = $other;
            }
        }

        return $dependents;
    }

    public function canEnable(string $module, array $enabled): bool
    {
        return $this->missingPrerequisites($module, $enabled) === [];
    }

    /** Core can never be disabled; nothing may be disabled while an enabled module needs it. */
    public function canDisable(string $module, array $enabled): bool
    {
        return $module !== self::CORE && $this->enabledDependents($module, $enabled) === [];
    }

    private function assertValid(): void
    {
        if (($this->requires[self::CORE] ?? null) !== []) {
            throw new InvalidArgumentException('Core must be declared with an empty requires list.');
        }

        foreach ($this->requires as $module => $needs) {
            if ($module !== self::CORE && ! in_array(self::CORE, $needs, true)) {
                throw new InvalidArgumentException("Module {$module} must declare Core as a prerequisite.");
            }
            foreach ($needs as $need) {
                if (! array_key_exists($need, $this->requires)) {
                    throw new InvalidArgumentException("Module {$module} requires unknown module {$need}.");
                }
            }
        }

        $this->assertNoCycles();
    }

    private function assertNoCycles(): void
    {
        $state = []; // 1 = visiting, 2 = done

        $visit = function (string $node) use (&$visit, &$state): void {
            if (($state[$node] ?? 0) === 2) {
                return;
            }
            if (($state[$node] ?? 0) === 1) {
                throw new InvalidArgumentException("Circular module dependency involving {$node}.");
            }
            $state[$node] = 1;
            foreach ($this->requires[$node] ?? [] as $next) {
                $visit($next);
            }
            $state[$node] = 2;
        };

        foreach (array_keys($this->requires) as $module) {
            $visit($module);
        }
    }
}
