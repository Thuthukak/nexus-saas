<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Customer portal user (the `portal` guard). Never consumes a seat. */
class PortalUser extends Authenticatable
{
    use BelongsToTenant;

    protected $table = 'portal_users';

    protected $fillable = ['name', 'email', 'password', 'is_active', 'party_id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
