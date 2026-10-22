<?php

declare(strict_types=1);

namespace App\Kernel\Contracts;

/**
 * Issues a cryptographically signed Reply-To address binding the tenant and ONE record, so an
 * inbound reply can be routed back without trusting any message header.
 */
interface ReplyAddressIssuer
{
    /** @return string e.g. reply+{tenant}.ticket-4821_{signature}@reply.example.com */
    public function issue(string $tenantId, string $recordType, string $recordId): string;
}
