<?php

declare(strict_types=1);

namespace Modules\Core\Inbound;

interface ImapMailbox
{
    /** @return list<RawImapMessage> unseen messages, oldest first, WITHOUT marking them read */
    public function fetchUnseen(int $limit): array;

    /** Moves a handled message (never deletes it) so failures can be inspected. */
    public function move(RawImapMessage $message, string $folder): void;

    public function disconnect(): void;
}
