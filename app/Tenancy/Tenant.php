<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Central table: not under RLS, needed to resolve the tenant. */
class Tenant extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const CANCELLED = 'cancelled';

    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
    ];

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function platformHost(): string
    {
        return $this->subdomain.'.'.config('tenancy.root_domain');
    }
}
