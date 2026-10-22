<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The table keeps its generic, industry-neutral name for ever: "Party". Whether a tenant sees
| Customer, Patient or Student is purely a display concern (CoreTerminology); nothing here is ever
| renamed or migrated for that.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_parties', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('email', 254);                    // stored lowercase
            $t->string('phone', 30)->nullable();
            $t->string('billing_address', 500)->nullable();
            $t->string('tax_number', 50)->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'email']);          // one party per email per tenant
            $t->unique(['tenant_id', 'id']);             // target for other modules' composite FKs (tenant_id, customer_id)
            $t->index(['tenant_id', 'name']);
        });

        Rls::enable('core_parties');
    }

    public function down(): void
    {
        Rls::disable('core_parties');
        Schema::dropIfExists('core_parties');
    }
};
