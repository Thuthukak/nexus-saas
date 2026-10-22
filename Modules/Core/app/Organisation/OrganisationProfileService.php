<?php

declare(strict_types=1);

namespace Modules\Core\Organisation;

use App\Kernel\Dtos\OrganisationProfileUpdated;
use App\Kernel\Dtos\OrganisationProfileUpdatedDTO;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** CoreOrganisationProfile: one profile per tenant (enforced by a UNIQUE index), logo on a private disk. */
final class OrganisationProfileService
{
    public const LOGO_MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp']; // no SVG: scriptable
    public const LOGO_MAX_KB = 1024;

    public function __construct(private readonly TenantContext $context)
    {
    }

    public function current(): ?OrganisationProfile
    {
        return OrganisationProfile::query()->first();
    }

    /**
     * Creates or updates the tenant's single profile.
     *
     * @param array{name:string,email:string,phone:?string,address:?string,tax_registration_number:?string} $data
     */
    public function save(array $data, ?UploadedFile $logo = null): OrganisationProfile
    {
        $tenantId = $this->context->requireId();
        $oldLogo = null;

        $profile = DB::transaction(function () use ($tenantId, $data, $logo, &$oldLogo) {
            $profile = OrganisationProfile::query()->lockForUpdate()->first() ?? new OrganisationProfile();
            $oldLogo = $profile->logo_path;

            $profile->fill($data);

            if ($logo !== null) {
                $profile->logo_path = $this->storeLogo($tenantId, $logo);
                $profile->logo_mime = $logo->getMimeType();
            }

            try {
                $profile->save();   // a racing second create hits the UNIQUE(tenant_id) index
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                $profile = OrganisationProfile::query()->firstOrFail();
                $profile->fill($data)->save();
            }

            return $profile;
        });

        if ($logo !== null && $oldLogo !== null && $oldLogo !== $profile->logo_path) {
            Storage::disk($this->disk())->delete($oldLogo);   // best effort, after the new row is committed
        }

        event(new OrganisationProfileUpdated(new OrganisationProfileUpdatedDTO(
            $tenantId, $profile->name, $profile->email, $profile->phone, $profile->address,
            $profile->tax_registration_number, $profile->logo_path !== null,
            (string) $profile->updated_at?->getTimestamp(),
        )));

        return $profile;
    }

    /** The logo bytes for THIS tenant only; null when there is none. */
    public function logoStream(): ?array
    {
        $profile = $this->current();
        $tenantId = $this->context->requireId();
        if ($profile === null || $profile->logo_path === null
            || ! str_starts_with($profile->logo_path, "tenants/{$tenantId}/")) {
            return null;
        }
        $disk = Storage::disk($this->disk());

        return $disk->exists($profile->logo_path)
            ? ['path' => $disk->path($profile->logo_path), 'mime' => $profile->logo_mime ?? 'application/octet-stream']
            : null;
    }

    private function storeLogo(string $tenantId, UploadedFile $file): string
    {
        $ext = self::LOGO_MIMES[$file->getMimeType()] ?? throw new \InvalidArgumentException('Unsupported logo type.');
        // Server-chosen name; the client's file name is never used in a path.
        $path = "tenants/{$tenantId}/logo/".Str::random(40).'.'.$ext;
        Storage::disk($this->disk())->put($path, $file->get());

        return $path;
    }

    private function disk(): string
    {
        return (string) config('tenancy.private_disk', 'local');
    }
}
