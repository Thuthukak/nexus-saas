<?php

use Modules\Core\Extensions\ExtensionPointRegistry;
use Modules\Core\Records\MetadataValidator;
use Modules\Core\Records\OpenEntityRegistry;

function extRegistry(): ExtensionPointRegistry
{
    $r = new ExtensionPointRegistry();
    $r->publish('CustomerProfileTabs', 'Tabs');

    return $r;
}

it('only accepts registrations against published hooks', function () {
    $r = extRegistry();

    $r->register('CustomerProfileTabs', 'Support', 'Support/TicketsTab', 'Tickets', 10);
    expect($r->registrationsFor('CustomerProfileTabs'))->toHaveCount(1);
    expect(fn () => $r->register('InvoiceFooter', 'Support', 'Support/X', 'X'))->toThrow(InvalidArgumentException::class);
});

it('keeps a module inside its own component folder', function (string $module, string $component) {
    expect(fn () => extRegistry()->register('CustomerProfileTabs', $module, $component, 'X'))->toThrow(InvalidArgumentException::class);
})->with([
    'another module\'s folder' => ['Support', 'Core/Steal'],
    'path traversal' => ['Support', 'Support/../Core/X'],
    'absolute' => ['Support', '/Support/X'],
    'extension' => ['Support', 'Support/X.vue'],
    'no folder' => ['Support', 'Support'],
    'unknown module' => ['Ghost', 'Ghost/X'],
    'prefix trick' => ['Support', 'SupportEvil/X'],
]);

it('validates label and order', function () {
    $r = extRegistry();
    expect(fn () => $r->register('CustomerProfileTabs', 'Support', 'Support/X', ''))->toThrow(InvalidArgumentException::class);
    expect(fn () => $r->register('CustomerProfileTabs', 'Support', 'Support/X', str_repeat('a', 61)))->toThrow(InvalidArgumentException::class);
    expect(fn () => $r->register('CustomerProfileTabs', 'Support', 'Support/X', 'X', -1))->toThrow(InvalidArgumentException::class);
    expect(fn () => $r->register('CustomerProfileTabs', 'Support', 'Support/X', 'X', 10001))->toThrow(InvalidArgumentException::class);
});

it('orders by order_index, then module, then component, and replaces duplicates', function () {
    $r = extRegistry();
    $r->register('CustomerProfileTabs', 'Support', 'Support/B', 'B', 100);
    $r->register('CustomerProfileTabs', 'Financial', 'Financial/A', 'A', 100);
    $r->register('CustomerProfileTabs', 'Support', 'Support/C', 'C', 5);
    $r->register('CustomerProfileTabs', 'Support', 'Support/B', 'B renamed', 100);   // same (module, component): replaces

    expect(array_column($r->registrationsFor('CustomerProfileTabs'), 'component'))->toBe(['Support/C', 'Financial/A', 'Support/B'])
        ->and($r->registrationsFor('CustomerProfileTabs')[2]['label'])->toBe('B renamed');
});

it('validates hook names when publishing', function () {
    expect(fn () => (new ExtensionPointRegistry())->publish('bad name', 'x'))->toThrow(InvalidArgumentException::class);
});

it('requires metadata to be a bounded JSON object', function (mixed $input, bool $ok) {
    $run = fn () => MetadataValidator::normalise($input);
    $ok ? expect($run())->toBeArray() : expect($run)->toThrow(InvalidArgumentException::class);
})->with([
    'object string' => ['{"a":1}', true],
    'empty object' => ['{}', true],
    'assoc array' => [['a' => ['b' => 1]], true],
    'empty array' => [[], true],
    'list string' => ['[1,2]', false],
    'list array' => [[1, 2], false],
    'scalar' => ['5', false],
    'not json' => ['nope', false],
    'null' => [null, false],
    'too big' => [['x' => str_repeat('a', 70000)], false],
    'too deep' => [json_decode('{"a":{"a":{"a":{"a":{"a":{"a":{"a":1}}}}}}}', true), false],
    'deep enough' => [json_decode('{"a":{"a":{"a":{"a":{"a":{"a":1}}}}}}', true), true],
]);

it('only treats registered entities as open and checks existence through the registered closure', function () {
    $e = new OpenEntityRegistry();
    $e->open('customers', 'Core', fn (string $id) => $id === '7', 'core.customers.manage');

    expect($e->isOpen('customers'))->toBeTrue()->and($e->isOpen('users'))->toBeFalse()
        ->and($e->exists('customers', '7'))->toBeTrue()->and($e->exists('customers', '8'))->toBeFalse()->and($e->exists('users', '7'))->toBeFalse()
        ->and($e->writePermission('customers'))->toBe('core.customers.manage')->and($e->readPermission('customers'))->toBeNull();
    expect(fn () => $e->writePermission('users'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $e->open('Bad Name', 'Core', fn () => true, 'x'))->toThrow(InvalidArgumentException::class);
});
