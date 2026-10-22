<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;

/** Letterhead details other modules cache for branded documents. The logo is referenced, not embedded. */
final readonly class OrganisationProfileUpdatedDTO extends Dto
{
    public function __construct(
        public string $tenantId,
        public string $name,
        public string $email,
        public ?string $phone,
        public ?string $address,
        public ?string $taxRegistrationNumber,
        public bool $hasLogo,
        public string $version,   // changes on every update: a cache-busting key for cached copies
    ) {
    }
}
