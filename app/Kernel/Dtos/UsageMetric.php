<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

enum UsageMetric: string
{
    case UserSeats = 'user_seats';             // quantity: active workspace users (measured, not summed)
    case FinancialVolume = 'financial_volume'; // MONEY: value of payments processed, minor units + currency
    case SupportTickets = 'support_tickets';   // quantity: tickets created

    /** Money metrics carry a currency and are stored in amount_minor; the rest are plain quantities. */
    public function isMoney(): bool
    {
        return $this === self::FinancialVolume;
    }
}
