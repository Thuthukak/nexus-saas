<?php

declare(strict_types=1);

namespace App\Kernel\Contracts;

use App\Kernel\Dtos\CustomerDTO;

/**
 * Read access to the tenant's shared customer directory for Financial, Support and future modules.
 * Always scoped to the ambient tenant: an id from another tenant simply is not found.
 */
interface PartyDirectory
{
    public function find(int $customerId): ?CustomerDTO;

    /**
     * @param  list<int>  $customerIds
     * @return array<int, CustomerDTO> keyed by customer id (missing ids are absent)
     */
    public function findMany(array $customerIds): array;
}
