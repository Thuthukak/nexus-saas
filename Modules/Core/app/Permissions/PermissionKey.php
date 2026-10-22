<?php

declare(strict_types=1);

namespace Modules\Core\Permissions;

/** Pure helper: permission keys follow module.resource.action, e.g. financial.invoices.create. */
final class PermissionKey
{
    /** Held only by the protected Super Admin role. Never accepted from user input. */
    public const WILDCARD = '*';

    public const SUPER_ADMIN_ROLE = 'Super Admin';

    private const PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    public static function isValid(string $key): bool
    {
        return preg_match(self::PATTERN, $key) === 1;
    }
}
