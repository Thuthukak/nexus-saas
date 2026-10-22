<?php

declare(strict_types=1);

namespace Modules\Core\Parties;

use App\Kernel\Dtos\CustomerDTO;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Core's generic Party/Contact record. Shown as Customer / Patient / Student via CoreTerminology. */
final class Party extends Model
{
    use BelongsToTenant;

    protected $table = 'core_parties';

    protected $fillable = ['name', 'email', 'phone', 'billing_address', 'tax_number'];

    public function toDto(): CustomerDTO
    {
        return new CustomerDTO($this->id, $this->tenant_id, $this->name, $this->email, $this->phone, $this->billing_address, $this->tax_number);
    }
}
