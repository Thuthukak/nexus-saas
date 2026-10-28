<?php

declare(strict_types=1);

namespace App\Platform\Registry;

use RuntimeException;

final class InvalidStatusTransition extends RuntimeException
{
    public function __construct(public readonly string $from, public readonly string $to)
    {
        parent::__construct("A {$from} tenant cannot become {$to}.");
    }
}
