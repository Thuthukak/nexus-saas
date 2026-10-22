<?php

declare(strict_types=1);

namespace Modules\Core\Parties;

use App\Kernel\Contracts\PartyDirectory;
use App\Kernel\Dtos\CustomerDTO;

final class PartyDirectoryService implements PartyDirectory
{
    public function find(int $customerId): ?CustomerDTO
    {
        return Party::query()->whereKey($customerId)->first()?->toDto();
    }

    public function findMany(array $customerIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $customerIds)));
        if ($ids === []) {
            return [];
        }

        return Party::query()->whereIn('id', $ids)->get()->mapWithKeys(fn (Party $p) => [$p->id => $p->toDto()])->all();
    }
}
