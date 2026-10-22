<?php

declare(strict_types=1);

namespace Modules\Core\Email;

use RuntimeException;

final class EmailConfigurationInvalid extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed');
    }
}
