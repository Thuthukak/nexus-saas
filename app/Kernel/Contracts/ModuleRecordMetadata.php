<?php

declare(strict_types=1);

namespace App\Kernel\Contracts;

/**
 * A capability handle bound to ONE module's metadata namespace. It has no module parameter on
 * purpose: code holding the handle for 'Support' cannot name, read or write another namespace.
 */
interface ModuleRecordMetadata
{
    /** @return array<string, mixed>|null this module's JSON object for the record, null when none */
    public function read(string $coreEntity, string $coreEntityId): ?array;

    /**
     * Replaces this module's whole namespace on the record.
     *
     * @param array<string, mixed> $metadata a JSON object
     * @throws \Modules\Core\Records\RecordNotFound when the entity is not open for extension or the record does not exist in this tenant
     */
    public function write(string $coreEntity, string $coreEntityId, array $metadata): void;
}
