<?php

declare(strict_types=1);

namespace Modules\Core\Inbound;

use App\Kernel\Contracts\InboundRecordTypeRegistrar;
use App\Platform\Modules\ModuleKey;
use InvalidArgumentException;

final class InboundRecordTypeRegistry implements InboundRecordTypeRegistrar
{
    /** @var array<string, string> recordType => moduleKey */
    private array $types = [];

    public function register(string $moduleKey, string $recordType): void
    {
        if (ModuleKey::tryFrom($moduleKey) === null) {
            throw new InvalidArgumentException("Unknown module [{$moduleKey}].");
        }
        if (preg_match('/^[a-z]{2,6}$/', $recordType) !== 1) {
            throw new InvalidArgumentException("Record type [{$recordType}] must be 2 to 6 lowercase letters.");
        }
        if (isset($this->types[$recordType]) && $this->types[$recordType] !== $moduleKey) {
            throw new InvalidArgumentException("Record type [{$recordType}] is already owned by {$this->types[$recordType]}.");
        }
        $this->types[$recordType] = $moduleKey;
    }

    public function moduleFor(string $recordType): ?string
    {
        return $this->types[$recordType] ?? null;
    }
}
