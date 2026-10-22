<?php

declare(strict_types=1);

namespace App\Kernel\Contracts;

/** Display vocabulary for the ambient tenant (labels in every module's UI and on generated documents). */
interface TerminologyResolver
{
    /** e.g. resolve('Party') => 'Patient'; resolve('Party', plural: true) => 'Patients'. */
    public function resolve(string $genericTerm, bool $plural = false): string;
}
