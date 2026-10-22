<?php

declare(strict_types=1);

namespace Modules\Core\Email\Delivery;

use Modules\Core\Email\SmtpSettings;
use Modules\Core\Email\SmtpTransportFactory;
use Symfony\Component\Mime\Email;

/** The tenant's own relay. The host is re-checked on every send; the connection goes to the validated IP. */
final class SmtpDelivery implements EmailDelivery
{
    public function __construct(private readonly SmtpTransportFactory $factory, private readonly SmtpSettings $settings)
    {
    }

    public function deliver(Email $email): void
    {
        $this->factory->send($this->settings, $email);
    }
}
