<?php

declare(strict_types=1);

namespace Modules\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Notifications\CoreNotification;
use Modules\Core\Notifications\NotificationTypeRegistry;
use Modules\Core\Notifications\Notifier;

/** Every query is restricted to the signed-in user; the tenant filter is the global scope + RLS. */
final class NotificationController
{
    /** ListNotifications: 200 NotificationListPayload. */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user('workspace')->id;
        $base = CoreNotification::query()->where('user_id', $userId);

        return response()->json([
            'unread_count' => (clone $base)->whereNull('read_at')->count(),
            'notifications' => $base->orderByDesc('created_at')->limit(20)->get()
                ->map(fn (CoreNotification $n) => [
                    'id' => $n->id, 'type' => $n->type, 'title' => $n->title, 'body' => $n->body,
                    'data' => $n->data, 'read_at' => $n->read_at?->toIso8601String(),
                    'created_at' => $n->created_at->toIso8601String(),
                ])->values(),
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        abort_unless(\Illuminate\Support\Str::isUuid($id), 404);

        $updated = CoreNotification::query()->where('user_id', $request->user('workspace')->id)
            ->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);
        abort_if($updated === 0 && ! CoreNotification::query()->where('user_id', $request->user('workspace')->id)->whereKey($id)->exists(), 404);

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        CoreNotification::query()->where('user_id', $request->user('workspace')->id)
            ->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function preferences(Request $request, Notifier $notifier, NotificationTypeRegistry $types): JsonResponse
    {
        $userId = $request->user('workspace')->id;

        return response()->json(['preferences' => collect($types->all())->map(
            fn (string $t) => ['type' => $t] + $notifier->channels($userId, $t),
        )->values()]);
    }

    public function setPreference(Request $request, Notifier $notifier, NotificationTypeRegistry $types): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'type' => ['required', 'string', fn ($a, $value, $fail) => $types->has((string) $value) ?: $fail('Unknown notification type.')],
            'in_app' => ['required', 'boolean'],
            'email' => ['required', 'boolean'],
        ]);
        if ($v->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }

        $d = $v->validated();
        $notifier->setPreference($request->user('workspace')->id, $d['type'], (bool) $d['in_app'], (bool) $d['email']);

        return response()->json(['ok' => true]);
    }
}
