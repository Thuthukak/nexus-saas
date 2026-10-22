<?php

declare(strict_types=1);

namespace Modules\Core\Permissions;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CorePermissions: tenant-scoped roles, platform-wide permission keys.
 * Everything runs in the current tenant (TenantContext + RLS); roles are tenant-keyed.
 */
final class PermissionManager
{
    private const GUARD = 'workspace';

    public function upsertRole(string $name): Role
    {
        $name = trim($name);
        $this->assertNotSuperAdmin($name);

        return Role::findOrCreate($name, self::GUARD);
    }

    public function attach(string $roleName, string $permissionKey): void
    {
        $this->assertNotSuperAdmin($roleName);
        $this->assertValidKey($permissionKey);

        $role = Role::findByName($roleName, self::GUARD);
        $role->givePermissionTo(Permission::findOrCreate($permissionKey, self::GUARD));
        $this->refresh();
    }

    public function detach(string $roleName, string $permissionKey): void
    {
        $this->assertNotSuperAdmin($roleName);
        $this->assertValidKey($permissionKey);

        Role::findByName($roleName, self::GUARD)->revokePermissionTo($permissionKey);
        $this->refresh();
    }

    public function deleteRole(string $roleName): void
    {
        $this->assertNotSuperAdmin($roleName);

        Role::findByName($roleName, self::GUARD)->delete();
        $this->refresh();
    }

    /** Creates the protected Super Admin role (wildcard) for the current tenant. Idempotent. */
    public function ensureSuperAdmin(): Role
    {
        $role = Role::findOrCreate(PermissionKey::SUPER_ADMIN_ROLE, self::GUARD);
        $role->givePermissionTo(Permission::findOrCreate(PermissionKey::WILDCARD, self::GUARD));
        $this->refresh();

        return $role;
    }

    /** Spatie caches permissions: drop the cache so every user holding the role sees the change. */
    public function refresh(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function assertNotSuperAdmin(string $roleName): void
    {
        if (trim($roleName) === PermissionKey::SUPER_ADMIN_ROLE) {
            throw PermissionException::superAdminProtected();
        }
    }

    private function assertValidKey(string $key): void
    {
        if (! PermissionKey::isValid($key)) {
            throw PermissionException::invalidKey($key);
        }
    }
}
