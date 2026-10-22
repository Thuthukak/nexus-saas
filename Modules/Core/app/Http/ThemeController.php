<?php

declare(strict_types=1);

namespace Modules\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Theming\ThemeService;

final class ThemeController
{
    public function __construct(private readonly ThemeService $themes)
    {
    }

    public function show(Request $request): Response
    {
        abort_unless($request->user('workspace')->can('core.settings.manage'), 403);

        return Inertia::render('Core/Theme', ['palette' => $this->themes->current()->colours()]);
    }

    /** UpdateTheme: 200 ThemeUpdatedPayload | 403 | 422. */
    public function update(Request $request): JsonResponse
    {
        if (! $request->user('workspace')->can('core.settings.manage')) {
            return response()->json(['message' => 'Missing permission to manage settings'], 403);
        }

        try {
            $result = $this->themes->update(
                $request->only(['primary_colour', 'secondary_colour', 'accent_colour']),
                $request->boolean('acknowledge_contrast'),
            );
        } catch (ValidationException $e) {
            return $e->response ?? response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        return response()->json([
            'primary_colour' => $result['palette']->primary,
            'secondary_colour' => $result['palette']->secondary,
            'accent_colour' => $result['palette']->accent,
            'warnings' => $result['warnings'],
        ]);
    }
}
