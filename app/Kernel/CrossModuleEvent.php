<?php

declare(strict_types=1);

namespace App\Kernel;

/**
 * Marker interface for events dispatched through Core's event bus.
 * Payload must be a Dto, never an array or model.
 */
interface CrossModuleEvent
{
    public function tenantId(): string;

    public function payload(): Dto;
}
