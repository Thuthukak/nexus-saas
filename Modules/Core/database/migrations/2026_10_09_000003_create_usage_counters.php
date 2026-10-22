<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| One row per tenant x metric x calendar month (UTC). Updated with atomic upserts.
| Earlier months are kept for reporting; a new month simply starts a new row.
| user_seats is not stored here: it is counted live from active workspace users.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('metric', 32);
            $t->string('period', 7);                       // YYYY-MM
            $t->unsignedBigInteger('amount')->default(0);  // tickets, or minor units for volume
            $t->timestamp('notified_80_at')->nullable();
            $t->timestamp('notified_100_at')->nullable();
            $t->timestamps();

            $t->primary(['tenant_id', 'metric', 'period']);
        });
        Rls::enable('usage_counters');
    }

    public function down(): void
    {
        Rls::disable('usage_counters');
        Schema::dropIfExists('usage_counters');
    }
};
