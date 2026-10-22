<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/** Internal workspace user (the `workspace` guard). Consumes a seat while active. */
class User extends Authenticatable
{
    use BelongsToTenant, HasRoles, Notifiable;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = [
        'is_active' => true,
    ];

    public function guardName(): string
    {
        return 'workspace';
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
