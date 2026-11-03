<?php

declare(strict_types=1);

namespace App\Platform\Subscriptions;

final readonly class PlanChangeCheck
{
    /** @param list<string> $modulesToDisable modules that will be switched off (their data is kept) */
    public function __construct(public bool $ok, public ?string $reason = null, public array $modulesToDisable = [], public int $usersToDeactivate = 0)
    {
    }
}
