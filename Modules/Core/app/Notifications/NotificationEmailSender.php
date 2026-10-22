<?php

declare(strict_types=1);

namespace Modules\Core\Notifications;

/**
 * Seam to CoreEmailIntegration. Until that feature exists the default implementation sends
 * through the platform mailer; CoreEmailIntegration will rebind this to the tenant's own
 * outbound configuration. Notifier never knows the difference.
 */
interface NotificationEmailSender
{
    public function queue(string $tenantId, string $toEmail, string $subject, ?string $body): void;
}
