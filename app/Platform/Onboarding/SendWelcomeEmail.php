<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

use App\Platform\Onboarding\Mail\WelcomeMail;
use Illuminate\Support\Facades\Mail;

final class SendWelcomeEmail
{
    public function handle(TenantProvisioned $event): void
    {
        $t = $event->dto;

        Mail::to($t->adminEmail)->send(new WelcomeMail($t->companyName, $t->adminName, $t->workspaceUrl));
    }
}
