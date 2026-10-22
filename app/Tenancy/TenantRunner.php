<?php

declare(strict_types=1);

namespace App\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs code for ONE tenant outside a web request (commands, schedulers): the same guarantee as
 * TenantMiddleware/SetTenantForJob. A transaction with the RLS variable set, tenant context
 * announced, and the previous context restored afterwards.
 */
final class TenantRunner
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function run(string $tenantId, Closure $callback): mixed
    {
        $previous = $this->context->id();

        try {
            return DB::transaction(function () use ($tenantId, $callback) {
                $this->context->set($tenantId);

                return $callback();
            });
        } finally {
            $previous !== null ? $this->context->set($previous) : $this->context->clear();
        }
    }
}
