<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\EnforcePastDueCommand;
use App\Platform\Billing\CreateProcessorSubscription;
use App\Platform\Billing\ProcessorRegistry;
use App\Platform\Onboarding\TenantProvisioned;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Add to bootstrap/providers.php. */
final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProcessorRegistry::class);
    }

    public function boot(): void
    {
        Event::listen(TenantProvisioned::class, [CreateProcessorSubscription::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([EnforcePastDueCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('billing:enforce-past-due')->hourly()->withoutOverlapping());
    }
}
