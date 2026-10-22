<?php

declare(strict_types=1);

namespace App\Kernel\Contracts;

interface ReportingRegistrar
{
    /** @throws \InvalidArgumentException invalid definitions, or a metric_key already used with a different unit */
    public function register(ReportMetricSource $source): void;
}
