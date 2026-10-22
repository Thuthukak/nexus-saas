<?php

declare(strict_types=1);

namespace Modules\Core\Permissions;

use Illuminate\Support\Facades\DB;

/**
 * The ONLY way to touch the Super Admin role: run control-plane code (platform operator
 * actions) inside run(). It sets a transaction-local flag the database triggers check.
 *
 * Tenant-level code (signup, tenant admin UI, controllers under TenantMiddleware) must
 * never call this. If the callback throws, the transaction (or savepoint) rolls back and
 * Postgres reverts the flag automatically.
 */
final class OperatorAuthority
{
    public function run(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            DB::select("select set_config('app.operator_authority', 'on', true)");

            $result = $callback();

            DB::select("select set_config('app.operator_authority', '', true)");

            return $result;
        });
    }
}
