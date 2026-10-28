<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

/** Platform staff. Separate table, guard ('operator') and session key from tenant workspace users. Central: no tenant, no RLS. */
final class Operator extends Authenticatable
{
    protected $table = 'operators';
    protected $fillable = ['name', 'email', 'password', 'is_active'];
    protected $hidden = ['password'];

    /** @var list<string>|null */
    private ?array $held = null;

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'last_login_at' => 'datetime'];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->held ??= DB::table('operator_permissions')->where('operator_id', $this->getKey())->pluck('permission')->all();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->is_active && ControlPlanePermission::grants($this->permissions(), $permission);
    }
}
