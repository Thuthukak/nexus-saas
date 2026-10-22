<?php

declare(strict_types=1);

namespace Modules\Core\Dashboard;

use App\Kernel\Contracts\DashboardWidgetRegistrar;
use App\Platform\Modules\ModuleKey;
use InvalidArgumentException;

/**
 * CoreDashboardRegistry. Code-level registrations; which ones a tenant sees is decided at read time
 * from its enabled modules (so disabling a module deregisters its widgets automatically).
 */
final class DashboardRegistry implements DashboardWidgetRegistrar
{
    /** @var array<string, array<string, mixed>> keyed "module.widget" */
    private array $widgets = [];

    public function register(
        string $moduleKey,
        string $widgetId,
        string $title,
        string $dataEndpoint,
        int $x,
        int $y,
        int $w,
        int $h,
        ?string $permission = null,
    ): void {
        if (ModuleKey::tryFrom($moduleKey) === null) {
            throw new InvalidArgumentException("Unknown module [{$moduleKey}].");
        }
        if (preg_match('/^[a-z0-9][a-z0-9_\-]{1,59}$/', $widgetId) !== 1) {
            throw new InvalidArgumentException("Invalid widget id [{$widgetId}].");
        }
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 80) {
            throw new InvalidArgumentException('Widget title must be 1 to 80 characters.');
        }
        self::assertEndpointBelongsTo($moduleKey, $dataEndpoint);
        if ($x < 0 || $w < 1 || $x + $w > GridLayout::COLUMNS || $y < 0 || $y > 1000 || $h < 1 || $h > 12) {
            throw new InvalidArgumentException('Grid position is outside the 12-column grid.');
        }

        $id = strtolower($moduleKey).'.'.$widgetId;
        $this->widgets[$id] = [
            'id' => $id, 'module_key' => $moduleKey, 'title' => $title, 'data_endpoint' => $dataEndpoint,
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'permission' => $permission,
        ];
    }

    /** The data endpoint must be a plain same-origin path under THE REGISTERING MODULE's own prefix. */
    public static function assertEndpointBelongsTo(string $moduleKey, string $endpoint): void
    {
        $prefix = '/'.strtolower($moduleKey).'/';
        if (preg_match('#^/[a-z]+/[A-Za-z0-9_\-/.]*$#', $endpoint) !== 1
            || ! str_starts_with($endpoint, $prefix)
            || str_contains($endpoint, '..') || str_contains($endpoint, '//')) {
            throw new InvalidArgumentException("Data endpoint [{$endpoint}] must be a path under {$prefix} (the registering module's own routes).");
        }
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values($this->widgets);
    }
}
