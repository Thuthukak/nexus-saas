<?php

declare(strict_types=1);

namespace Modules\Core\Email;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per tenant, only once the tenant chooses something other than the default. No row means
 * platform_shared (zero setup). `credentials` is encrypted with the application key and never serialised.
 */
final class EmailSettings extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_email_settings';
    protected $primaryKey = 'tenant_id';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];
    protected $hidden = ['credentials', 'consent_nonce_hash'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'consent_expires_at' => 'datetime', 'activated_at' => 'datetime', 'last_error_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
