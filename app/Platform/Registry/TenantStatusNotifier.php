<?php

declare(strict_types=1);

namespace App\Platform\Registry;

use App\Kernel\Contracts\EmailSender;
use App\Kernel\Contracts\TenantAdminDirectory;
use App\Kernel\Dtos\OutboundEmailDTO;
use App\Tenancy\Tenant;
use Throwable;

/** Emails every active tenant administrator. A failure to notify never undoes the status change. */
final class TenantStatusNotifier
{
    public function __construct(private readonly TenantAdminDirectory $admins, private readonly EmailSender $email)
    {
    }

    public function notify(Tenant $tenant, string $to, string $reason): void
    {
        try {
            $admins = $this->admins->activeAdmins($tenant->id);
        } catch (Throwable $e) {
            report($e);

            return;
        }
        $message = TenantStatusMessage::build($tenant->name, $to, $reason);

        foreach ($admins as $admin) {
            try {
                $this->email->send(new OutboundEmailDTO($tenant->id, $admin->email, $message['subject'], $message['text'], null, $admin->name));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
