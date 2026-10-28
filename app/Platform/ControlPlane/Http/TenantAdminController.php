<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Http;

use App\Platform\ControlPlane\ControlPlanePermission as P;
use App\Platform\Registry\InvalidStatusTransition;
use App\Platform\Registry\TenantDirectory;
use App\Platform\Registry\TenantNotFound;
use App\Platform\Registry\TenantRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TenantAdminController
{
    public function __construct(private readonly TenantDirectory $directory, private readonly TenantRegistry $registry)
    {
    }

    public function index(Request $request): Response|JsonResponse
    {
        if (! $request->attributes->get('operator')->hasPermission(P::TENANTS_VIEW)) {
            return $this->forbidden($request);
        }
        $page = $this->directory->search($request->query('q') ? (string) $request->query('q') : null, $request->query('status') ? (string) $request->query('status') : null);

        return $request->expectsJson() ? response()->json($page) : Inertia::render('ControlPlane/Tenants', ['tenants' => $page, 'filters' => $request->only('q', 'status')]);
    }

    public function show(Request $request, string $tenant): Response|JsonResponse
    {
        $operator = $request->attributes->get('operator');
        if (! $operator->hasPermission(P::TENANTS_VIEW)) {
            return $this->forbidden($request);
        }
        $detail = $this->directory->detail($tenant);
        if ($detail === null) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }
        $detail['can'] = ['suspend' => $operator->hasPermission(P::TENANTS_SUSPEND), 'reinstate' => $operator->hasPermission(P::TENANTS_REINSTATE)];

        return $request->expectsJson() ? response()->json($detail) : Inertia::render('ControlPlane/TenantDetail', $detail);
    }

    public function suspend(Request $request, string $tenant): JsonResponse|RedirectResponse
    {
        return $this->change($request, $tenant, 'suspended', P::TENANTS_SUSPEND);
    }

    public function reinstate(Request $request, string $tenant): JsonResponse|RedirectResponse
    {
        return $this->change($request, $tenant, 'active', P::TENANTS_REINSTATE);
    }

    private function change(Request $request, string $tenant, string $to, string $permission): JsonResponse|RedirectResponse
    {
        $operator = $request->attributes->get('operator');
        if (! $operator->hasPermission($permission)) {
            return $this->forbidden($request);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:500', 'regex:/\S/']]);

        try {
            $t = $this->registry->changeStatus($tenant, $to, $data['reason'], $operator->id);
        } catch (TenantNotFound) {
            return response()->json(['message' => 'Tenant not found'], 404);
        } catch (InvalidStatusTransition $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return $request->expectsJson()
            ? response()->json(['tenant_id' => $t->id, 'status' => $t->status])
            : back()->with('status', $to === 'suspended' ? 'Tenant suspended.' : 'Tenant reinstated.');
    }

    private function forbidden(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }
}
