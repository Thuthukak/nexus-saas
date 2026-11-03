<?php

declare(strict_types=1);

namespace App\Platform\Billing;

/** A processor event normalised to what the engine understands. Contains no secrets and no card data. */
final readonly class BillingEvent
{
    public const RENEWAL_SUCCEEDED = 'renewal_succeeded';
    public const PAYMENT_FAILED = 'payment_failed';
    public const PLAN_CHANGED = 'plan_changed';
    public const CANCELLED = 'cancelled';

    public function __construct(
        public string $processor,
        public string $eventId,
        public string $type,
        public ?string $tenantId = null,       // from metadata we attached when creating the subscription
        public ?string $reference = null,      // the processor's own subscription/customer id
        public ?string $planKey = null,
        public ?string $cycle = null,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $invoiceRef = null,
        public ?\DateTimeImmutable $periodStart = null,
        public ?\DateTimeImmutable $periodEnd = null,
    ) {
    }
}
