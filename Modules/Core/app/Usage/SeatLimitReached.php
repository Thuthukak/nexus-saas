<?php

declare(strict_types=1);

namespace Modules\Core\Usage;

use RuntimeException;

/** Hard limit: another active workspace user cannot be added. Prompt the admin to upgrade. */
final class SeatLimitReached extends RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("Your plan allows {$limit} workspace users. Upgrade your plan or deactivate a user to add another.");
    }
}
