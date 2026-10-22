<?php

namespace Modules\Financial\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Permissions\PermissionCatalogue;

class FinancialServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $this->app->make(PermissionCatalogue::class)->register('Financial', [
            'financial.invoices.create',
            'financial.invoices.manage',
            'financial.reports.view',
        ]);
    }
    
    /**
     * The name of the module.
     */
    protected string $name = 'Financial';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'financial';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Define module schedules.
     * 
     * @param $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
