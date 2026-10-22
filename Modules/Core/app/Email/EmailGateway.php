<?php

declare(strict_types=1);

namespace Modules\Core\Email;

use App\Kernel\Contracts\EmailSender;
use App\Kernel\Dtos\OutboundEmailDTO;

final class EmailGateway implements EmailSender
{
    public function send(OutboundEmailDTO $email): void
    {
        OutboundEmailValidator::validate($email);
        SendTenantEmail::dispatch($email);
    }
}
