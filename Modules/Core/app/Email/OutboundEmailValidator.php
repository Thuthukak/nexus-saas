<?php

declare(strict_types=1);

namespace Modules\Core\Email;

use App\Kernel\Dtos\OutboundEmailDTO;
use InvalidArgumentException;

/** Pure checks that run before anything is queued. Blocks header injection (CR/LF) in every header-bound field. */
final class OutboundEmailValidator
{
    public static function validate(OutboundEmailDTO $e): void
    {
        if (filter_var($e->toAddress, FILTER_VALIDATE_EMAIL) === false || strlen($e->toAddress) > 254 || preg_match('/[\r\n]/', $e->toAddress)) {
            throw new InvalidArgumentException('Recipient address is not valid.');
        }
        if ($e->subject === '' || mb_strlen($e->subject) > 200 || preg_match('/[\r\n\x00]/', $e->subject)) {
            throw new InvalidArgumentException('Subject must be 1 to 200 characters on a single line.');
        }
        if ($e->toName !== null && (mb_strlen($e->toName) > 100 || preg_match('/[\r\n\x00]/', $e->toName))) {
            throw new InvalidArgumentException('Recipient name is not valid.');
        }
        if (trim($e->textBody) === '' || strlen($e->textBody) > 200_000 || ($e->htmlBody !== null && strlen($e->htmlBody) > 500_000)) {
            throw new InvalidArgumentException('Message body is empty or too large.');
        }
        if (($e->replyRecordType === null) !== ($e->replyRecordId === null)) {
            throw new InvalidArgumentException('Provide both reply record type and id, or neither.');
        }
        if ($e->replyRecordType !== null && ! ReplyTokenSigner::isValidRef($e->replyRecordType, $e->replyRecordId)) {
            throw new InvalidArgumentException('Reply record reference is not valid.');
        }
    }
}
