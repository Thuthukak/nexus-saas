<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;

final readonly class TenantStatusChangedDTO extends Dto
{
    public function __construct(
        public string $tenantId,
        public string $fromStatus,   // active | suspended | cancelled
        public string $toStatus,
        public string $reason,
    ) {
    }
}
