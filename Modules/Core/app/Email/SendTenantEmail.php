<?php

declare(strict_types=1);

namespace Modules\Core\Email;

use App\Kernel\Dtos\OutboundEmailDTO;
use App\Tenancy\Jobs\SetTenantForJob;
use App\Tenancy\TenantRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class SendTenantEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly OutboundEmailDTO $email)
    {
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetTenantForJob($this->email->tenantId)];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 300];
    }

    public function handle(EmailDeliveryService $delivery): void
    {
        $delivery->deliver($this->email);
    }

    /**
     * Called after the final attempt. A tenant-provider failure is recorded where the tenant admin can see it.
     * We do NOT silently re-route the message through the platform domain: the tenant chose where its mail goes.
     */
    public function failed(Throwable $e): void
    {
        report($e);
        app(TenantRunner::class)->run($this->email->tenantId, fn () => app(EmailIntegrationService::class)->recordDeliveryFailure($e->getMessage()));
    }
}
