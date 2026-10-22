<?php

declare(strict_types=1);

namespace Modules\Core\Records;

use Closure;
use InvalidArgumentException;

/**
 * The Core record types explicitly OPENED for extension (e.g. customers). Anything not listed
 * here cannot carry module metadata. Each entry says how to check a record exists (always under
 * the ambient tenant) and which permissions guard reading and writing it.
 */
final class OpenEntityRegistry
{
    /** @var array<string, array{owner:string, exists:Closure, write:string, read:?string}> */
    private array $entities = [];

    /**
     * @param Closure(string): bool $exists  tenant-scoped existence check for the record id
     * @param string|null           $readPermission null = any signed-in workspace user may read
     */
    public function open(string $entity, string $ownerModule, Closure $exists, string $writePermission, ?string $readPermission = null): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,59}$/', $entity) !== 1) {
            throw new InvalidArgumentException("Invalid entity name [{$entity}].");
        }
        $this->entities[$entity] = ['owner' => $ownerModule, 'exists' => $exists, 'write' => $writePermission, 'read' => $readPermission];
    }

    public function isOpen(string $entity): bool
    {
        return isset($this->entities[$entity]);
    }

    public function exists(string $entity, string $id): bool
    {
        return $this->isOpen($entity) && (bool) ($this->entities[$entity]['exists'])($id);
    }

    public function writePermission(string $entity): string
    {
        return $this->entities[$entity]['write'] ?? throw new InvalidArgumentException("Entity [{$entity}] is not open for extension.");
    }

    public function readPermission(string $entity): ?string
    {
        return $this->entities[$entity]['read'] ?? null;
    }
}
