-- Run as the postgres superuser:  sudo -u postgres psql -f database/sql/test-db.sql
-- Safe to run once. Re-running errors on CREATE DATABASE, which is harmless.

CREATE DATABASE nexus_saas_test OWNER nexus_saas_owner;
\c nexus_saas_test

GRANT CONNECT ON DATABASE nexus_saas_test TO nexus_saas_app;
GRANT USAGE ON SCHEMA public TO nexus_saas_app;

ALTER DEFAULT PRIVILEGES FOR ROLE nexus_saas_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO nexus_saas_app;
ALTER DEFAULT PRIVILEGES FOR ROLE nexus_saas_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO nexus_saas_app;
