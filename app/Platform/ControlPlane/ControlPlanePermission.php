<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane;

/** Pure permission vocabulary and matching for operator staff. */
final class ControlPlanePermission
{
    public const TENANTS_VIEW = 'control_plane.tenants.view';
    public const TENANTS_SUSPEND = 'control_plane.tenants.suspend';
    public const TENANTS_REINSTATE = 'control_plane.tenants.reinstate';
    public const BILLING_VIEW = 'control_plane.billing.view';
    public const ALL = 'control_plane.*';

    private const HELD = '/^control_plane\.(\*|[a-z_]+\.(\*|[a-z_]+))$/D';
    private const NEEDED = '/^control_plane\.[a-z_]+\.[a-z_]+$/D';

    /** Is this a well-formed permission an operator may be granted (exact, area wildcard, or everything)? */
    public static function isValidGrant(string $permission): bool
    {
        return preg_match(self::HELD, $permission) === 1;
    }

    /**
     * @param list<string> $held what the operator was granted
     * @param string $needed an exact permission such as control_plane.tenants.suspend
     */
    public static function grants(array $held, string $needed): bool
    {
        if (preg_match(self::NEEDED, $needed) !== 1) {
            return false;   // a wildcard or malformed value can never be "needed"
        }
        foreach ($held as $h) {
            if (! self::isValidGrant($h)) {
                continue;
            }
            if ($h === $needed || $h === self::ALL || (str_ends_with($h, '.*') && str_starts_with($needed, substr($h, 0, -1)))) {
                return true;
            }
        }

        return false;
    }
}
