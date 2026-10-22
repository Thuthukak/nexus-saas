<?php

declare(strict_types=1);

namespace App\Kernel\Contracts;

/**
 * A module claims a record type for inbound replies (Support claims 'ticket'). Replies for a type are only
 * routed while the owning module is enabled for the tenant.
 */
interface InboundRecordTypeRegistrar
{
    /** @throws \InvalidArgumentException unknown module, invalid type (2-6 lowercase letters) or type owned by another module */
    public function register(string $moduleKey, string $recordType): void;
}
