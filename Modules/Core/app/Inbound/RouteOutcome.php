<?php

declare(strict_types=1);

namespace Modules\Core\Inbound;

final readonly class RouteOutcome
{
    public const DELIVERED = 'delivered';
    public const DUPLICATE = 'duplicate';
    public const REJECTED = 'rejected';

    private function __construct(public string $status, public ?string $reason = null)
    {
    }

    public static function delivered(): self
    {
        return new self(self::DELIVERED);
    }

    public static function duplicate(): self
    {
        return new self(self::DUPLICATE);
    }

    public static function rejected(string $reason): self
    {
        return new self(self::REJECTED, $reason);
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }
}
