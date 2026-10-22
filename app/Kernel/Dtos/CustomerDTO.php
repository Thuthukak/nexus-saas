<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;

/**
 * A snapshot of a Party from Core's shared directory. Other modules store ONLY customerId and read
 * the rest through the PartyDirectory contract or from events carrying this DTO; they never keep
 * their own copy of name, email, phone or address.
 */
final readonly class CustomerDTO extends Dto
{
    public function __construct(
        public int $customerId,
        public string $tenantId,
        public string $name,
        public string $email,
        public ?string $phone,
        public ?string $billingAddress,
        public ?string $taxNumber,
    ) {
    }
}
