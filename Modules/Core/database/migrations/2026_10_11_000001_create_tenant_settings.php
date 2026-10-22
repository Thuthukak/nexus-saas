<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('scope', 40);        // 'tenant' (global to the tenant) or a module key
            $t->string('key', 100);
            $t->jsonb('value');             // JSON-typed: string, number, bool, array or null
            $t->timestamps();

            $t->primary(['tenant_id', 'scope', 'key']);
        });

        Rls::enable('tenant_settings');
    }

    public function down(): void
    {
        Rls::disable('tenant_settings');
        Schema::dropIfExists('tenant_settings');
    }
};
