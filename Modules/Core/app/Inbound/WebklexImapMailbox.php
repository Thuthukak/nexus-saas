<?php

declare(strict_types=1);

namespace Modules\Core\Inbound;

use RuntimeException;

/**
 * IMAP intake through webklex/php-imap (composer require webklex/php-imap). The ONLY class that touches the library.
 * Headers are taken RAW and parsed by RawHeaderParser, so nothing depends on the library's own header parsing.
 */
final class WebklexImapMailbox implements ImapMailbox
{
    private mixed $client = null;
    private mixed $folder = null;

    public function __construct()
    {
        if (! class_exists(\Webklex\PHPIMAP\ClientManager::class)) {
            throw new RuntimeException('webklex/php-imap is not installed. Run: composer require webklex/php-imap');
        }
    }

    public function fetchUnseen(int $limit): array
    {
        $c = (array) config('inbound_mail.imap');
        if (! filled($c['host'] ?? null) || ! filled($c['username'] ?? null) || ! filled($c['password'] ?? null)) {
            throw new RuntimeException('The inbound IMAP mailbox is not configured (INBOUND_IMAP_*).');
        }
        if (! in_array($c['encryption'], ['ssl', 'starttls'], true)) {
            throw new RuntimeException('INBOUND_IMAP_ENCRYPTION must be ssl or starttls; plain IMAP is refused.');
        }

        // The platform's own authenticated mailbox connection IS the origin check for this entry point: TLS with certificate verification.
        $this->client = (new \Webklex\PHPIMAP\ClientManager())->make([
            'host' => $c['host'], 'port' => $c['port'], 'encryption' => $c['encryption'], 'validate_cert' => true,
            'username' => $c['username'], 'password' => $c['password'], 'protocol' => 'imap', 'authentication' => null,
        ]);
        $this->client->connect();
        $this->folder = $this->client->getFolderByPath($c['inbox']);

        $out = [];
        foreach ($this->folder->query()->unseen()->leaveUnread()->limit($limit)->get() as $m) {
            $header = $m->getHeader();
            $out[] = new RawImapMessage(
                uid: (string) $m->getUid(),
                headers: (string) ($header->raw ?? ''),
                text: $m->hasTextBody() ? (string) $m->getTextBody() : null,
                html: $m->hasHTMLBody() ? (string) $m->getHTMLBody() : null,
                size: (int) ($m->getSize() ?? 0),
                handle: $m,
            );
        }

        return $out;
    }

    public function move(RawImapMessage $message, string $folder): void
    {
        $message->handle->move($folder);
    }

    public function disconnect(): void
    {
        $this->client?->disconnect();
        $this->client = $this->folder = null;
    }
}
