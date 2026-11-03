<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Operator billing of tenants (central tables, no RLS; entirely separate from the Financial module).
|   tenant_subscriptions  + processor, past_due_since, custom_price_minor (Enterprise quotes above the starting price)
|   billing_events        one row per processed webhook event: makes retries idempotent, records the outcome
|   platform_invoices     the operator's own invoice to a tenant for each paid billing cycle
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_subscriptions', function (Blueprint $t) {
            $t->string('processor', 20)->nullable();
            $t->timestamp('past_due_since')->nullable();
            $t->unsignedBigInteger('custom_price_minor')->nullable();
        });
        DB::statement("ALTER TABLE tenant_subscriptions ADD CONSTRAINT tenant_subscriptions_status_check CHECK (status IN ('active','past_due','cancelled'))");
        DB::statement("ALTER TABLE tenant_subscriptions ADD CONSTRAINT tenant_subscriptions_processor_check CHECK (processor IS NULL OR processor IN ('paystack','stripe','chargebee'))");
        DB::statement('CREATE UNIQUE INDEX tenant_subscriptions_processor_ref_unique ON tenant_subscriptions (processor, processor_reference) WHERE processor_reference IS NOT NULL');

        Schema::create('billing_events', function (Blueprint $t) {
            $t->id();
            $t->string('processor', 20);
            $t->string('event_id', 120);
            $t->string('event_type', 40);
            $t->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $t->string('outcome', 30);        // applied | ignored | rejected_seats | rejected_plan
            $t->timestamp('created_at');
            $t->unique(['processor', 'event_id']);
        });

        DB::statement('CREATE SEQUENCE platform_invoice_number_seq START 1');
        Schema::create('platform_invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUuid('subscription_id')->constrained('tenant_subscriptions')->cascadeOnDelete();
            $t->string('number', 24)->unique();
            $t->string('plan_key', 40);
            $t->string('billing_cycle', 10);
            $t->string('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->timestamp('period_start')->nullable();
            $t->timestamp('period_end')->nullable();
            $t->string('status', 10)->default('paid');
            $t->string('processor', 20)->nullable();
            $t->string('processor_invoice_id', 120)->nullable();
            $t->timestamp('issued_at');
            $t->index(['tenant_id', 'issued_at']);
        });
        DB::statement('CREATE UNIQUE INDEX platform_invoices_processor_invoice_unique ON platform_invoices (processor, processor_invoice_id) WHERE processor_invoice_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoices');
        DB::statement('DROP SEQUENCE IF EXISTS platform_invoice_number_seq');
        Schema::dropIfExists('billing_events');
        DB::statement('DROP INDEX IF EXISTS tenant_subscriptions_processor_ref_unique');
        DB::statement('ALTER TABLE tenant_subscriptions DROP CONSTRAINT IF EXISTS tenant_subscriptions_processor_check');
        DB::statement('ALTER TABLE tenant_subscriptions DROP CONSTRAINT IF EXISTS tenant_subscriptions_status_check');
        Schema::table('tenant_subscriptions', fn (Blueprint $t) => $t->dropColumn(['processor', 'past_due_since', 'custom_price_minor']));
    }
};
