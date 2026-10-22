<?php

declare(strict_types=1);

namespace Modules\Core\Usage;

use App\Kernel\Dtos\UsageRecorded;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Core listens; Financial and Support only dispatch UsageRecorded and never call Core.
 *
 * Metering must NEVER block the business flow (rule 26: no limit may reject a payment or a
 * ticket). So a failure here is reported and swallowed, and runs inside a savepoint so a
 * failed statement cannot poison the caller's PostgreSQL transaction.
 */
final class RecordUsageListener
{
    public function __construct(private readonly UsageMeter $meter)
    {
    }

    public function handle(UsageRecorded $event): void
    {
        try {
            DB::transaction(fn () => $this->meter->record($event->dto));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
