<?php

declare(strict_types=1);

namespace Modules\Core\Email;

use App\Kernel\Contracts\ReplyAddressIssuer;
use App\Kernel\Dtos\OutboundEmailDTO;
use App\Tenancy\Tenant;
use Modules\Core\Email\Delivery\EmailDelivery;
use Modules\Core\Email\Delivery\GraphDelivery;
use Modules\Core\Email\Delivery\SharedDelivery;
use Modules\Core\Email\Delivery\SmtpDelivery;
use Modules\Core\Models\User;

/** Runs INSIDE the tenant context (queue job). Picks the provider, builds the message, delivers it. */
final class EmailDeliveryService
{
    public function __construct(
        private readonly EmailIntegrationService $integration,
        private readonly ReplyAddressIssuer $replies,
        private readonly MicrosoftGraphClient $graph,
        private readonly SmtpTransportFactory $smtpFactory,
    ) {
    }

    public function deliver(OutboundEmailDTO $dto): void
    {
        $settings = $this->integration->settings();
        $provider = $this->integration->effectiveProvider($settings);   // not active => platform_shared

        $user = $dto->senderUserId !== null ? User::query()->find($dto->senderUserId) : null;
        $reply = $provider === EmailProvider::PlatformShared && $dto->replyRecordType !== null
            ? $this->replies->issue($dto->tenantId, $dto->replyRecordType, $dto->replyRecordId)
            : null;

        $identity = SenderIdentity::resolve(
            tenantProvider: $provider !== EmailProvider::PlatformShared,
            configuredFrom: $settings?->from_address,
            platformFrom: (string) config('mail.from.address'),
            tenantName: (string) Tenant::query()->whereKey($dto->tenantId)->value('name'),
            userName: $user?->name,
            userEmail: $user?->email,
            replyAddress: $reply,
        );

        $this->strategy($provider, $settings)->deliver(EmailComposer::compose($dto, $identity));
    }

    private function strategy(EmailProvider $provider, ?EmailSettings $s): EmailDelivery
    {
        return match ($provider) {
            EmailProvider::PlatformShared => new SharedDelivery(),
            EmailProvider::Microsoft365 => new GraphDelivery($this->graph, $s),
            EmailProvider::CustomSmtp => new SmtpDelivery($this->smtpFactory, SmtpSettings::fromStored((array) $s->credentials)),
        };
    }
}
