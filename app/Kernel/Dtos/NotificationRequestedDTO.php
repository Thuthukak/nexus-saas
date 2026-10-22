<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;

/** What any module dispatches to ask Core to notify one user. */
final readonly class NotificationRequestedDTO extends Dto
{
    public function __construct(
        public string $tenantId,
        public int $userId,
        public string $type,        // a registered notification type, e.g. 'support.ticket_assigned'
        public string $title,
        public ?string $body = null,
        public ?string $dataJson = null,  // small JSON object (ids, links), never secrets
    ) {
    }
}
