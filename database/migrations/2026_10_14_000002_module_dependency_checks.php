<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A module can never require itself (the cycle check also catches this; the database says it too).
        DB::statement('ALTER TABLE module_dependencies ADD CONSTRAINT module_dependencies_not_self CHECK (module_key <> requires_key)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE module_dependencies DROP CONSTRAINT IF EXISTS module_dependencies_not_self');
    }
};
