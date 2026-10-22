<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;

/**
 * One outbound email a module wants a tenant to send. Core decides HOW it is delivered
 * (platform shared domain, the tenant's Microsoft 365, or the tenant's own SMTP relay).
 */
final readonly class OutboundEmailDTO extends Dto
{
    public function __construct(
        public string $tenantId,
        public string $toAddress,
        public string $subject,
        public string $textBody,
        public ?string $htmlBody = null,
        public ?string $toName = null,
        public ?int $senderUserId = null,        // the workspace user this is sent on behalf of
        public ?string $replyRecordType = null,  // e.g. 'ticket' (2-6 lowercase letters): ask for a threaded, signed Reply-To
        public ?string $replyRecordId = null,    // e.g. '4821' (1-7 lowercase letters/digits)
    ) {
    }
}
