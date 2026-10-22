<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Plans, entitlements and subscriptions are DATA (rule 27). These are central
| platform tables: not under RLS (the control plane and ModuleManager read them
| across tenants), and every tenant-keyed table starts its index with tenant_id.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();          // starter | professional | enterprise
            $t->string('name');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('plan_modules', function (Blueprint $t) {
            $t->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $t->string('module_key');
            $t->primary(['plan_id', 'module_key']);
        });

        // One row per plan x currency. NULL = unlimited.
        Schema::create('plan_limits', function (Blueprint $t) {
            $t->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $t->string('currency', 3);
            $t->unsignedInteger('seats')->nullable();
            $t->unsignedInteger('tickets_per_month')->nullable();
            $t->unsignedBigInteger('volume_per_month_minor')->nullable(); // processed volume, minor units
            $t->boolean('custom_domain_allowed')->default(false);
            $t->primary(['plan_id', 'currency']);
        });

        Schema::create('plan_prices', function (Blueprint $t) {
            $t->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $t->string('currency', 3);
            $t->string('cycle', 10);                                  // monthly | annual
            $t->unsignedInteger('monthly_equivalent_minor');
            $t->unsignedInteger('charge_minor');                      // charged per cycle
            $t->boolean('is_starting_from')->default(false);          // Enterprise: custom quotes above
            $t->primary(['plan_id', 'currency', 'cycle']);
        });

        Schema::create('module_dependencies', function (Blueprint $t) {
            $t->string('module_key');
            $t->string('requires_key');
            $t->primary(['module_key', 'requires_key']);
        });

        Schema::create('tenant_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignId('plan_id')->constrained('plans');
            $t->string('billing_cycle', 10);
            $t->string('currency', 3);
            $t->string('status', 20)->default('active');   // active | past_due | cancelled
            $t->timestamp('current_period_start')->nullable();
            $t->timestamp('current_period_end')->nullable();
            $t->string('processor_reference')->nullable();  // Stripe/Chargebee id, filled by the Billing Engine
            $t->timestamps();

            $t->index(['tenant_id', 'status']);
        });

        Schema::create('tenant_modules', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('module_key');
            $t->boolean('is_enabled')->default(false);
            $t->timestamp('first_enabled_at')->nullable();
            $t->timestamp('enabled_at')->nullable();
            $t->timestamp('disabled_at')->nullable();
            $t->timestamps();

            $t->primary(['tenant_id', 'module_key']);
            $t->index(['tenant_id', 'is_enabled']);
        });

        Schema::create('tenant_addons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('module_key');
            $t->boolean('is_active')->default(true);
            $t->string('currency', 3)->nullable();
            $t->unsignedInteger('price_minor')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'module_key']);
            $t->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        foreach (['tenant_addons', 'tenant_modules', 'tenant_subscriptions', 'module_dependencies',
                  'plan_prices', 'plan_limits', 'plan_modules', 'plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
