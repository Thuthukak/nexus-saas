<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CoreEmailIntegration. One row per tenant, created only when a tenant leaves the default (platform_shared, no row).
| `credentials` holds the Laravel-encrypted SMTP credential set or Microsoft Graph token (text, never jsonb).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_email_settings', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
            $t->string('provider', 20)->default('platform_shared');
            $t->string('from_address', 254)->nullable();
            $t->string('status', 10)->default('active');
            $t->text('credentials')->nullable();
            $t->char('consent_nonce_hash', 64)->nullable();     // single-use Microsoft consent state
            $t->timestamp('consent_expires_at')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->string('last_error', 300)->nullable();
            $t->timestamp('last_error_at')->nullable();
            $t->timestamps();
        });

        DB::statement("ALTER TABLE tenant_email_settings ADD CONSTRAINT tenant_email_settings_provider_check CHECK (provider IN ('platform_shared','microsoft365','custom_smtp'))");
        DB::statement("ALTER TABLE tenant_email_settings ADD CONSTRAINT tenant_email_settings_status_check CHECK (status IN ('active','pending','failed'))");
        // A tenant-owned provider always has a sender address; an ACTIVE one always has credentials.
        DB::statement("ALTER TABLE tenant_email_settings ADD CONSTRAINT tenant_email_settings_shape_check CHECK (provider = 'platform_shared' OR from_address IS NOT NULL)");
        DB::statement("ALTER TABLE tenant_email_settings ADD CONSTRAINT tenant_email_settings_active_check CHECK (provider = 'platform_shared' OR status <> 'active' OR credentials IS NOT NULL)");

        Rls::enable('tenant_email_settings');
    }

    public function down(): void
    {
        Rls::disable('tenant_email_settings');
        Schema::dropIfExists('tenant_email_settings');
    }
};
