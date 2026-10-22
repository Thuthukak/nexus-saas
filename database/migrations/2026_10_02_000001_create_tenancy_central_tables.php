<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('subdomain')->unique();
            $table->string('status')->default('active')->index(); // active|suspended|cancelled
            $table->string('country_code', 2);
            $table->string('billing_currency', 3);                 // ZAR when country_code = ZA, else USD
            $table->string('business_type')->nullable();
            // Defaults to the shared connection; lets a tenant be routed elsewhere later.
            $table->string('database_connection')->default('pgsql');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->string('verification_token');
            $table->string('cname_target');
            $table->timestamp('verified_at')->nullable(); // only non-null may resolve
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
        Schema::dropIfExists('tenants');
    }
};
