<?php

declare(strict_types=1);

namespace App\Tenancy\Middleware;

use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Control-plane routes answer only on admin.pendagraphics.co.za. */
final class ControlPlaneOnly
{
    public function __construct(private readonly TenantResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->resolver->isControlPlaneHost($request->getHost()), 404);

        return $next($request);
    }
}
