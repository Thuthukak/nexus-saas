<?php

declare(strict_types=1);

namespace Modules\Core\Terminology;

use App\Kernel\Contracts\TerminologyResolver;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;

/**
 * CoreTerminology. Resolution order for a generic term:
 *   1. the tenant's own override (tenant_terminology),
 *   2. the platform default for the tenant's business type (terminology_mappings),
 *   3. the generic term itself.
 * This only ever changes DISPLAY text. No table or column is renamed or migrated.
 */
final class TerminologyService implements TerminologyResolver
{
    public function __construct(
        private readonly TerminologyRegistry $registry,
        private readonly TenantContext $context,
        private readonly Cache $cache,
    ) {
    }

    public function resolve(string $genericTerm, bool $plural = false): string
    {
        $entry = $this->all()[$genericTerm] ?? null;
        if ($entry === null) {
            // Unregistered names pass through untouched: fall back to the generic term.
            return $plural ? TerminologyKey::pluralise($genericTerm) : $genericTerm;
        }

        return $plural ? $entry['plural'] : $entry['singular'];
    }

    /** @return array<string, array{singular:string, plural:string, source:string}> every registered term, resolved */
    public function all(): array
    {
        $tenantId = $this->context->requireId();

        return $this->cache->remember(self::cacheKey($tenantId), 3600, fn () => $this->compute($tenantId));
    }

    /** Sets (or replaces) this tenant's own wording for a generic term. */
    public function override(string $generic, string $singular, ?string $plural): void
    {
        $this->registry->generic($generic);   // must be registered
        $tenantId = $this->context->requireId();
        $singular = trim($singular);
        $plural = trim((string) $plural) !== '' ? trim($plural) : TerminologyKey::pluralise($singular);

        DB::statement(
            'INSERT INTO tenant_terminology (tenant_id, generic_term, singular, plural, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (tenant_id, generic_term) DO UPDATE SET singular = excluded.singular, plural = excluded.plural, updated_at = excluded.updated_at',
            [$tenantId, $generic, $singular, $plural, now(), now()],
        );
        $this->forget($tenantId);
    }

    /** Removes the override so the business-type default applies again. */
    public function reset(string $generic): void
    {
        $tenantId = $this->context->requireId();
        DB::table('tenant_terminology')->where('tenant_id', $tenantId)->where('generic_term', $generic)->delete();
        $this->forget($tenantId);
    }

    public function forget(string $tenantId): void
    {
        $this->cache->forget(self::cacheKey($tenantId));
        DB::afterCommit(fn () => $this->cache->forget(self::cacheKey($tenantId)));
    }

    public static function cacheKey(string $tenantId): string
    {
        return "tenant:{$tenantId}:terminology";
    }

    /** @return array<string, array{singular:string, plural:string, source:string}> */
    private function compute(string $tenantId): array
    {
        $key = TerminologyKey::fromBusinessType(DB::table('tenants')->where('id', $tenantId)->value('business_type'));
        $defaults = $key === '' ? collect() : DB::table('terminology_mappings')->where('business_type_key', $key)->get()->keyBy('generic_term');
        $overrides = DB::table('tenant_terminology')->where('tenant_id', $tenantId)->get()->keyBy('generic_term');

        $out = [];
        foreach ($this->registry->all() as $generic) {
            if ($o = $overrides->get($generic)) {
                $out[$generic] = ['singular' => $o->singular, 'plural' => $o->plural, 'source' => 'tenant'];
            } elseif ($d = $defaults->get($generic)) {
                $out[$generic] = ['singular' => $d->singular, 'plural' => $d->plural, 'source' => 'business_type'];
            } else {
                $out[$generic] = $this->registry->generic($generic) + ['source' => 'generic'];
            }
        }

        return $out;
    }
}
