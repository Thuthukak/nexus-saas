<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use App\Kernel\Contracts\ProvisionsTenantAdmin;
use App\Kernel\Dtos\ProvisionTenantAdminDTO;
use App\Kernel\Dtos\TenantAdminProvisionedDTO;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Usage\SeatLimiter;

/**
 * Core's side of signup: seeds the tenant's roles, then creates the admin as seat 1 with the
 * Tenant Admin role. Runs inside the control plane's provisioning transaction (savepoint), so
 * any failure here rolls back the whole signup.
 */
final class TenantAdminProvisioner implements ProvisionsTenantAdmin
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CoreTenantSeeder $seeder,
        private readonly SeatLimiter $seats,
    ) {
    }

    public function provision(ProvisionTenantAdminDTO $dto): TenantAdminProvisionedDTO
    {
        return DB::transaction(function () use ($dto) {
            $this->context->set($dto->tenantId); // RLS now accepts this tenant's inserts

            // Claim the complete email address first: a duplicate fails here (unique index),
            // before anything else is created.
            DB::table('platform_emails')->insert([
                'email_normalised' => $dto->email,
                'tenant_id' => $dto->tenantId,
                'user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->seeder->seed($dto->tenantId); // Super Admin + Tenant Admin roles

            // The seat limiter locks the tenant row and requires an active subscription; the
            // admin counts as seat 1 (a plan with a limit of 1 still works).
            $user = $this->seats->withSeat($dto->tenantId, fn () => User::create([
                'name' => $dto->name,
                'email' => $dto->email,
                'password' => $dto->password,
            ]));

            DB::table('platform_emails')
                ->where('email_normalised', $dto->email)
                ->update(['user_id' => $user->id, 'updated_at' => now()]);

            $user->assignRole('Tenant Admin'); // never Super Admin: the database refuses it at tenant level

            return new TenantAdminProvisionedDTO($user->id, $user->email);
        });
    }
}
