<?php

use App\Platform\Modules\DependencyGraph;

it('accepts Core with no requirements and others requiring Core', function () {
    $g = new DependencyGraph(['Core' => [], 'Financial' => ['Core'], 'Support' => ['Core']]);

    expect($g->requiresOf('Financial'))->toBe(['Core'])
        ->and($g->dependentsOf('Core'))->toBe(['Financial', 'Support'])
        ->and($g->dependentsOf('Financial'))->toBe([]);
});

it('rejects a non-Core module with no Core prerequisite', function () {
    new DependencyGraph(['Core' => [], 'Financial' => []]);
})->throws(InvalidArgumentException::class, 'must declare Core');

it('rejects Core requiring anything', function () {
    new DependencyGraph(['Core' => ['Financial'], 'Financial' => ['Core']]);
})->throws(InvalidArgumentException::class, 'Core must not require');

it('rejects circular requirements between two modules', function () {
    new DependencyGraph(['Core' => [], 'Financial' => ['Core', 'Support'], 'Support' => ['Core', 'Financial']]);
})->throws(InvalidArgumentException::class, 'Circular');

it('rejects a module requiring itself', function () {
    new DependencyGraph(['Core' => [], 'Financial' => ['Core', 'Financial']]);
})->throws(InvalidArgumentException::class, 'Circular');

it('rejects unknown modules', function () {
    new DependencyGraph(['Core' => [], 'Billing' => ['Core']]);
})->throws(InvalidArgumentException::class, 'Unknown module');
