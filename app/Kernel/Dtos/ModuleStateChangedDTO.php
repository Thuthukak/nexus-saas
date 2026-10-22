<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;

final readonly class ModuleStateChangedDTO extends Dto
{
    public function __construct(
        public string $tenantId,
        public string $moduleKey,
        public bool $enabled,
        public bool $firstTimeEnabled,
    ) {
    }
}
