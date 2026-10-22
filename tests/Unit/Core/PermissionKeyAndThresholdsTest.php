<?php

use Modules\Core\Permissions\PermissionKey;
use Modules\Core\Usage\UsageThresholds;

it('accepts only module.resource.action keys', function (string $key, bool $valid) {
    expect(PermissionKey::isValid($key))->toBe($valid);
})->with([
    ['financial.invoices.create', true],
    ['core.users.manage', true],
    ['support.tickets.view_all', true],
    ['*', false],                      // wildcard is never accepted from input
    ['invoices.create', false],
    ['Financial.Invoices.Create', false],
    ['financial.invoices.create.extra', false],
    ['financial..create', false],
    ['', false],
]);

it('reports 80% and 100% thresholds with integer maths', function (int $used, ?int $limit, array $expected) {
    expect(UsageThresholds::levelsReached($used, $limit))->toBe($expected);
})->with([
    'below 80%'         => [39, 50, []],
    'exactly 80%'       => [40, 50, [80]],
    'between'           => [49, 50, [80]],
    'exactly 100%'      => [50, 50, [80, 100]],
    'over the limit'    => [75, 50, [80, 100]],
    'unlimited'         => [1_000_000, null, []],
    'zero limit'        => [5, 0, []],
    'volume minor unit' => [4_000_000, 5_000_000, [80]],  // R40,000 of R50,000
]);
