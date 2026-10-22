<?php

declare(strict_types=1);

namespace Modules\Core\Notifications;

/** Modules register their notification types with default channels; unknown types are rejected. */
final class NotificationTypeRegistry
{
    /** @var array<string, array{in_app: bool, email: bool}> */
    private array $types = [];

    public function register(string $type, bool $inApp = true, bool $email = false): void
    {
        $this->types[$type] = ['in_app' => $inApp, 'email' => $email];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /** @return array{in_app: bool, email: bool} */
    public function defaults(string $type): array
    {
        return $this->types[$type] ?? throw new \InvalidArgumentException("Unknown notification type [{$type}].");
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->types);
    }
}
