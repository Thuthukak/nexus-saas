<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Replaces the single `amount` column of usage_counters (migration 000003) with separate
| columns, so a quantity is never stored in the same place as money:
|
|   quantity     counts in their natural unit (tickets, seats). Never money.
|   amount_minor money in the MINOR unit of `currency`. Scale: 2 decimal places for ZAR and
|                USD (cents). If a currency with another exponent is ever billed, the scale
|                must come from a per-currency table, not be assumed.
|   currency     ISO 4217 code; NULL for quantity metrics.
|
| Dev-stage only: the table has no production data, so it is dropped and recreated.
| Do not copy this pattern once real counters exist.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('usage_counters');

        Schema::create('usage_counters', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('metric', 32);
            $t->string('period', 7);                           // YYYY-MM (UTC)
            $t->unsignedBigInteger('quantity')->default(0);
            $t->unsignedBigInteger('amount_minor')->default(0);
            $t->char('currency', 3)->nullable();
            $t->timestamp('notified_80_at')->nullable();
            $t->timestamp('notified_100_at')->nullable();
            $t->timestamps();

            $t->primary(['tenant_id', 'metric', 'period']);
        });

        // A row is either money (currency set, no quantity) or a quantity (no currency, no money).
        DB::statement('ALTER TABLE usage_counters ADD CONSTRAINT usage_counters_unit_check CHECK (
            (currency IS NULL AND amount_minor = 0) OR (currency IS NOT NULL AND quantity = 0)
        )');

        Rls::enable('usage_counters');
    }

    public function down(): void
    {
        Rls::disable('usage_counters');
        Schema::dropIfExists('usage_counters');
    }
};
