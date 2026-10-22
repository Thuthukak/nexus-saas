<?php

declare(strict_types=1);

namespace Modules\Core\Notifications;

use App\Kernel\Dtos\NotificationRequestedDTO;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;

/**
 * CoreNotifications. For one user and one type: resolve channel preferences, write the in-app
 * row (tenant-scoped), and queue an email when the email channel is on. Toasts are delivered by
 * the client polling the list endpoint while a session is active.
 *
 * A notification is never written or sent for a user outside the resolved tenant:
 *  1. the DTO's tenant must equal the ambient TenantContext,
 *  2. the user is looked up through the tenant-scoped User model (global scope + RLS),
 *  3. the composite foreign key (tenant_id, user_id) -> users(tenant_id, id) rejects mismatches.
 */
final class Notifier
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly NotificationTypeRegistry $types,
        private readonly NotificationEmailSender $email,
    ) {
    }

    /** @return CoreNotification|null the stored in-app notification (null when the in-app channel is off) */
    public function notify(NotificationRequestedDTO $dto): ?CoreNotification
    {
        $tenantId = $this->context->requireId();
        if ($dto->tenantId !== $tenantId) {
            throw new NotificationTenantMismatch('Notification tenant does not match the active tenant.');
        }

        $user = User::query()->whereKey($dto->userId)->first();
        if ($user === null) {
            throw new NotificationTenantMismatch('Notification recipient is not a user of this tenant.');
        }

        $channels = $this->channels($user->id, $dto->type);
        $stored = null;

        if ($channels['in_app']) {
            $stored = CoreNotification::create([
                'user_id' => $user->id,
                'type' => $dto->type,
                'title' => mb_substr($dto->title, 0, 200),
                'body' => $dto->body,
                'data' => $dto->dataJson !== null ? json_decode($dto->dataJson, true) : null,
            ]);
        }

        if ($channels['email'] && $user->is_active) {
            $this->email->queue($tenantId, $user->email, $dto->title, $dto->body);
        }

        return $stored;
    }

    /** @return array{in_app: bool, email: bool} */
    public function channels(int $userId, string $type): array
    {
        $defaults = $this->types->defaults($type);   // throws for unregistered types
        $pref = DB::table('core_notification_preferences')
            ->where('tenant_id', $this->context->requireId())->where('user_id', $userId)->where('type', $type)->first();

        return $pref ? ['in_app' => (bool) $pref->in_app, 'email' => (bool) $pref->email] : $defaults;
    }

    public function setPreference(int $userId, string $type, bool $inApp, bool $email): void
    {
        $this->types->defaults($type);
        $tenantId = $this->context->requireId();

        DB::table('core_notification_preferences')->upsert(
            [['tenant_id' => $tenantId, 'user_id' => $userId, 'type' => $type, 'in_app' => $inApp, 'email' => $email,
              'created_at' => now(), 'updated_at' => now()]],
            ['tenant_id', 'user_id', 'type'],
            ['in_app', 'email', 'updated_at'],
        );
    }
}
