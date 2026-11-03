<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The operator's own invoice to a tenant for a paid billing cycle. Not the Financial module's invoices. */
final class InvoiceService
{
    public function issuePaid(object $subscription, string $planKey, int $amountMinor, ?string $invoiceRef, ?\DateTimeInterface $start, ?\DateTimeInterface $end): ?string
    {
        if ($invoiceRef !== null && DB::table('platform_invoices')->where('processor', $subscription->processor)->where('processor_invoice_id', $invoiceRef)->exists()) {
            return null;   // the processor already told us about this invoice
        }
        $seq = (int) DB::selectOne("select nextval('platform_invoice_number_seq') as n")->n;
        $id = (string) Str::uuid();

        DB::table('platform_invoices')->insert([
            'id' => $id, 'tenant_id' => $subscription->tenant_id, 'subscription_id' => $subscription->id,
            'number' => sprintf('INV-%s-%06d', now()->format('Y'), $seq), 'plan_key' => $planKey,
            'billing_cycle' => $subscription->billing_cycle, 'currency' => $subscription->currency, 'amount_minor' => $amountMinor,
            'period_start' => $start, 'period_end' => $end, 'status' => 'paid',
            'processor' => $subscription->processor, 'processor_invoice_id' => $invoiceRef, 'issued_at' => now(),
        ]);

        return $id;
    }
}
