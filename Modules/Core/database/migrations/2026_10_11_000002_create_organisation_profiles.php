<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // UNIQUE: only one organisation profile may exist per tenant.
            $t->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('email', 254);
            $t->string('phone', 30)->nullable();
            $t->string('address', 500)->nullable();
            $t->string('tax_registration_number', 50)->nullable();
            $t->string('logo_path')->nullable();   // relative to the private disk; always under tenants/{tenant_id}/
            $t->string('logo_mime', 40)->nullable();
            $t->timestamps();
        });

        Rls::enable('organisation_profiles');
    }

    public function down(): void
    {
        Rls::disable('organisation_profiles');
        Schema::dropIfExists('organisation_profiles');
    }
};
