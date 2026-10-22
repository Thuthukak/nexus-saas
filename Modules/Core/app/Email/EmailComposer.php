<?php

declare(strict_types=1);

namespace Modules\Core\Email;

use App\Kernel\Dtos\OutboundEmailDTO;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class EmailComposer
{
    /** @param array{address:string, name:?string, reply_to:?string} $identity */
    public static function compose(OutboundEmailDTO $dto, array $identity): Email
    {
        $email = (new Email())
            ->from(new Address($identity['address'], (string) $identity['name']))
            ->to(new Address($dto->toAddress, (string) $dto->toName))
            ->subject($dto->subject)
            ->text($dto->textBody);

        if ($dto->htmlBody !== null) {
            $email->html($dto->htmlBody);
        }
        if ($identity['reply_to'] !== null) {
            $email->replyTo($identity['reply_to']);
        }

        return $email;
    }
}
