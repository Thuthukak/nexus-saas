<?php

declare(strict_types=1);

namespace Modules\Core\Permissions;

use RuntimeException;

final class PermissionException extends RuntimeException
{
    public static function invalidKey(string $key): self
    {
        return new self("'{$key}' is not a valid permission key; use module.resource.action.");
    }

    public static function superAdminProtected(): self
    {
        return new self('The Super Admin role always keeps the wildcard permission and cannot be edited or deleted.');
    }
}
