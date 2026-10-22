<?php

declare(strict_types=1);

namespace Modules\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Core\Records\MetadataValidator;
use Modules\Core\Records\OpenEntityRegistry;
use Modules\Core\Records\RecordMetadataService;
use Modules\Core\Records\RecordNotFound;

/**
 * UpdateRecordMetadata. The calling module identifies itself with the X-Module-Key header (set by
 * the module's own frontend client) and MUST match module_key: a module only ever touches its own
 * namespace. Checks run in an order that leaks nothing to the unauthorised.
 */
final class RecordMetadataController
{
    public function __construct(
        private readonly RecordMetadataService $records,
        private readonly OpenEntityRegistry $entities,
    ) {
    }

    /** 200 RecordMetadataPayload | 403 | 404 | 422 */
    public function write(Request $request, string $entity, string $id): JsonResponse
    {
        $moduleKey = (string) $request->input('module_key', '');

        if ($request->header('X-Module-Key') !== $moduleKey || $moduleKey === '') {
            return response()->json(['message' => "This module may not write to another module's metadata namespace"], 403);
        }
        if (! $this->records->isModuleUsable($moduleKey)) {
            return response()->json(['message' => 'This module is not enabled for this workspace'], 403);
        }
        if (! $this->entities->isOpen($entity)) {
            return response()->json(['message' => 'Core record not found'], 404);
        }
        if (! $request->user('workspace')->can($this->entities->writePermission($entity))) {
            return response()->json(['message' => 'Missing permission to extend this record'], 403);
        }

        try {
            $metadata = MetadataValidator::normalise($request->input('metadata'));
            $this->records->forModule($moduleKey)->write($entity, $id, $metadata);
        } catch (RecordNotFound) {
            return response()->json(['message' => 'Core record not found'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => ['metadata' => [$e->getMessage()]]], 422);
        }

        return response()->json($this->payload($entity, $id, $moduleKey, $metadata));
    }

    /** Reads the calling module's OWN namespace only. */
    public function read(Request $request, string $entity, string $id): JsonResponse
    {
        $moduleKey = (string) $request->query('module_key', '');

        if ($request->header('X-Module-Key') !== $moduleKey || $moduleKey === '') {
            return response()->json(['message' => "This module may not read another module's metadata namespace"], 403);
        }
        if (! $this->records->isModuleUsable($moduleKey)) {
            return response()->json(['message' => 'This module is not enabled for this workspace'], 403);
        }
        if (! $this->entities->isOpen($entity)) {
            return response()->json(['message' => 'Core record not found'], 404);
        }
        $read = $this->entities->readPermission($entity);
        if ($read !== null && ! $request->user('workspace')->can($read)) {
            return response()->json(['message' => 'Missing permission to view this record'], 403);
        }

        try {
            $metadata = $this->records->forModule($moduleKey)->read($entity, $id);
        } catch (RecordNotFound) {
            return response()->json(['message' => 'Core record not found'], 404);
        }

        return response()->json($this->payload($entity, $id, $moduleKey, $metadata));
    }

    /** @param array<string, mixed>|null $metadata */
    private function payload(string $entity, string $id, string $moduleKey, ?array $metadata): array
    {
        return ['core_entity' => $entity, 'core_entity_id' => $id, 'module_key' => $moduleKey, 'metadata' => $metadata];
    }
}
