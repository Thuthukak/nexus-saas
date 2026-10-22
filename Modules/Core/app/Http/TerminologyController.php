<?php

declare(strict_types=1);

namespace Modules\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Terminology\TerminologyKey;
use Modules\Core\Terminology\TerminologyRegistry;
use Modules\Core\Terminology\TerminologyService;

final class TerminologyController
{
    public function __construct(private readonly TerminologyService $terms, private readonly TerminologyRegistry $registry)
    {
    }

    public function show(Request $request): Response
    {
        abort_unless($request->user('workspace')->can('core.settings.manage'), 403);

        return Inertia::render('Core/Terminology', ['rows' => collect($this->terms->all())->map(fn ($v, $g) => ['generic' => $g] + $v)->values()]);
    }

    public function update(Request $request): JsonResponse
    {
        if (! $request->user('workspace')->can('core.settings.manage')) {
            return response()->json(['message' => 'Missing permission to manage terminology'], 403);
        }

        $v = Validator::make($request->all(), [
            'generic_term' => ['required', 'string', fn ($a, $val, $fail) => $this->registry->has((string) $val) ?: $fail('Unknown term.')],
            'display_term' => ['required', 'string', fn ($a, $val, $fail) => TerminologyKey::isValidTerm((string) $val) ?: $fail('Use 1 to 40 letters, spaces, hyphens or apostrophes.')],
            'display_plural' => ['nullable', 'string', fn ($a, $val, $fail) => ($val === null || $val === '' || TerminologyKey::isValidTerm((string) $val)) ?: $fail('Use 1 to 40 letters, spaces, hyphens or apostrophes.')],
        ]);
        if ($v->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }

        $d = $v->validated();
        $this->terms->override($d['generic_term'], $d['display_term'], $d['display_plural'] ?? null);

        return response()->json($this->terms->all()[$d['generic_term']] + ['generic_term' => $d['generic_term']]);
    }

    public function reset(Request $request, string $generic): JsonResponse
    {
        if (! $request->user('workspace')->can('core.settings.manage')) {
            return response()->json(['message' => 'Missing permission to manage terminology'], 403);
        }
        abort_unless($this->registry->has($generic), 404);

        $this->terms->reset($generic);

        return response()->json($this->terms->all()[$generic] + ['generic_term' => $generic]);
    }
}
