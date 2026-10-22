<?php

declare(strict_types=1);

namespace Modules\Core\Theming;

use Illuminate\Validation\ValidationException;
use Modules\Core\Settings\SettingRegistry;
use Modules\Core\Settings\Settings;

/** CoreTheming: palette lives in tenant-scoped settings under the `theme.` group. */
final class ThemeService
{
    public const DEFAULTS = ['primary_colour' => '#b45309', 'secondary_colour' => '#44403c', 'accent_colour' => '#0f766e'];

    public function __construct(private readonly Settings $settings)
    {
    }

    public static function registerSettings(SettingRegistry $registry): void
    {
        foreach (self::DEFAULTS as $token => $default) {
            $registry->register(SettingRegistry::TENANT_SCOPE, 'theme.'.$token, 'string', $default);
        }
    }

    public function current(): ThemePalette
    {
        $input = [];
        foreach (ThemePalette::TOKENS as $token) {
            $input[$token] = $this->settings->get(SettingRegistry::TENANT_SCOPE, 'theme.'.$token);
        }
        [$palette] = ThemePalette::fromInput($input);

        return $palette ?? ThemePalette::fromInput(self::DEFAULTS)[0];
    }

    /**
     * Validates, enforces the contrast warning, persists. A palette with contrast warnings is
     * NOT saved unless the caller explicitly acknowledged them.
     *
     * @param  array<string, mixed>  $input
     * @return array{palette: ThemePalette, warnings: list<array>}
     *
     * @throws ValidationException
     */
    public function update(array $input, bool $acknowledgeContrast): array
    {
        [$palette, $errors] = ThemePalette::fromInput($input);
        if ($palette === null) {
            throw ValidationException::withMessages(array_map(fn ($m) => [$m], $errors));
        }

        $warnings = $palette->contrastWarnings();
        if ($warnings !== [] && ! $acknowledgeContrast) {
            $e = ValidationException::withMessages(['acknowledge_contrast' => ['Some colours fail the WCAG AA contrast check. Confirm to save anyway.']]);
            $e->response = response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
                'warnings' => $warnings,
            ], 422);
            throw $e;
        }

        $values = [];
        foreach ($palette->colours() as $token => $hex) {
            $values['theme.'.$token] = $hex;
        }
        $this->settings->setMany(SettingRegistry::TENANT_SCOPE, $values);

        return ['palette' => $palette, 'warnings' => $warnings];
    }
}
