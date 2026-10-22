<?php

use Modules\Core\Terminology\TerminologyKey;
use Modules\Core\Terminology\TerminologyRegistry;

it('turns a free-text business type into a mapping key', function (?string $in, string $key) {
    expect(TerminologyKey::fromBusinessType($in))->toBe($key);
})->with([
    ['Healthcare', 'healthcare'], ['Professional Services', 'professional-services'], ['  Retail!! ', 'retail'],
    ['Arts & Crafts', 'arts-crafts'], ['', ''], [null, ''], ['!!!', ''],
]);

it('pluralises naively but correctly for common endings', function (string $s, string $p) {
    expect(TerminologyKey::pluralise($s))->toBe($p);
})->with([['Party', 'Parties'], ['Patient', 'Patients'], ['Class', 'Classes'], ['Box', 'Boxes'], ['Day', 'Days'], ['Church', 'Churches'], ['', '']]);

it('accepts only plain words as display terms', function (string $term, bool $ok) {
    expect(TerminologyKey::isValidTerm($term))->toBe($ok);
})->with([
    ['Patient', true], ['Dental client', true], ["Parent's ward", true], ['Étudiant', true], ['Co-op member', true],
    ['', false], ['   ', false], ['<b>x</b>', false], ['Client 2', false], ['A;DROP', false], ['1abc', false], [str_repeat('a', 41), false],
]);

it('registers generic terms with explicit or derived plurals', function () {
    $r = new TerminologyRegistry();
    $r->register('Party', 'Parties');
    $r->register('Account');

    expect($r->generic('Party'))->toBe(['singular' => 'Party', 'plural' => 'Parties'])
        ->and($r->generic('Account')['plural'])->toBe('Accounts')
        ->and($r->has('Nope'))->toBeFalse()->and($r->all())->toBe(['Party', 'Account']);
    expect(fn () => $r->generic('Nope'))->toThrow(InvalidArgumentException::class);
});
