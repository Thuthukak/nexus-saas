<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Central table: not under RLS. */
class TenantDomain extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'verified_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Only verified, active domains may resolve a tenant or receive a certificate. */
    public function scopeResolvable(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at')->where('is_active', true);
    }
}
