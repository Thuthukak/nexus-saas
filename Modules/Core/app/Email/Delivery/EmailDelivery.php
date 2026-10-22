<?php

declare(strict_types=1);

namespace Modules\Core\Email\Delivery;

use Symfony\Component\Mime\Email;

interface EmailDelivery
{
    public function deliver(Email $email): void;
}
