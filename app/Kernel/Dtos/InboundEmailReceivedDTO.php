<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;

/** A VERIFIED inbound email: the tenant and record come from the signed reply address, never from message headers. */
final readonly class InboundEmailReceivedDTO extends Dto
{
    public function __construct(
        public string $tenantId,
        public string $recordType,   // e.g. 'ticket'
        public string $recordId,     // e.g. '4821'
        public InboundEmailDTO $email,
    ) {
    }
}
