<?php

declare(strict_types=1);

namespace Modules\Core\Records;

use App\Kernel\Contracts\ModuleRecordMetadata;

/** The capability handed to a module: bound to one module key, no way to name another. */
final class ModuleRecordMetadataHandle implements ModuleRecordMetadata
{
    public function __construct(private readonly RecordMetadataService $service, private readonly string $moduleKey)
    {
    }

    public function read(string $coreEntity, string $coreEntityId): ?array
    {
        return $this->service->readNamespace($this->moduleKey, $coreEntity, $coreEntityId);
    }

    public function write(string $coreEntity, string $coreEntityId, array $metadata): void
    {
        $this->service->writeNamespace($this->moduleKey, $coreEntity, $coreEntityId, $metadata);
    }
}
