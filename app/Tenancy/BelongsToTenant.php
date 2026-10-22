<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;

/**
 * Application-level tenant filter. Fails closed: with no tenant in context,
 * a query throws instead of returning everything. This is NOT the security
 * boundary; PostgreSQL RLS is. It does not protect raw SQL, DB::table(),
 * third-party models, or withoutGlobalScopes().
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', new class implements Scope {
            public function apply(Builder $builder, Model $model): void
            {
                $builder->where($model->qualifyColumn('tenant_id'), app(TenantContext::class)->requireId());
            }
        });

        static::creating(function (Model $model): void {
            if (empty($model->tenant_id)) {
                $model->tenant_id = app(TenantContext::class)->requireId();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
