<?php

declare(strict_types=1);

namespace Modules\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Organisation\OrganisationProfile;
use Modules\Core\Organisation\OrganisationProfileService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class OrganisationController
{
    public function __construct(private readonly OrganisationProfileService $profiles)
    {
    }

    public function show(Request $request): Response
    {
        abort_unless($request->user('workspace')->can('core.settings.manage'), 403);

        return Inertia::render('Core/Organisation', ['profile' => self::payload($this->profiles->current())]);
    }

    /** UpdateOrganisationProfile: 200 OrganisationProfilePayload | 403 | 422. */
    public function update(Request $request): JsonResponse
    {
        if (! $request->user('workspace')->can('core.settings.manage')) {
            return response()->json(['message' => 'Missing permission to manage the organisation profile'], 403);
        }

        $validator = Validator::make($request->all(), [
            'organisation_name' => ['required', 'string', 'max:150'],
            'organisation_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'organisation_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s.]{5,30}$/'],
            'organisation_address' => ['nullable', 'string', 'max:500'],
            'tax_registration_number' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-\/\s.]+$/'],
            'logo' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:'.OrganisationProfileService::LOGO_MAX_KB,
                'dimensions:max_width=2000,max_height=2000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $v = $validator->validated();
        $profile = $this->profiles->save([
            'name' => trim($v['organisation_name']),
            'email' => mb_strtolower(trim($v['organisation_email'])),
            'phone' => $v['organisation_phone'] ?? null,
            'address' => $v['organisation_address'] ?? null,
            'tax_registration_number' => $v['tax_registration_number'] ?? null,
        ], $request->file('logo'));

        return response()->json(self::payload($profile));
    }

    /** Streams this tenant's logo (private disk; any signed-in user of the tenant). */
    public function logo(): BinaryFileResponse
    {
        $logo = $this->profiles->logoStream();
        abort_if($logo === null, 404);

        return response()->file($logo['path'], [
            'Content-Type' => $logo['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** @return array<string, mixed>|null */
    public static function payload(?OrganisationProfile $p): ?array
    {
        return $p === null ? null : [
            'organisation_name' => $p->name,
            'organisation_email' => $p->email,
            'organisation_address' => $p->address,
            'organisation_phone' => $p->phone,
            'tax_registration_number' => $p->tax_registration_number,
            'logo_url' => $p->logo_path ? '/core/organisation/logo?v='.$p->updated_at?->getTimestamp() : null,
        ];
    }
}
