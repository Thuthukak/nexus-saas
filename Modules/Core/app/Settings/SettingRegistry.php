<?php

declare(strict_types=1);

namespace Modules\Core\Settings;

use App\Platform\Modules\ModuleKey;

/**
 * Every setting a module wants to store must be registered here (scope + key + type + default).
 * Unregistered keys are rejected: settings are a contract, not a free-for-all bag.
 * Types: string | int | bool | array.
 */
final class SettingRegistry
{
    public const TENANT_SCOPE = 'tenant';
    private const TYPES = ['string', 'int', 'bool', 'array'];

    /** @var array<string, array{type:string, default:mixed}> keyed "scope\0key" */
    private array $definitions = [];

    public function register(string $scope, string $key, string $type, mixed $default = null): void
    {
        if (! self::isValidScope($scope)) {
            throw new SettingsException("Unknown settings scope [{$scope}].");
        }
        if (! in_array($type, self::TYPES, true)) {
            throw new SettingsException("Unknown setting type [{$type}].");
        }
        if ($default !== null && ! self::matchesType($type, $default)) {
            throw new SettingsException("Default for [{$scope}.{$key}] is not of type {$type}.");
        }

        $this->definitions[$scope."\0".$key] = ['type' => $type, 'default' => $default];
    }

    public function has(string $scope, string $key): bool
    {
        return isset($this->definitions[$scope."\0".$key]);
    }

    /** @return array{type:string, default:mixed} */
    public function definition(string $scope, string $key): array
    {
        return $this->definitions[$scope."\0".$key]
            ?? throw new SettingsException("Setting [{$scope}.{$key}] is not registered.");
    }

    /** @return array<string, mixed> key => default, for one scope */
    public function defaults(string $scope): array
    {
        $out = [];
        foreach ($this->definitions as $id => $def) {
            [$s, $k] = explode("\0", $id, 2);
            if ($s === $scope) {
                $out[$k] = $def['default'];
            }
        }

        return $out;
    }

    public static function isValidScope(string $scope): bool
    {
        return $scope === self::TENANT_SCOPE || ModuleKey::tryFrom($scope) !== null;
    }

    public static function matchesType(string $type, mixed $value): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'int' => is_int($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            default => false,
        };
    }
}
