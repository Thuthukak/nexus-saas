<?php

declare(strict_types=1);

namespace Modules\Core\Permissions;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Authorisation for role management at tenant level. Super Admin is never editable,
 * deletable or assignable here, not even by a Super Admin user: those actions belong to
 * the platform operator (OperatorAuthority) only. Database triggers back this up.
 */
final class RolePolicy
{
    public function update(Model $user, Role $role): bool
    {
        return $this->canManage($user, $role);
    }

    public function delete(Model $user, Role $role): bool
    {
        return $this->canManage($user, $role);
    }

    public function assign(Model $user, Role $role): bool
    {
        return $this->canManage($user, $role);
    }

    private function canManage(Model $user, Role $role): bool
    {
        if ($role->name === PermissionKey::SUPER_ADMIN_ROLE) {
            return false;
        }

        return method_exists($user, 'can') && $user->can('core.roles.manage');
    }
}
