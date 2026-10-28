<?php

declare(strict_types=1);

namespace App\Platform\Registry;

/** Pure: the email a tenant administrator receives when their workspace status changes. */
final class TenantStatusMessage
{
    /** @return array{subject:string, text:string} */
    public static function build(string $tenantName, string $to, string $reason): array
    {
        $name = trim(mb_substr(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $tenantName) ?? '', 0, 80)) ?: 'Your';
        $reason = trim($reason);

        return match ($to) {
            'suspended' => [
                'subject' => "Your {$name} workspace has been suspended",
                'text' => "Access to your {$name} workspace has been suspended. Your data has not been deleted.\n\nReason: {$reason}\n\nPlease reply to this email or contact support to get it restored.",
            ],
            'active' => [
                'subject' => "Your {$name} workspace is active again",
                'text' => "Your {$name} workspace has been reinstated and everyone can sign in again.\n\nNote: {$reason}",
            ],
            default => [
                'subject' => "Your {$name} subscription has been cancelled",
                'text' => "Your {$name} workspace has been cancelled.\n\nReason: {$reason}\n\nPlease contact support if this is unexpected.",
            ],
        };
    }
}
