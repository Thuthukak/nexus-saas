<?php

declare(strict_types=1);

namespace App\Platform\Modules;

use RuntimeException;

/** Business-rule failure from ModuleManager. $status maps to the HTTP semantics. */
final class ModuleException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function coreAlwaysEnabled(): self
    {
        return new self('The Core module is mandatory and cannot be disabled.', 'core_mandatory', 409);
    }

    public static function notEntitled(string $module): self
    {
        return new self("{$module} is not included in your plan or purchased as an add-on.", 'not_entitled', 403);
    }

    /** @param list<string> $missing */
    public static function missingPrerequisites(string $module, array $missing): self
    {
        return new self("Enable {$module}'s prerequisites first: ".implode(', ', $missing).'.', 'missing_prerequisites', 409);
    }

    /** @param list<string> $dependents */
    public static function hasEnabledDependents(string $module, array $dependents): self
    {
        return new self("Disable {$module}'s dependents first: ".implode(', ', $dependents).'.', 'has_dependents', 409);
    }

    public static function strandsTenants(string $module, string $newPrerequisite, int $tenants): self
    {
        return new self("Cannot make {$module} require {$newPrerequisite}: {$tenants} tenant(s) have {$module} enabled without it.", 'strands_tenants', 409);
    }
}
