<?php

declare(strict_types=1);

namespace Modules\Core\Dashboard;

use App\Platform\Modules\ModuleManager;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Access\Authorizable;

/** ListDashboardWidgets: the tenant's assembled grid. Returns layout and endpoints only; never widget data. */
final class DashboardService
{
    public function __construct(
        private readonly DashboardRegistry $registry,
        private readonly ModuleManager $modules,
        private readonly TenantContext $context,
    ) {
    }

    /** @return array{widget_count:int, widgets:list<array<string, mixed>>} */
    public function assemble(?Authorizable $viewer): array
    {
        $active = $this->modules->activeModules($this->context->requireId());

        $visible = array_filter($this->registry->all(), function (array $w) use ($active, $viewer): bool {
            if (! in_array($w['module_key'], $active, true)) {
                return false;   // module disabled for this tenant: its widgets are gone
            }

            return $w['permission'] === null || ($viewer !== null && $viewer->can($w['permission']));
        });

        $byId = [];
        foreach ($visible as $w) {
            $byId[$w['id']] = $w;
        }

        $widgets = array_map(fn (array $p) => [
            'id' => $p['id'],
            'module_key' => $byId[$p['id']]['module_key'],
            'title' => $byId[$p['id']]['title'],
            'data_endpoint' => $byId[$p['id']]['data_endpoint'],
            'grid' => ['x' => $p['x'], 'y' => $p['y'], 'w' => $p['w'], 'h' => $p['h']],
        ], GridLayout::resolve(array_map(fn ($w) => ['id' => $w['id'], 'x' => $w['x'], 'y' => $w['y'], 'w' => $w['w'], 'h' => $w['h']], array_values($byId))));

        return ['widget_count' => count($widgets), 'widgets' => $widgets];
    }
}
