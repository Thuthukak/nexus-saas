<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use App\Kernel\Contracts\TenantAdminDirectory;
use App\Kernel\Dtos\TenantAdminDTO;
use App\Tenancy\TenantRunner;
use Modules\Core\Models\User;

/** Core owns users, so Core answers "who administers this tenant?" for the control plane. */
final class TenantAdminDirectoryService implements TenantAdminDirectory
{
    public function __construct(private readonly TenantRunner $runner)
    {
    }

    public function activeAdmins(string $tenantId): array
    {
        return $this->runner->run($tenantId, fn () => User::role('Tenant Admin')->where('is_active', true)->orderBy('id')->get()
            ->map(fn (User $u) => new TenantAdminDTO($u->id, (string) $u->name, (string) $u->email))->all());
    }
}
