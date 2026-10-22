<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Inbound\ImapMailbox;
use Modules\Core\Inbound\ImapMessageMapper;
use Modules\Core\Inbound\InboundEmailRouter;
use Modules\Core\Inbound\RawImapMessage;
use Modules\Core\Inbound\RouteOutcome;
use Throwable;

/**
 * email:poll-inbound — the launch entry point. A thin adapter: it builds InboundEmailDTOs and hands them to the router.
 * Runs outside any tenant and reads no tenant data; the router sets the tenant context after the signature verifies.
 */
final class PollInboundMailboxCommand extends Command
{
    protected $signature = 'email:poll-inbound {--limit=50 : Most messages per run}';

    protected $description = 'Read the inbound reply mailbox and route each message';

    public function handle(ImapMailbox $mailbox, InboundEmailRouter $router): int
    {
        $mapper = new ImapMessageMapper((string) config('tenancy.reply_domain'), 'imap');
        $processed = (string) config('inbound_mail.imap.processed_folder');
        $rejected = (string) config('inbound_mail.imap.rejected_folder');
        $tally = ['delivered' => 0, 'duplicate' => 0, 'rejected' => 0, 'failed' => 0];

        try {
            foreach ($mailbox->fetchUnseen(max(1, (int) $this->option('limit'))) as $raw) {
                try {
                    $outcome = $this->handleOne($raw, $mapper, $router);
                    $tally[$outcome->status]++;
                    $mailbox->move($raw, $outcome->isRejected() ? $rejected : $processed);
                } catch (Throwable $e) {
                    // Handling failed (for example a module's listener threw): the claim was rolled back, so leave the message
                    // in the inbox to be retried, but park it after too many attempts so one bad message cannot loop forever.
                    $tally['failed']++;
                    report($e);
                    $key = 'inbound-attempts:'.hash('sha256', $raw->uid.'|'.$raw->headers);
                    $attempts = Cache::add($key, 1, 86400) ? 1 : (int) Cache::increment($key);
                    if ($attempts >= (int) config('inbound_mail.max_attempts', 5)) {
                        $mailbox->move($raw, $rejected);
                    }
                }
            }
        } finally {
            $mailbox->disconnect();
        }

        $this->info(sprintf('delivered %d, duplicate %d, rejected %d, failed %d', ...array_values($tally)));

        return $tally['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function handleOne(RawImapMessage $raw, ImapMessageMapper $mapper, InboundEmailRouter $router): RouteOutcome
    {
        if ($raw->size > (int) config('inbound_mail.max_message_bytes')) {
            return RouteOutcome::rejected('too_large');
        }
        $dto = $mapper->map($raw);

        return $dto === null ? RouteOutcome::rejected('unparseable') : $router->route($dto);
    }
}
