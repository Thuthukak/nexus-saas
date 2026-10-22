<?php

declare(strict_types=1);

namespace App\Platform\Onboarding\Http;

use App\Platform\Onboarding\SignupConflict;
use App\Platform\Onboarding\TenantProvisioner;
use Illuminate\Http\JsonResponse;

/** POST /api/tenants: one of the four real JSON routes. Served only from the platform root domain. */
final class CreateTenantController
{
    public function __invoke(CreateTenantRequest $request, TenantProvisioner $provisioner): JsonResponse
    {
        try {
            $tenant = $provisioner->provision($request->toDto());
        } catch (SignupConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        // TenantCreatedPayload. The client must cross to handoff_url with window.location.href
        // (a full navigation), never an Inertia client-side redirect.
        return response()->json([
            'tenant_id' => $tenant->tenantId,
            'subdomain' => $tenant->subdomain,
            'workspace_url' => $tenant->workspaceUrl,
            'handoff_url' => $tenant->handoffUrl,
            'billing_currency' => $tenant->billingCurrency,
            'subscription_plan' => $tenant->subscriptionPlan,
        ], 201);
    }
}
