<?php

declare(strict_types=1);

namespace Modules\Core\Http;

use App\Tenancy\TenantUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Email\EmailIntegrationService;

/** Microsoft Admin Consent redirect target. Platform root domain, no session: trust comes from the signed state only. */
final class MicrosoftConsentController
{
    public function __invoke(Request $request, EmailIntegrationService $email): Response|\Illuminate\Http\RedirectResponse
    {
        $result = $email->completeMicrosoftConsent($request->query());

        if ($result['outcome'] === 'invalid' || $result['subdomain'] === null) {
            return response('This link is invalid or has expired. Start again from your workspace email settings.', 400, ['Referrer-Policy' => 'no-referrer']);
        }

        return redirect()->to(TenantUrl::workspace($result['subdomain'], '/core/email-integration?consent='.$result['outcome']))
            ->header('Referrer-Policy', 'no-referrer');
    }
}
