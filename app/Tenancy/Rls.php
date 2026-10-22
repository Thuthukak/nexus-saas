<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Applies the fail-closed Row-Level Security policy to a tenant-owned table.
 * Call from a migration right after Schema::create().
 */
final class Rls
{
    public static function enable(string $table): void
    {
        $var = config('tenancy.rls_variable', 'app.current_tenant_id');
        $expr = "tenant_id = nullif(current_setting('{$var}', true), '')::uuid";

        DB::statement("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE \"{$table}\" FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON \"{$table}\"");
        DB::statement("CREATE POLICY tenant_isolation ON \"{$table}\" USING ({$expr}) WITH CHECK ({$expr})");
    }

    public static function disable(string $table): void
    {
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON \"{$table}\"");
        DB::statement("ALTER TABLE \"{$table}\" NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE \"{$table}\" DISABLE ROW LEVEL SECURITY");
    }
}
