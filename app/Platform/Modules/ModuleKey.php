<?php

declare(strict_types=1);

namespace App\Platform\Modules;

enum ModuleKey: string
{
    case Core = 'Core';
    case Financial = 'Financial';
    case Support = 'Support';
}
