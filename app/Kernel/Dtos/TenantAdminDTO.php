<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;

final readonly class TenantAdminDTO extends Dto
{
    public function __construct(public int $userId, public string $name, public string $email)
    {
    }
}
