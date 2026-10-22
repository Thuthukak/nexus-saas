<?php

declare(strict_types=1);

namespace Modules\Core\Email\Delivery;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/** The platform's own verified sending domain (the application's configured mailer). */
final class SharedDelivery implements EmailDelivery
{
    public function deliver(Email $email): void
    {
        Mail::mailer()->getSymfonyTransport()->send($email);
    }
}
