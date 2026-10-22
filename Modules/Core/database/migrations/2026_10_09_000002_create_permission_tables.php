<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| spatie/laravel-permission tables with `teams` enabled and a UUID team key (tenant_id).
| DO NOT also publish Spatie's own migration: this replaces it.
|
| RLS covers roles, model_has_roles, model_has_permissions (they carry tenant_id).
| `permissions` is a platform-wide catalogue of module.resource.action keys, and
| `role_has_permissions` is a pivot with no tenant column; it is reachable only through
| role ids that RLS already hides, so it is deliberately left out of RLS.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();

            $t->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();

            $t->unique(['tenant_id', 'name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $t) {
            $t->unsignedBigInteger('permission_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $t->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $t->index(['model_id', 'model_type']);
            $t->primary(['tenant_id', 'permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $t) {
            $t->unsignedBigInteger('role_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $t->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $t->index(['model_id', 'model_type']);
            $t->primary(['tenant_id', 'role_id', 'model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $t) {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');

            $t->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $t->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $t->primary(['permission_id', 'role_id']);
        });

        foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
            Rls::disable($table);
        }
        foreach (['role_has_permissions', 'model_has_roles', 'model_has_permissions', 'roles', 'permissions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
