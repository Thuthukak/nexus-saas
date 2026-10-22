<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;
use InvalidArgumentException;

/**
 * What Financial and Support dispatch to Core. Quantity and money are never mixed:
 *  - ofQuantity(): counts (tickets, seats), a plain integer in its natural unit.
 *  - ofMoney():    an amount in the MINOR unit of an explicit currency (cents for ZAR/USD).
 * Negative values are rejected (refund handling is deferred).
 */
final readonly class UsageRecordedDTO extends Dto
{
    private function __construct(
        public string $tenantId,
        public UsageMetric $metric,
        public int $quantity,
        public int $amountMinor,
        public ?string $currency,
    ) {
    }

    public static function ofQuantity(string $tenantId, UsageMetric $metric, int $quantity): self
    {
        if ($metric->isMoney()) {
            throw new InvalidArgumentException("{$metric->value} is money: use UsageRecordedDTO::ofMoney().");
        }
        if ($quantity < 0) {
            throw new InvalidArgumentException('Usage quantity cannot be negative.');
        }

        return new self($tenantId, $metric, $quantity, 0, null);
    }

    public static function ofMoney(string $tenantId, UsageMetric $metric, int $amountMinor, string $currency): self
    {
        if (! $metric->isMoney()) {
            throw new InvalidArgumentException("{$metric->value} is a quantity: use UsageRecordedDTO::ofQuantity().");
        }
        if ($amountMinor < 0) {
            throw new InvalidArgumentException('Usage amount cannot be negative.');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Currency must be a 3-letter uppercase ISO code.');
        }

        return new self($tenantId, $metric, 0, $amountMinor, $currency);
    }
}
