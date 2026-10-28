<?php

declare(strict_types=1);

namespace App\Platform\Registry;

use RuntimeException;

final class TenantNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Tenant not found');
    }
}
