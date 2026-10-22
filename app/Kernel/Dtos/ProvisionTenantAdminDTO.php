<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;

final readonly class ProvisionTenantAdminDTO extends Dto
{
    public function __construct(
        public string $tenantId,
        public string $name,
        public string $email,    // already trimmed and lower-cased
        #[\SensitiveParameter] public string $password, // plain text, hashed by the user model; never logged
    ) {
    }
}
