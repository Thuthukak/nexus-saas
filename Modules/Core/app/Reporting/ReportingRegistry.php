<?php

declare(strict_types=1);

namespace Modules\Core\Reporting;

use App\Kernel\Contracts\ReportingRegistrar;
use App\Kernel\Contracts\ReportMetricSource;
use App\Kernel\Dtos\ReportMetricDefinition;
use App\Platform\Modules\ModuleKey;
use InvalidArgumentException;

/** Code-level registry of metric sources. Which ones a tenant can query is decided per request from its enabled modules. */
final class ReportingRegistry implements ReportingRegistrar
{
    /** @var array<string, ReportMetricSource> moduleKey => source */
    private array $sources = [];

    public function register(ReportMetricSource $source): void
    {
        $module = $source->moduleKey();
        if (ModuleKey::tryFrom($module) === null) {
            throw new InvalidArgumentException("Unknown module [{$module}].");
        }

        $seen = [];
        foreach ($source->metrics() as $def) {
            if (preg_match('/^[a-z][a-z0-9_.]{1,59}$/', $def->metricKey) !== 1) {
                throw new InvalidArgumentException("Invalid metric key [{$def->metricKey}].");
            }
            if (isset($seen[$def->metricKey])) {
                throw new InvalidArgumentException("Metric [{$def->metricKey}] declared twice.");
            }
            $seen[$def->metricKey] = true;
            if ($def->label === '' || mb_strlen($def->label) > 80) {
                throw new InvalidArgumentException('Metric label must be 1 to 80 characters.');
            }
            if (! in_array($def->unit, [ReportMetricDefinition::COUNT, ReportMetricDefinition::MONEY], true)) {
                throw new InvalidArgumentException("Metric [{$def->metricKey}] has an unknown unit.");
            }
            if ($def->refreshEveryMinutes < 5 || $def->refreshEveryMinutes > 1440) {
                throw new InvalidArgumentException('refreshEveryMinutes must be 5 to 1440.');
            }
            foreach ($this->sources as $otherModule => $other) {
                if ($otherModule === $module) {
                    continue;   // re-registering replaces the module's own previous source
                }
                foreach ($other->metrics() as $o) {
                    if ($o->metricKey === $def->metricKey && $o->unit !== $def->unit) {
                        throw new InvalidArgumentException("Metric [{$def->metricKey}] is already used by {$otherModule} with unit {$o->unit}.");
                    }
                }
            }
        }

        $this->sources[$module] = $source;
    }

    /** @return array<string, ReportMetricSource> */
    public function sources(): array
    {
        return $this->sources;
    }

    public function source(string $module): ?ReportMetricSource
    {
        return $this->sources[$module] ?? null;
    }
}
