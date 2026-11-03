<?php

declare(strict_types=1);

namespace App\Platform\Billing;

/** Pure: what a processor event does to a subscription's status. Cancelled is final. */
final class SubscriptionState
{
    public const ACTIVE = 'active';
    public const PAST_DUE = 'past_due';
    public const CANCELLED = 'cancelled';

    /** @return string the new status (equal to $current when the event changes nothing) */
    public static function after(string $current, string $eventType): string
    {
        if ($current === self::CANCELLED) {
            return self::CANCELLED;
        }

        return match ($eventType) {
            BillingEvent::RENEWAL_SUCCEEDED => self::ACTIVE,
            BillingEvent::PAYMENT_FAILED => self::PAST_DUE,
            BillingEvent::CANCELLED => self::CANCELLED,
            default => $current,
        };
    }

    /** Has this past-due subscription used up its grace period? */
    public static function graceExpired(\DateTimeInterface $pastDueSince, \DateTimeInterface $now, int $graceDays): bool
    {
        return $now->getTimestamp() >= $pastDueSince->getTimestamp() + max(0, $graceDays) * 86400;
    }

    public static function periodEnd(\DateTimeImmutable $start, string $cycle): \DateTimeImmutable
    {
        return $cycle === 'annual' ? $start->modify('+1 year') : $start->modify('+1 month');
    }
}
