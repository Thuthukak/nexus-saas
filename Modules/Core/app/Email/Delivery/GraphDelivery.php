<?php

declare(strict_types=1);

namespace Modules\Core\Email\Delivery;

use Modules\Core\Email\EmailSettings;
use Modules\Core\Email\MicrosoftGraphClient;
use Symfony\Component\Mime\Email;

final class GraphDelivery implements EmailDelivery
{
    public function __construct(private readonly MicrosoftGraphClient $graph, private readonly EmailSettings $settings)
    {
    }

    public function deliver(Email $email): void
    {
        $this->graph->sendMail($this->graph->accessToken($this->settings), $email);
    }
}
