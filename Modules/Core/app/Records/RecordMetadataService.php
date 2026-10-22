<?php

declare(strict_types=1);

namespace Modules\Core\Records;

use App\Kernel\Contracts\ModuleRecordMetadata;
use App\Kernel\Contracts\RecordMetadataStore;
use App\Kernel\Dtos\RecordMetadataUpdated;
use App\Kernel\Dtos\RecordMetadataUpdatedDTO;
use App\Platform\Modules\ModuleKey;
use App\Platform\Modules\ModuleManager;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CoreRecordExtensions. A module's metadata lives in its own namespace (one row per record+module)
 * and is reachable only through a handle bound to that module. Disabling a module hides its
 * namespace but never deletes it (data is preserved for re-enabling).
 */
final class RecordMetadataService implements RecordMetadataStore
{
    public function __construct(
        private readonly OpenEntityRegistry $entities,
        private readonly ModuleManager $modules,
        private readonly TenantContext $context,
    ) {
    }

    public function forModule(string $moduleKey): ModuleRecordMetadata
    {
        $this->assertUsable($moduleKey);

        return new ModuleRecordMetadataHandle($this, $moduleKey);
    }

    /** @return array<string, mixed>|null */
    public function readNamespace(string $moduleKey, string $entity, string $id): ?array
    {
        $this->assertUsable($moduleKey);
        $this->assertRecord($entity, $id);

        $json = DB::table('record_extensions')
            ->where('tenant_id', $this->context->requireId())   // explicit tenant filter on top of RLS
            ->where('core_entity', $entity)->where('core_entity_id', $id)->where('module_key', $moduleKey)
            ->value('metadata');

        return $json === null ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $metadata */
    public function writeNamespace(string $moduleKey, string $entity, string $id, array $metadata): void
    {
        $this->assertUsable($moduleKey);
        $this->assertRecord($entity, $id);
        $metadata = MetadataValidator::normalise($metadata);
        $tenantId = $this->context->requireId();

        DB::statement(
            'INSERT INTO record_extensions (tenant_id, core_entity, core_entity_id, module_key, metadata, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?::jsonb, ?, ?)
             ON CONFLICT (tenant_id, core_entity, core_entity_id, module_key)
             DO UPDATE SET metadata = excluded.metadata, updated_at = excluded.updated_at',
            [$tenantId, $entity, $id, $moduleKey, json_encode($metadata === [] ? new \stdClass() : $metadata, JSON_THROW_ON_ERROR), now(), now()],
        );

        event(new RecordMetadataUpdated(new RecordMetadataUpdatedDTO($tenantId, $entity, $id, $moduleKey)));
    }

    /**
     * Core's own merged view of a record: every ENABLED module's namespace, keyed by module.
     * (Core renders this itself; modules never get other modules' data through it.)
     *
     * @return array<string, array<string, mixed>>
     */
    public function mergedFor(string $entity, string $id): array
    {
        $this->assertRecord($entity, $id);
        $active = $this->modules->activeModules($this->context->requireId());

        return DB::table('record_extensions')
            ->where('tenant_id', $this->context->requireId())
            ->where('core_entity', $entity)->where('core_entity_id', $id)
            ->whereIn('module_key', $active)
            ->orderBy('module_key')
            ->pluck('metadata', 'module_key')
            ->map(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR))
            ->all();
    }

    public function isModuleUsable(string $moduleKey): bool
    {
        return ModuleKey::tryFrom($moduleKey) !== null
            && in_array($moduleKey, $this->modules->activeModules($this->context->requireId()), true);
    }

    private function assertUsable(string $moduleKey): void
    {
        if (ModuleKey::tryFrom($moduleKey) === null) {
            throw new InvalidArgumentException("Unknown module [{$moduleKey}].");
        }
        if (! $this->isModuleUsable($moduleKey)) {
            throw new InvalidArgumentException("Module [{$moduleKey}] is not enabled for this tenant.");
        }
    }

    private function assertRecord(string $entity, string $id): void
    {
        if (! $this->entities->exists($entity, $id)) {
            throw new RecordNotFound('Core record not found');
        }
    }
}
