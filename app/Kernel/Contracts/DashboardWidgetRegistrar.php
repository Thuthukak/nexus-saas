<?php

declare(strict_types=1);

namespace App\Kernel\Contracts;

/**
 * How a module contributes a widget to Core's otherwise empty dashboard grid. Call from the module's
 * provider boot(). The widget fetches its OWN data from its OWN endpoint; Core never queries a
 * module's tables. A tenant only sees widgets of modules enabled for it (deregistration is automatic).
 */
interface DashboardWidgetRegistrar
{
    /**
     * @param string      $dataEndpoint a same-origin path under the registering module's own prefix, e.g. '/support/widgets/open-tickets'
     * @param int         $x            0-11, $w 1-12, x + w <= 12 (a 12-column grid); $y >= 0, $h 1-12
     * @param string|null $permission   if set, only users who hold it see the widget
     * @throws \InvalidArgumentException invalid id/title/endpoint/grid
     */
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
    ): void;
}
