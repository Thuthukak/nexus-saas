<?php

namespace Modules\Support\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Permissions\PermissionCatalogue;

class SupportServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $this->app->make(PermissionCatalogue::class)->register('Support', [
            'support.tickets.view_all',
        ]);
    }

    /**
     * The name of the module.
     */
    protected string $name = 'Support';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'support';

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
