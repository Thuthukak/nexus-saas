<?php

declare(strict_types=1);

namespace App\Kernel;

/**
 * Base class for every typed cross-module payload.
 * Subclasses must be `final readonly class` with promoted constructor properties.
 * Never pass raw arrays, stdClass or Eloquent models across module boundaries.
 */
abstract readonly class Dto
{
}
