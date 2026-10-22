<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->unsignedBigInteger('user_id');
            $t->string('type', 80);
            $t->string('title', 200);
            $t->text('body')->nullable();
            $t->jsonb('data')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at')->useCurrent();

            // A notification can only point at a user OF THE SAME TENANT (composite FK).
            $t->index(['tenant_id', 'user_id', 'read_at', 'created_at']);
        });

        // users has a primary key on id only; add the unique pair the composite FK needs.
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_tenant_id_id_unique UNIQUE (tenant_id, id)');
        DB::statement('ALTER TABLE core_notifications ADD CONSTRAINT core_notifications_user_same_tenant
            FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id) ON DELETE CASCADE');

        Schema::create('core_notification_preferences', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->unsignedBigInteger('user_id');
            $t->string('type', 80);
            $t->boolean('in_app')->default(true);
            $t->boolean('email')->default(false);
            $t->timestamps();

            $t->primary(['tenant_id', 'user_id', 'type']);
        });
        DB::statement('ALTER TABLE core_notification_preferences ADD CONSTRAINT core_notification_prefs_user_same_tenant
            FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id) ON DELETE CASCADE');

        Rls::enable('core_notifications');
        Rls::enable('core_notification_preferences');
    }

    public function down(): void
    {
        Rls::disable('core_notification_preferences');
        Rls::disable('core_notifications');
        Schema::dropIfExists('core_notification_preferences');
        Schema::dropIfExists('core_notifications');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_tenant_id_id_unique');
    }
};
