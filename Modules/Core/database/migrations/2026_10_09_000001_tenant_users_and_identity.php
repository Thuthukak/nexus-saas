<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Workspace users reuse Laravel's default `users` table (and its `sessions` table),
| converted into a tenant-owned table under RLS.
|
| Uniqueness rule (spec rule 28): one registration per COMPLETE email address across the
| whole platform, never per domain. RLS hides other tenants' users, so the cross-tenant
| check lives in a small central directory, `platform_emails`, with a unique index.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->exists()) {
            throw new RuntimeException('users is not empty: clear it (dev data only) before converting it to a tenant table.');
        }

        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique('users_email_unique');
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_login_at')->nullable();

            $t->unique(['tenant_id', 'email']);
            $t->index(['tenant_id', 'is_active']);
        });
        Rls::enable('users');

        // Central directory: one row per registered workspace-user email, platform-wide.
        Schema::create('platform_emails', function (Blueprint $t) {
            $t->string('email_normalised')->primary();         // lower(trim(email)), the unique key
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();

            $t->index('tenant_id');
        });

        // Customer portal guard. Never consumes a seat (rule 23). party_id points at Core's
        // customer directory, added with that feature (FK is added in its migration).
        Schema::create('portal_users', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->uuid('party_id')->nullable();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestamps();

            $t->unique(['tenant_id', 'email']);
            $t->index(['tenant_id', 'is_active']);
            $t->index(['tenant_id', 'party_id']);
        });
        Rls::enable('portal_users');
    }

    public function down(): void
    {
        Rls::disable('portal_users');
        Schema::dropIfExists('portal_users');
        Schema::dropIfExists('platform_emails');

        Rls::disable('users');
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'email']);
            $t->dropIndex(['tenant_id', 'is_active']);
            $t->dropConstrainedForeignId('tenant_id');
            $t->dropColumn(['is_active', 'last_login_at']);
            $t->unique('email');
        });
    }
};
