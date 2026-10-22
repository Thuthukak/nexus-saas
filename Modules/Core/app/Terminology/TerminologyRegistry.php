<?php

declare(strict_types=1);

namespace Modules\Core\Terminology;

/** Generic (internal) entity names that can be given a display term. Modules register theirs in boot(). */
final class TerminologyRegistry
{
    /** @var array<string, array{singular: string, plural: string}> */
    private array $terms = [];

    public function register(string $generic, ?string $plural = null): void
    {
        $this->terms[$generic] = ['singular' => $generic, 'plural' => $plural ?? TerminologyKey::pluralise($generic)];
    }

    public function has(string $generic): bool
    {
        return isset($this->terms[$generic]);
    }

    /** @return array{singular: string, plural: string} */
    public function generic(string $generic): array
    {
        return $this->terms[$generic] ?? throw new \InvalidArgumentException("Unknown generic term [{$generic}].");
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->terms);
    }
}
