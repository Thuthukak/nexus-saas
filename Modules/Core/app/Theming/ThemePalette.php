<?php

declare(strict_types=1);

namespace Modules\Core\Theming;

/**
 * A validated palette. Contrast rule (documented, fixed): each of the three colours is used as
 * TEXT (links, headings, emphasis) on the white page surface, so every colour is paired with
 * the surface and must reach WCAG AA 4.5:1. Filled areas (buttons, headers) get an automatic
 * readable text colour (on_*), so they never fail.
 */
final readonly class ThemePalette
{
    public const AA = 4.5;
    public const SURFACE = '#ffffff';
    public const TOKENS = ['primary_colour', 'secondary_colour', 'accent_colour'];

    private function __construct(
        public string $primary,
        public string $secondary,
        public string $accent,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input  keys primary_colour, secondary_colour, accent_colour
     * @return array{0: ?self, 1: array<string, string>}  [palette|null, errors by field]
     */
    public static function fromInput(array $input): array
    {
        $errors = [];
        $clean = [];
        foreach (self::TOKENS as $token) {
            $raw = $input[$token] ?? null;
            $hex = is_string($raw) ? Colour::normaliseHex($raw) : null;
            if ($hex === null) {
                $errors[$token] = 'Use a hex colour such as #1d4ed8.';
            }
            $clean[$token] = $hex;
        }

        return $errors === [] ? [new self($clean['primary_colour'], $clean['secondary_colour'], $clean['accent_colour']), []] : [null, $errors];
    }

    /** @return array<string, string> token => normalised hex */
    public function colours(): array
    {
        return ['primary_colour' => $this->primary, 'secondary_colour' => $this->secondary, 'accent_colour' => $this->accent];
    }

    /**
     * @return list<array{token:string, colour:string, ratio:float, message:string}>
     */
    public function contrastWarnings(): array
    {
        $warnings = [];
        foreach ($this->colours() as $token => $hex) {
            $ratio = round(Colour::contrast($hex, self::SURFACE), 2);
            if ($ratio < self::AA) {
                $warnings[] = [
                    'token' => $token, 'colour' => $hex, 'ratio' => $ratio,
                    'message' => sprintf('%s on the white page background has a contrast of %.2f:1; WCAG AA needs at least 4.5:1.', $hex, $ratio),
                ];
            }
        }

        return $warnings;
    }

    /** Everything the frontend needs to apply the theme. @return array<string, string> */
    public function tokens(): array
    {
        return $this->colours() + [
            'on_primary' => Colour::readableOn($this->primary),
            'on_secondary' => Colour::readableOn($this->secondary),
            'on_accent' => Colour::readableOn($this->accent),
        ];
    }
}
