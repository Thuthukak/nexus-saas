<?php

declare(strict_types=1);

namespace Modules\Core\Parties;

use App\Kernel\Dtos\CustomerCreated;
use App\Kernel\Dtos\CustomerUpdated;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * CoreCustomerManagement. Every query goes through the tenant-scoped Party model (global scope +
 * RLS), so a record can never be created or read without a tenant.
 */
final class PartyService
{
    private const FIELDS = ['name', 'email', 'phone', 'billing_address', 'tax_number'];

    /**
     * Create-or-update by email within the tenant. Keys ABSENT from $data are left unchanged on
     * update; keys present (even null) overwrite. Race-safe: the (tenant_id, email) unique index
     * decides a simultaneous double-create, and the loser becomes an update.
     *
     * @param  array<string, mixed>  $data  name, email (required), phone, billing_address, tax_number
     * @return array{party: Party, created: bool}
     */
    public function store(array $data): array
    {
        $data['email'] = mb_strtolower(trim($data['email']));
        $created = false;

        $party = DB::transaction(function () use ($data, &$created) {
            $existing = Party::query()->where('email', $data['email'])->lockForUpdate()->first();
            if ($existing !== null) {
                return $this->apply($existing, $data);
            }

            try {
                // Savepoint: a unique violation must not abort the surrounding transaction.
                $party = DB::transaction(fn () => Party::create(array_intersect_key($data, array_flip(self::FIELDS))));
                $created = true;

                return $party;
            } catch (UniqueConstraintViolationException) {
                return $this->apply(Party::query()->where('email', $data['email'])->lockForUpdate()->firstOrFail(), $data);
            }
        });

        event($created ? new CustomerCreated($party->toDto()) : new CustomerUpdated($party->toDto()));

        return ['party' => $party, 'created' => $created];
    }

    /**
     * Update by id (allows changing the email). Throws EmailAlreadyUsed if another party has it.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): ?Party
    {
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower(trim($data['email']));
        }

        try {
            $party = DB::transaction(function () use ($id, $data) {
                $party = Party::query()->whereKey($id)->lockForUpdate()->first();

                return $party === null ? null : DB::transaction(fn () => $this->apply($party, $data));
            });
        } catch (UniqueConstraintViolationException) {
            throw new EmailAlreadyUsed('Another record already uses this email address.');
        }

        if ($party !== null) {
            event(new CustomerUpdated($party->toDto()));
        }

        return $party;
    }

    /** @param array<string, mixed> $data */
    private function apply(Party $party, array $data): Party
    {
        $party->fill(array_intersect_key($data, array_flip(self::FIELDS)))->save();

        return $party;
    }
}
