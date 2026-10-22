<?php

declare(strict_types=1);

namespace Modules\Core\Notifications;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CoreNotification extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;
    protected $table = 'core_notifications';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
