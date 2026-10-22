<?php

use Modules\Core\Settings\SettingRegistry;
use Modules\Core\Settings\SettingsException;
use Modules\Core\Theming\Colour;
use Modules\Core\Theming\ThemePalette;

it('normalises hex colours and rejects malformed ones', function () {
    expect(Colour::normaliseHex('#FFF'))->toBe('#ffffff')
        ->and(Colour::normaliseHex(' #1D4ED8 '))->toBe('#1d4ed8');
    foreach (['red', '#12', '#12345', '#gggggg', '', 'rgb(0,0,0)', '#1d4ed8;'] as $bad) {
        expect(Colour::normaliseHex($bad))->toBeNull();
    }
});

it('computes WCAG contrast', function () {
    expect(Colour::contrast('#000000', '#ffffff'))->toEqualWithDelta(21.0, 0.001)
        ->and(Colour::contrast('#ffffff', '#ffffff'))->toEqualWithDelta(1.0, 0.001)
        ->and(Colour::contrast('#767676', '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        ->and(Colour::contrast('#777777', '#ffffff'))->toBeLessThan(4.5);
});

it('picks readable text colours', function () {
    expect(Colour::readableOn('#ffffff'))->toBe('#111111')->and(Colour::readableOn('#000000'))->toBe('#ffffff');
});

it('validates palette input and reports every bad field', function () {
    [$ok, $errors] = ThemePalette::fromInput(['primary_colour' => '#1D4ED8', 'secondary_colour' => '#444', 'accent_colour' => '#0f766e']);
    expect($errors)->toBe([])->and($ok->secondary)->toBe('#444444');

    [$bad, $errors] = ThemePalette::fromInput(['primary_colour' => 'blue', 'secondary_colour' => null]);
    expect($bad)->toBeNull()->and(array_keys($errors))->toBe(['primary_colour', 'secondary_colour', 'accent_colour']);
});

it('warns for colours below AA on the white surface only', function () {
    [$p] = ThemePalette::fromInput(['primary_colour' => '#1d4ed8', 'secondary_colour' => '#777777', 'accent_colour' => '#ffff00']);

    expect(array_column($p->contrastWarnings(), 'token'))->toBe(['secondary_colour', 'accent_colour']);
});

it('adds readable on_* tokens', function () {
    [$p] = ThemePalette::fromInput(['primary_colour' => '#000000', 'secondary_colour' => '#ffffff', 'accent_colour' => '#0f766e']);

    expect($p->tokens())->toMatchArray(['on_primary' => '#ffffff', 'on_secondary' => '#111111']);
});

it('only allows registered scopes, types and typed defaults', function () {
    $r = new SettingRegistry();
    $r->register('tenant', 'a', 'string', 'x');
    $r->register('Financial', 'b', 'int', 5);

    expect($r->has('tenant', 'a'))->toBeTrue()->and($r->has('tenant', 'zzz'))->toBeFalse()
        ->and($r->defaults('Financial'))->toBe(['b' => 5]);
    expect(fn () => $r->register('Nonsense', 'k', 'string'))->toThrow(SettingsException::class);
    expect(fn () => $r->register('tenant', 'k', 'float'))->toThrow(SettingsException::class);
    expect(fn () => $r->register('tenant', 'k', 'int', 'five'))->toThrow(SettingsException::class);
    expect(fn () => $r->definition('tenant', 'missing'))->toThrow(SettingsException::class);
});

it('checks value types strictly', function () {
    expect(SettingRegistry::matchesType('int', 5))->toBeTrue()->and(SettingRegistry::matchesType('int', '5'))->toBeFalse()
        ->and(SettingRegistry::matchesType('bool', 0))->toBeFalse()->and(SettingRegistry::matchesType('array', []))->toBeTrue()
        ->and(SettingRegistry::matchesType('string', null))->toBeFalse();
});
