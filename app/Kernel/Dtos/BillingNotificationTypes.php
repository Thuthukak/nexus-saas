<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

/** Notification types the billing engine and plan management raise. Core registers them (in-app + email). */
final class BillingNotificationTypes
{
    public const PLAN_CHANGED = 'billing.plan_changed';
    public const PLAN_CHANGE_BLOCKED = 'billing.plan_change_blocked';
    public const PAYMENT_FAILED = 'billing.payment_failed';

    public const ALL = [self::PLAN_CHANGED, self::PLAN_CHANGE_BLOCKED, self::PAYMENT_FAILED];
}
