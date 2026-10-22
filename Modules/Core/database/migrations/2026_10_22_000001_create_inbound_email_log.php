<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CoreInboundEmailRouting. CENTRAL table (no RLS): a message is claimed before any tenant context exists, and refusals
| (forged or unroutable mail) belong to no tenant. It stores NO addresses, subjects or bodies: only a SHA-256 of
| (Message-ID | recipient), the outcome and, for accepted mail, the tenant and record it was routed to.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_email_log', function (Blueprint $t) {
            $t->id();
            $t->char('dedupe_key', 64)->unique();                     // at-most-once, race-safe
            $t->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $t->string('record_type', 6)->nullable();
            $t->string('record_id', 7)->nullable();
            $t->string('status', 10);
            $t->string('reason', 40)->nullable();
            $t->string('source', 20);
            $t->timestamp('created_at');
            $t->index('created_at');                                  // pruning
        });

        DB::statement("ALTER TABLE inbound_email_log ADD CONSTRAINT inbound_email_log_status_check CHECK (status IN ('delivered','rejected'))");
        DB::statement("ALTER TABLE inbound_email_log ADD CONSTRAINT inbound_email_log_shape_check CHECK ((status = 'delivered' AND tenant_id IS NOT NULL AND record_type IS NOT NULL AND record_id IS NOT NULL AND reason IS NULL) OR (status = 'rejected' AND reason IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_email_log');
    }
};
