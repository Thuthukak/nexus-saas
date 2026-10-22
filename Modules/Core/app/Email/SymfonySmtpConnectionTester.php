<?php

declare(strict_types=1);

namespace Modules\Core\Email;

use InvalidArgumentException;
use Throwable;

final class SymfonySmtpConnectionTester implements SmtpConnectionTester
{
    public function __construct(private readonly SmtpTransportFactory $factory)
    {
    }

    public function test(SmtpSettings $settings): void
    {
        try {
            $transport = $this->factory->make($settings);
        } catch (InvalidArgumentException $e) {
            throw new SmtpConnectionFailed($e->getMessage(), 0, $e);   // guard messages are written for the tenant admin
        }

        try {
            $transport->start();   // connect, STARTTLS, authenticate
        } catch (Throwable $e) {
            $msg = str_replace($settings->password, '***', $e->getMessage());

            throw new SmtpConnectionFailed('Could not connect and sign in to the SMTP server: '.mb_substr(preg_replace('/\s+/', ' ', $msg), 0, 200), 0, $e);
        } finally {
            try {
                $transport->stop();
            } catch (Throwable) {
            }
        }
    }
}
