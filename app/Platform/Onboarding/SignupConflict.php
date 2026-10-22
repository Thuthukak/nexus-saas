<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

use RuntimeException;

/** Subdomain taken or reserved, or the complete admin email is already registered: HTTP 409. */
final class SignupConflict extends RuntimeException
{
    public const MESSAGE = 'A tenant with this subdomain or email address already exists';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
