-- Run ONCE as a PostgreSQL superuser. Adjust passwords.
-- Two roles: the migration owner (owns tables) and the app role (no BYPASSRLS, not superuser).
-- Note: These are just dev credentials. In production, use a more secure password and consider using a vault or secret manager.

CREATE ROLE nexus_saas_owner LOGIN PASSWORD 'Support1@7509' NOSUPERUSER NOBYPASSRLS;
CREATE ROLE nexus_saas_app   LOGIN PASSWORD 'Support1@7509'   NOSUPERUSER NOBYPASSRLS;

CREATE DATABASE nexus_saas OWNER nexus_saas_owner;
\c nexus_saas

GRANT CONNECT ON DATABASE nexus_saas TO nexus_saas_app;
GRANT USAGE ON SCHEMA public TO nexus_saas_app;

-- Anything nexus_saas_owner creates later is automatically usable by nexus_saas_app.
ALTER DEFAULT PRIVILEGES FOR ROLE nexus_saas_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO nexus_saas_app;
ALTER DEFAULT PRIVILEGES FOR ROLE nexus_saas_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO nexus_saas_app;

-- Laravel .env:
--   DB_USERNAME=nexus_saas_app            (runtime)
--   DB_MIGRATE_USERNAME=nexus_saas_owner  (used by `php artisan migrate`, see config/database.php 'pgsql_migrate')
