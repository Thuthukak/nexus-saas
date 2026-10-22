<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\CrossModuleEvent;
use App\Kernel\Dto;

/**
 * Dispatched when a module is enabled or disabled for a tenant. Modules react
 * (seed data, register or deregister widgets and extension points) only if they
 * are the module concerned; the dispatcher never calls them directly.
 */
final class ModuleStateChanged implements CrossModuleEvent
{
    public function __construct(public readonly ModuleStateChangedDTO $dto)
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
