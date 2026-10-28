<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\CrossModuleEvent;
use App\Kernel\Dto;

/** Dispatched by the control plane after a tenant's status changed and the change is committed. */
final class TenantStatusChanged implements CrossModuleEvent
{
    public function __construct(public readonly TenantStatusChangedDTO $dto)
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
