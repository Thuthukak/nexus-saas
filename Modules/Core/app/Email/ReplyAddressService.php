<?php

declare(strict_types=1);

namespace Modules\Core\Email;

use App\Kernel\Contracts\ReplyAddressIssuer;

final class ReplyAddressService implements ReplyAddressIssuer
{
    public function __construct(private readonly ReplyTokenSigner $signer)
    {
    }

    public function issue(string $tenantId, string $recordType, string $recordId): string
    {
        return $this->signer->issue($tenantId, $recordType, $recordId).'@'.config('tenancy.reply_domain');
    }
}
