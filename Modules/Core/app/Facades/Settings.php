<?php

declare(strict_types=1);

namespace Modules\Core\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(string $scope, string $key)
 * @method static array all(string $scope)
 * @method static void set(string $scope, string $key, mixed $value)
 * @method static void setMany(string $scope, array $values)
 */
final class Settings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Modules\Core\Settings\Settings::class;
    }
}
