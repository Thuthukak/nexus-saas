<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;

/** Emitted whenever a tenant setting changes. The value travels as a JSON string (immutable, typed). */
final readonly class SettingsUpdatedDTO extends Dto
{
    public function __construct(
        public string $tenantId,
        public string $scope,      // 'tenant' or a module key
        public string $key,
        public string $valueJson,
    ) {
    }
}
