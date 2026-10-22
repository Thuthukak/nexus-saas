<?php

declare(strict_types=1);

namespace App\Kernel\Contracts;

use App\Kernel\Dtos\OutboundEmailDTO;

/** The only way a module sends tenant email. Queued; delivery uses the tenant's configured provider. */
interface EmailSender
{
    /** @throws \InvalidArgumentException invalid address, subject or body (nothing is queued) */
    public function send(OutboundEmailDTO $email): void;
}
