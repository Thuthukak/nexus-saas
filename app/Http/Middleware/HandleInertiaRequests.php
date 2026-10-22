<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;
use Modules\Core\Extensions\ExtensionResolver;
use Modules\Core\Terminology\TerminologyService;
use Modules\Core\Theming\ThemeService;

/**
 * REPLACES overlay 7. Shares what every tenant page needs: tenant, user, permissions, modules, theme.
 *
 * IMPORTANT: Inertia calls share() BEFORE the route middleware (TenantMiddleware) has run, so the
 * tenant is not yet on the request. Every prop is therefore a closure, resolved later when the
 * page response is built, by which time the tenant and the session are available.
 * (Overlay 5 read the tenant eagerly here, so `tenant` and `auth.user` came through as null.)
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $tenant = fn () => $request->attributes->get('tenant');
        $user = fn () => $tenant() !== null ? $request->user('workspace') : null;

        return array_merge(parent::share($request), [
            'tenant' => fn () => $tenant() ? ['name' => $tenant()->name, 'subdomain' => $tenant()->subdomain] : null,
            'auth' => fn () => [
                'user' => $user() ? ['id' => $user()->id, 'name' => $user()->name, 'email' => $user()->email] : null,
                'permissions' => $user() ? $user()->getAllPermissions()->pluck('name')->values() : [],
            ],
            'modules' => fn () => $user()
                ? DB::table('tenant_modules')->where('tenant_id', $tenant()->id)->where('is_enabled', true)->orderBy('module_key')->pluck('module_key')
                : [],
            // Shared before login too: the login page is branded with the tenant's colours.
            'theme' => fn () => $tenant() ? app(ThemeService::class)->current()->tokens() : null,
            // Display vocabulary (Party -> Customer/Patient/Student ...), resolved for the tenant.
            'terms' => fn () => $tenant() ? app(TerminologyService::class)->all() : null,
            // Components other modules plugged into published hooks, for the modules enabled for this tenant.
            'extensions' => fn () => $user() ? app(ExtensionResolver::class)->all() : [],
            'flash' => ['status' => fn () => $request->session()->get('status')],
        ]);
    }
}
