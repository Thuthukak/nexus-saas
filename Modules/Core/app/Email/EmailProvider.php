<?php

declare(strict_types=1);

namespace Modules\Core\Email;

enum EmailProvider: string
{
    case PlatformShared = 'platform_shared';
    case Microsoft365 = 'microsoft365';
    case CustomSmtp = 'custom_smtp';

    public function needsEnterprise(): bool
    {
        return $this !== self::PlatformShared;
    }
}
