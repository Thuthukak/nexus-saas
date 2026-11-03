<?php

declare(strict_types=1);

namespace App\Kernel\Contracts;

/** Lets plan management count a tenant's active workspace users without reading Core's tables. */
interface WorkspaceUserCounter
{
    public function activeUsers(string $tenantId): int;
}
