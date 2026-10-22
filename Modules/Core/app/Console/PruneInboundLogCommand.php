<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneInboundLogCommand extends Command
{
    protected $signature = 'email:prune-inbound-log {--days= : Keep this many days (default from config)}';

    protected $description = 'Delete old inbound email routing log rows';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('inbound_mail.log_retention_days', 60)));
        $n = DB::table('inbound_email_log')->where('created_at', '<', now()->subDays($days))->delete();
        $this->info("Deleted {$n} row(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
