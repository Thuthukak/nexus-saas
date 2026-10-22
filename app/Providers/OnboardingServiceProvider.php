<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\Onboarding\SendWelcomeEmail;
use App\Platform\Onboarding\TenantProvisioned;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class OnboardingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Public signup creates tenants and sends email: throttle per IP.
        RateLimiter::for('signup', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        Event::listen(TenantProvisioned::class, SendWelcomeEmail::class);
    }
}
