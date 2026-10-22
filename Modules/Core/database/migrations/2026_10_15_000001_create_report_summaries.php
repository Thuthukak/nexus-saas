<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CoreReportingPipeline's pre-aggregated, tenant-scoped, indexed summary. Core owns it; each module's
| source fills it on a schedule. Cross-module reports read ONLY this table: never a module's live
| tables and never a join across module-owned tables.
|
| The primary key order (tenant, metric, module, day, label, currency) IS the index a report uses:
| one tenant + one metric + a module + a day range is a single contiguous index range scan.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_summaries', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('metric_key', 60);
            $t->string('module_key', 40);
            $t->date('day');                          // UTC day
            $t->string('label', 60)->default('');
            $t->char('currency', 3)->default('');     // '' for counts (keeps the key NOT NULL)
            $t->bigInteger('value');                  // count, or minor units when currency is set
            $t->timestamp('refreshed_at');

            $t->primary(['tenant_id', 'metric_key', 'module_key', 'day', 'label', 'currency']);
        });

        // Money carries a currency and counts never do: the two are never mixed in one row.
        DB::statement("ALTER TABLE report_summaries ADD CONSTRAINT report_summaries_unit_check CHECK (currency = '' OR currency ~ '^[A-Z]{3}$')");

        Schema::create('report_refresh_state', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('module_key', 40);
            $t->string('metric_key', 60);
            $t->date('refreshed_through')->nullable();
            $t->timestamp('refreshed_at')->nullable();
            $t->string('last_error', 300)->nullable();
            $t->timestamp('last_error_at')->nullable();

            $t->primary(['tenant_id', 'module_key', 'metric_key']);
        });

        Rls::enable('report_summaries');
        Rls::enable('report_refresh_state');
    }

    public function down(): void
    {
        Rls::disable('report_refresh_state');
        Rls::disable('report_summaries');
        Schema::dropIfExists('report_refresh_state');
        Schema::dropIfExists('report_summaries');
    }
};
