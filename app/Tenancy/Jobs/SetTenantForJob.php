<?php

declare(strict_types=1);

namespace App\Tenancy\Jobs;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Queue job middleware: `return [new SetTenantForJob($this->tenantId)];`
 * Same guarantee as TenantMiddleware: RLS variable set inside a transaction.
 */
final class SetTenantForJob
{
    public function __construct(private readonly string $tenantId)
    {
    }

    public function handle(object $job, Closure $next): mixed
    {
        $context = app(TenantContext::class);

        try {
            return DB::transaction(function () use ($job, $next, $context) {
                $context->set($this->tenantId);

                return $next($job);
            });
        } finally {
            $context->clear();
        }
    }
}
