<?php

declare(strict_types=1);

namespace Modules\Core\Notifications;

use App\Kernel\Dtos\NotificationRequested;
use App\Tenancy\TenantContext;

/** Entry point for other modules: they dispatch NotificationRequested, Core does the rest. */
final class HandleNotificationRequested
{
    public function __construct(private readonly TenantContext $context, private readonly Notifier $notifier)
    {
    }

    public function handle(NotificationRequested $event): void
    {
        // Run under the event's tenant (jobs/commands may have no ambient tenant), then restore.
        $previous = $this->context->id();
        $this->context->set($event->tenantId());
        try {
            $this->notifier->notify($event->dto);
        } finally {
            $previous !== null ? $this->context->set($previous) : $this->context->clear();
        }
    }
}
