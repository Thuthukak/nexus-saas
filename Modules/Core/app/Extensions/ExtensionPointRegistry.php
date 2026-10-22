<?php

declare(strict_types=1);

namespace Modules\Core\Extensions;

use App\Kernel\Contracts\ExtensionPointRegistrar;
use App\Platform\Modules\ModuleKey;
use InvalidArgumentException;

/**
 * CoreExtensionPoints. Core PUBLISHES named hooks at boot; modules REGISTER components against
 * them. A module can only register into a hook that has been published (never an arbitrary spot),
 * and only a component under its own folder (never another module's UI).
 *
 * Registrations are code-level and global. Per-tenant visibility is decided at read time by
 * ExtensionResolver from the modules enabled for that tenant, so disabling a module deregisters
 * its components automatically.
 */
final class ExtensionPointRegistry implements ExtensionPointRegistrar
{
    private const HOOK = '/^[A-Z][A-Za-z0-9]{2,59}$/';
    private const COMPONENT = '/^[A-Za-z0-9]+(\/[A-Za-z0-9_\-]+)+$/';

    /** @var array<string, string> hook => description */
    private array $hooks = [];

    /** @var array<string, array<string, array{module_key:string, component:string, label:string, order_index:int}>> */
    private array $registrations = [];

    public function publish(string $hook, string $description): void
    {
        if (preg_match(self::HOOK, $hook) !== 1) {
            throw new InvalidArgumentException("Invalid hook name [{$hook}].");
        }
        $this->hooks[$hook] = $description;
        $this->registrations[$hook] ??= [];
    }

    public function isPublished(string $hook): bool
    {
        return isset($this->hooks[$hook]);
    }

    /** @return list<string> */
    public function published(): array
    {
        return array_keys($this->hooks);
    }

    public function register(string $hook, string $moduleKey, string $component, string $label, int $orderIndex = 100): void
    {
        if (! $this->isPublished($hook)) {
            throw new InvalidArgumentException("Hook [{$hook}] has not been published by Core; modules cannot inject into arbitrary points.");
        }
        if (ModuleKey::tryFrom($moduleKey) === null) {
            throw new InvalidArgumentException("Unknown module [{$moduleKey}].");
        }
        if (preg_match(self::COMPONENT, $component) !== 1 || ! str_starts_with($component, $moduleKey.'/')) {
            throw new InvalidArgumentException("Component [{$component}] must be a path under the registering module's own folder ({$moduleKey}/...).");
        }
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 60) {
            throw new InvalidArgumentException('Label must be 1 to 60 characters.');
        }
        if ($orderIndex < 0 || $orderIndex > 10000) {
            throw new InvalidArgumentException('order_index must be between 0 and 10000.');
        }

        // Same (module, component) registered twice replaces the earlier entry (idempotent boot).
        $this->registrations[$hook][$moduleKey.'|'.$component] = [
            'module_key' => $moduleKey, 'component' => $component, 'label' => $label, 'order_index' => $orderIndex,
        ];
    }

    /**
     * Every registration for a hook, in render order (order_index, then module, then component: deterministic).
     *
     * @return list<array{module_key:string, component:string, label:string, order_index:int}>
     */
    public function registrationsFor(string $hook): array
    {
        $list = array_values($this->registrations[$hook] ?? []);
        usort($list, fn ($a, $b) => [$a['order_index'], $a['module_key'], $a['component']] <=> [$b['order_index'], $b['module_key'], $b['component']]);

        return $list;
    }
}
