<?php

declare(strict_types=1);

namespace Modules\Core\Notifications;

use App\Kernel\Dtos\NotificationRequestedDTO;
use App\Kernel\Dtos\UsageThresholdReached;
use App\Tenancy\TenantContext;
use Modules\Core\Models\User;

/** The 80% / 100% usage notices (sent once by UsageMeter) reach every active Tenant Admin. */
final class NotifyAdminsOfUsageThreshold
{
    public const TYPE = 'core.usage_threshold';

    public function __construct(private readonly TenantContext $context, private readonly Notifier $notifier)
    {
    }

    public function handle(UsageThresholdReached $event): void
    {
        $dto = $event->payload();
        $previous = $this->context->id();
        $this->context->set($dto->tenantId);

        try {
            $title = $dto->percent >= 100
                ? "You have reached your {$dto->metric->value} limit"
                : "You have used {$dto->percent}% of your {$dto->metric->value} limit";

            foreach (User::role('Tenant Admin')->where('is_active', true)->get() as $admin) {
                $this->notifier->notify(new NotificationRequestedDTO(
                    $dto->tenantId, $admin->id, self::TYPE, $title,
                    "Period {$dto->period}: {$dto->used} of {$dto->limit} used.",
                    json_encode(['metric' => $dto->metric->value, 'percent' => $dto->percent], JSON_THROW_ON_ERROR),
                ));
            }
        } finally {
            $previous !== null ? $this->context->set($previous) : $this->context->clear();
        }
    }
}
