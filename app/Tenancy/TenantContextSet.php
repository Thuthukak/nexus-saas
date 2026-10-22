<?php

declare(strict_types=1);

namespace App\Tenancy;

/** Fired whenever the current tenant is set, so modules can scope their own libraries (e.g. role teams). */
final class TenantContextSet
{
    public function __construct(public readonly string $tenantId)
    {
    }
}
