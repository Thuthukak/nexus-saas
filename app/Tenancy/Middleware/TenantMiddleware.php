<?php

declare(strict_types=1);

namespace App\Tenancy\Middleware;

use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the hostname, then runs the rest of the request in
 * a transaction with app.current_tenant_id set (set_config(..., true)), so RLS
 * is in force before any tenant-scoped query can run.
 */
final class TenantMiddleware
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        // The control plane is never reachable through the tenant pipeline.
        if ($this->resolver->isControlPlaneHost($host)) {
            abort(404);
        }

        $tenant = $this->resolver->resolve($host);

        if ($tenant === null) {
            abort(404);
        }

        if ($tenant->status !== Tenant::ACTIVE) {
            return response()->view('errors.suspended', ['tenant' => $tenant], 403);
        }

        $request->attributes->set('tenant', $tenant);

        try {
            return DB::transaction(function () use ($tenant, $request, $next) {
                $this->context->set($tenant->id);

                return $next($request);
            });
        } finally {
            $this->context->clear();
        }
    }
}
