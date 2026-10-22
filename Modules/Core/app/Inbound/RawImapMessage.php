<?php

declare(strict_types=1);

namespace Modules\Core\Inbound;

/** What an IMAP library hands us for one message, reduced to plain data. `handle` is the library's own object, used only to move the message. */
final class RawImapMessage
{
    public function __construct(
        public readonly string $uid,
        public readonly string $headers,     // the raw header block
        public readonly ?string $text,
        public readonly ?string $html,
        public readonly int $size = 0,
        public readonly mixed $handle = null,
    ) {
    }
}
