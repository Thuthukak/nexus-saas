<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Modules\ModuleDependencyRegistry;
use App\Platform\Modules\ModuleException;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class SyncModuleDependencies extends Command
{
    protected $signature = 'modules:sync-dependencies';
    protected $description = 'Validate config/module-dependencies.php and write it to the module_dependencies table';

    public function handle(ModuleDependencyRegistry $registry): int
    {
        try {
            $registry->sync((array) config('module-dependencies'));
        } catch (InvalidArgumentException|ModuleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Module dependencies synced.');

        return self::SUCCESS;
    }
}
