<?php

declare(strict_types=1);

namespace Modules\Core\Extensions;

use App\Platform\Modules\ModuleManager;
use App\Tenancy\TenantContext;

/** What the ambient tenant actually sees: registrations of modules that are enabled for it. */
final class ExtensionResolver
{
    public function __construct(
        private readonly ExtensionPointRegistry $registry,
        private readonly ModuleManager $modules,
        private readonly TenantContext $context,
    ) {
    }

    /** @return list<array{module_key:string, component:string, label:string, order_index:int}> */
    public function forHook(string $hook): array
    {
        $active = $this->modules->activeModules($this->context->requireId());

        return array_values(array_filter(
            $this->registry->registrationsFor($hook),
            fn (array $r) => in_array($r['module_key'], $active, true),
        ));
    }

    /** @return array<string, list<array>> every published hook with its visible registrations */
    public function all(): array
    {
        $out = [];
        foreach ($this->registry->published() as $hook) {
            $out[$hook] = $this->forHook($hook);
        }

        return $out;
    }
}
