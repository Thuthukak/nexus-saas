<?php

declare(strict_types=1);

namespace Modules\Core\Usage;

use RuntimeException;

/** A money usage event arrived in a currency other than the tenant's billing currency. Never converted silently. */
final class UsageCurrencyMismatch extends RuntimeException
{
}
