<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * REPLACES the overlay-1 file. Same behaviour, plus it announces the tenant so
 * modules can scope their own libraries without Tenancy knowing about them.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    public function set(string $tenantId): void
    {
        $this->tenantId = $tenantId;

        // is_local = true => equivalent to SET LOCAL; lasts for the current
        // transaction only, so it is safe behind connection pooling.
        DB::select('select set_config(?, ?, true)', [config('tenancy.rls_variable'), $tenantId]);

        event(new TenantContextSet($tenantId));
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function requireId(): string
    {
        return $this->tenantId
            ?? throw new RuntimeException('No tenant resolved: refusing to run a tenant-scoped query.');
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }
}
