<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Platform\Billing\Processors\BillingProcessor;
use App\Platform\Billing\Processors\ChargebeeProcessor;
use App\Platform\Billing\Processors\PaystackProcessor;
use App\Platform\Billing\Processors\StripeProcessor;

/** Builds adapters from config/billing.php. Only the three known keys ever resolve. */
final class ProcessorRegistry
{
    public const KEYS = ['paystack', 'stripe', 'chargebee'];

    /** @var array<string, BillingProcessor> */
    private array $override = [];

    public function get(string $key): ?BillingProcessor
    {
        if (isset($this->override[$key])) {
            return $this->override[$key];
        }
        $c = (array) config("billing.processors.{$key}", []);

        return match ($key) {
            'stripe' => new StripeProcessor((string) ($c['webhook_secret'] ?? ''), (string) ($c['api_key'] ?? ''), (int) ($c['tolerance_seconds'] ?? 300)),
            'paystack' => new PaystackProcessor((string) ($c['webhook_secret'] ?? ''), (array) ($c['plan_codes'] ?? [])),
            'chargebee' => new ChargebeeProcessor((string) ($c['webhook_username'] ?? ''), (string) ($c['webhook_password'] ?? ''), (string) ($c['site'] ?? ''), (string) ($c['api_key'] ?? '')),
            default => null,
        };
    }

    /** Which processor bills a tenant in this currency, or null for manual billing. */
    public function keyForCurrency(string $currency): ?string
    {
        $k = config('billing.processor_for_currency.'.strtoupper($currency));

        return is_string($k) && in_array($k, self::KEYS, true) ? $k : null;
    }

    public function fake(string $key, BillingProcessor $p): void
    {
        $this->override[$key] = $p;
    }
}
