<?php

declare(strict_types=1);

namespace Modules\Core\Notifications;

use Illuminate\Support\Facades\Mail;
use Modules\Core\Notifications\Mail\NotificationMail;

final class PlatformMailerEmailSender implements NotificationEmailSender
{
    public function queue(string $tenantId, string $toEmail, string $subject, ?string $body): void
    {
        Mail::to($toEmail)->queue(new NotificationMail($subject, $body));
    }
}
