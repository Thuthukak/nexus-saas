<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Control plane (central tables, no RLS: operators are platform staff, never tenant users).
|   operators             platform staff who sign in on admin.<domain> only
|   operator_permissions  what each may do, e.g. control_plane.tenants.suspend
|   tenant_status_events  append-only audit trail of every status change (who, why, when)
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('email', 254);
            $t->string('password');
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_login_at')->nullable();
            $t->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX operators_email_lower_unique ON operators (lower(email))');

        Schema::create('operator_permissions', function (Blueprint $t) {
            $t->foreignId('operator_id')->constrained('operators')->cascadeOnDelete();
            $t->string('permission', 80);
            $t->primary(['operator_id', 'permission']);
        });
        DB::statement("ALTER TABLE operator_permissions ADD CONSTRAINT operator_permissions_format_check CHECK (permission ~ '^control_plane\\.(\\*|[a-z_]+\\.(\\*|[a-z_]+))$')");

        Schema::create('tenant_status_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('from_status', 10);
            $t->string('to_status', 10);
            $t->string('reason', 500);
            $t->string('actor', 10);                                              // operator | system (billing engine)
            $t->foreignId('operator_id')->nullable()->constrained('operators')->nullOnDelete();
            $t->timestamp('created_at');
            $t->index(['tenant_id', 'created_at']);
        });
        $statuses = "('active','suspended','cancelled')";
        DB::statement("ALTER TABLE tenant_status_events ADD CONSTRAINT tenant_status_events_status_check CHECK (from_status IN {$statuses} AND to_status IN {$statuses} AND from_status <> to_status)");
        DB::statement("ALTER TABLE tenant_status_events ADD CONSTRAINT tenant_status_events_actor_check CHECK (actor IN ('operator','system'))");

        // The registry's status column can only ever hold the three known states.
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN {$statuses})");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_status_check');
        Schema::dropIfExists('tenant_status_events');
        Schema::dropIfExists('operator_permissions');
        Schema::dropIfExists('operators');
    }
};
