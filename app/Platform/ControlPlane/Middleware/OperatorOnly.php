<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Signed-in, still-active operator required. A deactivated operator is signed out on their very next request. */
final class OperatorOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = auth('operator');
        $operator = $guard->user();

        if ($operator !== null && ! $operator->is_active) {
            $guard->logout();
            $request->session()->invalidate();
            $operator = null;
        }

        if ($operator === null) {
            return $request->expectsJson() ? response()->json(['message' => 'Unauthenticated'], 401) : redirect('/login');
        }

        $request->attributes->set('operator', $operator);

        return $next($request);
    }
}
