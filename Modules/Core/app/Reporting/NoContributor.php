<?php

declare(strict_types=1);

namespace Modules\Core\Reporting;

use RuntimeException;

final class NoContributor extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No enabled module contributes the requested metric_key');
    }
}
