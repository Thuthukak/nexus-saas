<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\CrossModuleEvent;
use App\Kernel\Dto;

/** Core's notifications (and the tenant admin's upgrade prompt) react to this. */
final class UsageThresholdReached implements CrossModuleEvent
{
    public function __construct(public readonly UsageThresholdReachedDTO $dto)
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
