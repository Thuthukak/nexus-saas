<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\CrossModuleEvent;
use App\Kernel\Dto;

final class CustomerCreated implements CrossModuleEvent
{
    public function __construct(public readonly CustomerDTO $dto)
    {
    }

    public function tenantId(): string
    {
        return $this->dto->tenantId;
    }

    public function payload(): Dto
    {
        return $this->dto;
    }
}
