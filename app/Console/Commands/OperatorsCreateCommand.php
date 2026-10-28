<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\ControlPlane\ControlPlanePermission;
use App\Platform\ControlPlane\Operator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** php artisan operators:create "Name" email@x.com --permission=control_plane.* [--password=...] */
final class OperatorsCreateCommand extends Command
{
    protected $signature = 'operators:create {name} {email} {--permission=* : e.g. control_plane.* or control_plane.tenants.view} {--password= : prompted when omitted}';
    protected $description = 'Create a platform operator (staff) account for the admin host';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $perms = array_values(array_unique((array) $this->option('permission')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Invalid email.');

            return self::FAILURE;
        }
        if ($perms === []) {
            $this->error('Give at least one --permission, e.g. --permission=control_plane.*');

            return self::FAILURE;
        }
        foreach ($perms as $p) {
            if (! ControlPlanePermission::isValidGrant($p)) {
                $this->error("Invalid permission [{$p}].");

                return self::FAILURE;
            }
        }
        if (DB::table('operators')->whereRaw('lower(email) = ?', [$email])->exists()) {
            $this->error('An operator with that email already exists.');

            return self::FAILURE;
        }
        $password = (string) ($this->option('password') ?: $this->secret('Password (min 12 characters)'));
        if (mb_strlen($password) < 12 || mb_strlen($password) > 72) {
            $this->error('Password must be 12 to 72 characters.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($email, $perms, $password) {
            $op = Operator::create(['name' => (string) $this->argument('name'), 'email' => $email, 'password' => $password, 'is_active' => true]);
            DB::table('operator_permissions')->insert(array_map(fn ($p) => ['operator_id' => $op->id, 'permission' => $p], $perms));
        });
        $this->info("Operator {$email} created.");

        return self::SUCCESS;
    }
}
