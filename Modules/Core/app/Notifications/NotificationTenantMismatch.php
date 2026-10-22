<?php

declare(strict_types=1);

namespace Modules\Core\Notifications;

use RuntimeException;

/** A notification was requested for a user who is not a member of the resolved tenant. */
final class NotificationTenantMismatch extends RuntimeException
{
}
