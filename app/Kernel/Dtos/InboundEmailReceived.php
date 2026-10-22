<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\CrossModuleEvent;
use App\Kernel\Dto;

/**
 * Dispatched once per accepted inbound email, with the tenant context already set. The module that
 * registered the record type (Support for 'ticket') listens and appends the message to its thread.
 * The module must still check the record exists and that fromAddress is acceptable for it.
 */
final class InboundEmailReceived implements CrossModuleEvent
{
    public function __construct(public readonly InboundEmailReceivedDTO $dto)
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
