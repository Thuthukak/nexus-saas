<?php

declare(strict_types=1);

namespace Modules\Core\Email;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;

class SmtpTransportFactory
{
    public function __construct(private readonly SmtpHostGuard $guard)
    {
    }

    /** Connects to the GUARD-VALIDATED IP address, verifying the certificate against the configured host name. */
    public function make(SmtpSettings $s): EsmtpTransport
    {
        $ip = $this->guard->resolve($s->host, $s->port);
        $transport = new EsmtpTransport($ip, $s->port, $s->port === 465);
        $transport->setUsername($s->username);
        $transport->setPassword($s->password);
        if (method_exists($transport, 'setRequireTls')) {
            $transport->setRequireTls(true);   // credentials are never sent in clear text
        }
        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setStreamOptions(['ssl' => ['peer_name' => $s->host, 'verify_peer' => true, 'verify_peer_name' => true]]);
            $stream->setTimeout(10);
        }

        return $transport;
    }

    /** Sends one message through the tenant's relay and always closes the connection. */
    public function send(SmtpSettings $s, Email $email): void
    {
        $transport = $this->make($s);
        try {
            $transport->send($email);
        } finally {
            $transport->stop();
        }
    }
}
