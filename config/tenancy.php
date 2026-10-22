<?php

/*
| REPLACES the overlay-1 file. Adds `scheme` and `port` so workspace and handoff URLs
| work both in production (https, no port) and in local dev (http://acme.localhost:8000).
*/
return [
    /*
    | Platform root domain. Tenants live at {slug}.{root_domain}.
    | Local dev: TENANCY_ROOT_DOMAIN=localhost (browsers resolve *.localhost to this machine).
    */
    'root_domain' => env('TENANCY_ROOT_DOMAIN', 'pendagraphics.co.za'),

    /*
    | Control plane host. Served only from this hostname, never from a tenant host.
    */
    'control_plane_host' => env('TENANCY_CONTROL_PLANE_HOST', 'admin.pendagraphics.co.za'),

    /*
    | URL building for generated workspace links. Production: https and no port.
    | Local dev: TENANCY_URL_SCHEME=http  TENANCY_URL_PORT=8000
    */
    'scheme' => env('TENANCY_URL_SCHEME', 'https'),
    'port' => env('TENANCY_URL_PORT') ? (int) env('TENANCY_URL_PORT') : null,

    /*
    | Subdomains that can never be assigned to a tenant.
    */
    'reserved_subdomains' => [
        'www', 'api', 'admin', 'app', 'mail', 'reply', 'control', 'status',
    ],

    /*
    | Inbound reply mail sub-domain (catch-all mailbox).
    */
    'reply_domain' => env('TENANCY_REPLY_DOMAIN', 'reply.pendagraphics.co.za'),

    /*
    | Name of the PostgreSQL session variable the RLS policies key on.
    */
    'rls_variable' => 'app.current_tenant_id',

    /*
    | Default connection tenants are routed to (tenants.database_connection).
    */
    'default_connection' => env('DB_CONNECTION', 'pgsql'),

    /*
    | Login handoff token lifetime in seconds (spec: expires within one minute).
    */
    'handoff_ttl_seconds' => 60,
];
