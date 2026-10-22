<?php

declare(strict_types=1);

namespace Modules\Core\Organisation;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class OrganisationProfile extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'organisation_profiles';

    protected $fillable = ['name', 'email', 'phone', 'address', 'tax_registration_number', 'logo_path', 'logo_mime'];
}
