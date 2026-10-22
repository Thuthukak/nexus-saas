<?php

declare(strict_types=1);

namespace App\Kernel\Contracts;

/**
 * How a module plugs a UI component into a screen Core (or another module) has explicitly published.
 * Call from the module's service provider boot(). Registration is global (code); what a TENANT sees
 * is filtered by the modules enabled for that tenant, so disabling a module removes its components
 * with no stale state to clean up.
 */
interface ExtensionPointRegistrar
{
    /**
     * @param string $hook       a published hook, e.g. 'CustomerProfileTabs'
     * @param string $moduleKey  the registering module, e.g. 'Support'
     * @param string $component  'Support/CustomerTicketsTab': MUST live under the registering module's own folder
     * @param string $label      short text for tabs/menus
     * @throws \InvalidArgumentException when the hook is not published, or the component is outside the module's folder
     */
    public function register(string $hook, string $moduleKey, string $component, string $label, int $orderIndex = 100): void;
}
