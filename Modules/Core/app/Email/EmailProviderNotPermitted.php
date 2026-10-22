<?php

declare(strict_types=1);

namespace Modules\Core\Email;

use RuntimeException;

final class EmailProviderNotPermitted extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Missing permission or plan is not Enterprise');
    }
}
