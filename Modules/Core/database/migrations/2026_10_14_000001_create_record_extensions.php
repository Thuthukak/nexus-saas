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
        // One row per (record, module): the row IS the module's namespace. Core's own tables are never altered.
        Schema::create('record_extensions', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('core_entity', 60);
            $t->string('core_entity_id', 64);
            $t->string('module_key', 40);
            $t->jsonb('metadata');
            $t->timestamps();

            $t->primary(['tenant_id', 'core_entity', 'core_entity_id', 'module_key']);
        });

        // Metadata must be a JSON object (a namespace), never a bare scalar or list.
        DB::statement("ALTER TABLE record_extensions ADD CONSTRAINT record_extensions_object_check CHECK (jsonb_typeof(metadata) = 'object')");

        Rls::enable('record_extensions');
    }

    public function down(): void
    {
        Rls::disable('record_extensions');
        Schema::dropIfExists('record_extensions');
    }
};
