<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;

final readonly class RecordMetadataUpdatedDTO extends Dto
{
    public function __construct(
        public string $tenantId,
        public string $coreEntity,
        public string $coreEntityId,
        public string $moduleKey,
    ) {
    }
}
