<?php

declare(strict_types=1);

namespace Modules\Core\Reporting;

use RuntimeException;

final class ReportTooLarge extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Report too large: narrow the date range');
    }
}
