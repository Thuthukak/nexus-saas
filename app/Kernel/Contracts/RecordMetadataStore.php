<?php

declare(strict_types=1);

namespace App\Kernel\Contracts;

interface RecordMetadataStore
{
    /** @throws \InvalidArgumentException unknown module, or a module not enabled for the ambient tenant */
    public function forModule(string $moduleKey): ModuleRecordMetadata;
}
