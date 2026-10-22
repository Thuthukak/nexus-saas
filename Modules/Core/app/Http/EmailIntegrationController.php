<?php

declare(strict_types=1);

namespace Modules\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Email\EmailConfigurationInvalid;
use Modules\Core\Email\EmailIntegrationService;
use Modules\Core\Email\EmailPlanPolicy;
use Modules\Core\Email\EmailProvider;
use Modules\Core\Email\EmailProviderNotPermitted;
use Modules\Core\Email\MicrosoftGraphClient;
use App\Tenancy\TenantContext;

final class EmailIntegrationController
{
    public function __construct(private readonly EmailIntegrationService $email)
    {
    }

    public function show(Request $request, EmailPlanPolicy $plans, MicrosoftGraphClient $graph, TenantContext $context): Response
    {
        abort_unless($request->user('workspace')->can('core.settings.manage'), 403);

        return Inertia::render('Core/EmailIntegration', [
            'settings' => $this->email->payload(),
            'canUseTenantProviders' => $plans->allows($context->requireId(), EmailProvider::CustomSmtp),
            'microsoftAvailable' => $graph->configured(),
            'consent' => in_array($request->query('consent'), ['ok', 'denied', 'failed'], true) ? $request->query('consent') : null,
            'replyDomain' => config('tenancy.reply_domain'),
        ]);
    }

    /** ConfigureEmailIntegration: 200 EmailIntegrationPayload | 403 | 422. */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user('workspace');
        if (! $user->can('core.settings.manage')) {
            return response()->json(['message' => 'Missing permission or plan is not Enterprise'], 403);
        }

        try {
            return response()->json($this->email->configure((int) $user->getAuthIdentifier(), $request->only([
                'email_provider', 'from_address', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
            ])));
        } catch (EmailProviderNotPermitted $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (EmailConfigurationInvalid $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors], 422);
        }
    }
}
