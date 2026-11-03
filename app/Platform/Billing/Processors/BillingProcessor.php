<?php

declare(strict_types=1);

namespace App\Platform\Billing\Processors;

use App\Platform\Billing\BillingEvent;
use App\Platform\Billing\SubscriptionSyncRequest;

interface BillingProcessor
{
    public function key(): string;

    /** Does this request really come from the processor? Pure of side effects. Never throws; false on anything doubtful. */
    public function verify(string $payload, array $headers): bool;

    /** Normalise a VERIFIED payload. Null means "an event type we do not act on". Throws InvalidArgumentException when malformed. */
    public function parse(string $payload): ?BillingEvent;

    /** Create or update the tenant's subscription at the processor. Returns the processor reference to store. */
    public function syncSubscription(SubscriptionSyncRequest $request): string;
}
