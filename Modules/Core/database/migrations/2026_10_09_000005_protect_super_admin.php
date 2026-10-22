<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Database-level guard for the Super Admin role, so no code path (UI, policy bug, raw
| Spatie call, tinker) can assign, rename, delete or strip the wildcard from it at tenant
| level. Only code running inside OperatorAuthority::run() (control plane) may, because that
| sets the transaction-local flag app.operator_authority = 'on'.
|
| Same philosophy as RLS: the application-layer RolePolicy is the first line, this is the backstop.
|
| NOTE: deleting a tenant cascades to its roles, so tenant deletion must also run inside
| OperatorAuthority::run().
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_super_admin_role() RETURNS trigger AS $$
BEGIN
    IF coalesce(current_setting('app.operator_authority', true), '') = 'on' THEN
        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    END IF;

    IF TG_OP = 'DELETE' THEN
        IF OLD.name = 'Super Admin' THEN
            RAISE EXCEPTION 'The Super Admin role cannot be deleted at tenant level' USING ERRCODE = '42501';
        END IF;
        RETURN OLD;
    END IF;

    -- UPDATE: block editing Super Admin, and renaming any other role into Super Admin
    IF OLD.name = 'Super Admin' OR NEW.name = 'Super Admin' THEN
        RAISE EXCEPTION 'The Super Admin role cannot be edited at tenant level' USING ERRCODE = '42501';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION guard_super_admin_assignment() RETURNS trigger AS $$
DECLARE
    target_role_id bigint;
    role_name text;
BEGIN
    IF coalesce(current_setting('app.operator_authority', true), '') = 'on' THEN
        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    END IF;

    target_role_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.role_id ELSE NEW.role_id END;
    SELECT name INTO role_name FROM roles WHERE id = target_role_id;

    IF role_name = 'Super Admin' THEN
        RAISE EXCEPTION 'Super Admin can only be assigned or removed by the platform operator' USING ERRCODE = '42501';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION guard_super_admin_wildcard() RETURNS trigger AS $$
DECLARE
    role_name text;
    permission_name text;
BEGIN
    IF coalesce(current_setting('app.operator_authority', true), '') = 'on' THEN
        RETURN OLD;
    END IF;

    SELECT name INTO role_name FROM roles WHERE id = OLD.role_id;
    SELECT name INTO permission_name FROM permissions WHERE id = OLD.permission_id;

    IF role_name = 'Super Admin' AND permission_name = '*' THEN
        RAISE EXCEPTION 'Super Admin must always retain the wildcard permission' USING ERRCODE = '42501';
    END IF;

    RETURN OLD;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_guard_super_admin_role
    BEFORE UPDATE OR DELETE ON roles
    FOR EACH ROW EXECUTE FUNCTION guard_super_admin_role();

CREATE TRIGGER trg_guard_super_admin_assignment
    BEFORE INSERT OR DELETE ON model_has_roles
    FOR EACH ROW EXECUTE FUNCTION guard_super_admin_assignment();

CREATE TRIGGER trg_guard_super_admin_wildcard
    BEFORE DELETE ON role_has_permissions
    FOR EACH ROW EXECUTE FUNCTION guard_super_admin_wildcard();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS trg_guard_super_admin_wildcard ON role_has_permissions;
DROP TRIGGER IF EXISTS trg_guard_super_admin_assignment ON model_has_roles;
DROP TRIGGER IF EXISTS trg_guard_super_admin_role ON roles;
DROP FUNCTION IF EXISTS guard_super_admin_wildcard();
DROP FUNCTION IF EXISTS guard_super_admin_assignment();
DROP FUNCTION IF EXISTS guard_super_admin_role();
SQL);
    }
};
