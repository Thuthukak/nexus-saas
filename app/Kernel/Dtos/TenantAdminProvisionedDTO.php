<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;

final readonly class TenantAdminProvisionedDTO extends Dto
{
    public function __construct(
        public int $userId,
        public string $email,
    ) {
    }
}
