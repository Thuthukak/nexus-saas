# Implementation Context

Technology decisions for `platform.butter` (spec v3.0.0). Give this file to the AI agent alongside the compiled spec prompt. The spec states the intent; this file states how to realise it.

## Stack

- Use Laravel 11 on PHP 8.3+ for the backend, with PostgreSQL 15+ as a single shared database for all tenants.
- Use Vue 3 Composition API with Inertia.js for the frontend.
- Use PestPHP for backend tests and Vitest for frontend tests.

## Hosting and domains

- Host on Elithost (elithost.co.za). The platform needs root access to run Caddy, PostgreSQL, a queue worker, and the scheduler, so use a VPS or dedicated plan, not cPanel shared hosting.
- The platform root domain is `pendagraphics.co.za`. Tenant workspaces are `client-slug.pendagraphics.co.za`; the control plane is `admin.pendagraphics.co.za`.
- Reserved subdomains that can never be assigned to a tenant: `www`, `api`, `admin`, `app`, `mail`, `reply`, `control`, `status`. Keep the list in `config/tenancy.php`.
- Inbound reply mail uses the `reply.pendagraphics.co.za` sub-domain, delivered to a single catch-all mailbox. See "Inbound email intake" below.

## TLS with Caddy

- Run Caddy as the edge reverse proxy in front of PHP-FPM or Octane.
- Custom domains use Caddy on-demand TLS. Configure the `ask` endpoint to call an internal Laravel route that returns 200 only when the hostname is a verified, active custom domain. Without this check, anyone could make Caddy request certificates for arbitrary hostnames.
- Let's Encrypt allows roughly 50 new certificates per week per registered domain, so issuing an on-demand certificate per signup subdomain will hit the limit as signups grow. Prefer one wildcard certificate for `*.pendagraphics.co.za` via the DNS-01 challenge. That needs a Caddy DNS plugin for the DNS provider; if Elithost's DNS has none, move the zone to a provider that does (for example Cloudflare, DNS-only mode).
- Serve the control plane and the tenant wildcard from the same Laravel app, with routes registered by host: control-plane routes only under `admin.pendagraphics.co.za`, tenant routes only under tenant hosts and verified custom domains.

## Modules and permissions

- Structure business capabilities as isolated `nwidart/laravel-modules` packages (Core, Financial, Support), each owning its own migrations, routes, controllers, models, and Vue components.
- Financial and Support depend only on Core and never on each other.
- Shared kernel code (`Dto`, `CrossModuleEvent`, tenancy classes) lives in `app/` and may be used by every module.
- Use `spatie/laravel-permission` with teams enabled and `tenant_id` as the team key, so roles and permissions are per tenant.

## Cross-module communication

- Cross-module events are Laravel events dispatched through Core's event bus and implement `CrossModuleEvent`.
- Every typed DTO is an immutable PHP class (`abstract readonly class Dto`). Cross-module payloads must never be a raw array, `stdClass`, or Eloquent model.

## Routing: Inertia versus JSON

- Internal pages use Inertia controllers with no separate REST API layer. Endpoint contracts whose routes have no `/api` prefix in the spec are Inertia routes: honour the status semantics (403 for missing permission, 404, 409, validation failures as Inertia form errors) and expose the documented payload as page props or flash data rather than JSON.
- Only four routes are real JSON routes under `/api`: `CreateTenant` (signup), `PlatformBillingWebhook`, `PaymentGatewayWebhook`, and `StoreTicket` (the public web form). Exempt webhooks from CSRF and verify their signatures instead.

## Tenant isolation

- Tenant ids are UUIDs.
- Application-level tenant filter: a global Eloquent scope (`BelongsToTenant`) on every tenant-owned model.
- The global scope does not protect raw SQL, `DB::table()` calls, third-party package models, or queries that explicitly disable scopes.
- Database-level backstop: PostgreSQL Row-Level Security on every tenant-owned table, keyed on `app.current_tenant_id`.
- `TenantMiddleware` wraps each request in a database transaction and sets the variable with `set_config('app.current_tenant_id', ?, true)`, which is equivalent to `SET LOCAL` and is safe behind connection pooling. Queue jobs need a job middleware that does the same.
- Policies fail closed: use `nullif(current_setting('app.current_tenant_id', true), '')::uuid`, `FORCE ROW LEVEL SECURITY`, and both `USING` and `WITH CHECK`.
- The application connects as a non-superuser role without `BYPASSRLS`. Run migrations as a separate owner role.
- Row-Level Security must enforce isolation independently of Eloquent. A request must never reach a tenant-scoped query before `app.current_tenant_id` has been set on that connection.
- Central tables (`tenants`, `tenant_domains`) are not under RLS because they are needed to resolve the tenant. Only domains with a non-null `verified_at` may resolve.
- `tenants.database_connection` defaults to the shared connection and exists so a tenant can be routed elsewhere later without a schema change.
- Every tenant-owned table gets a composite index that starts with `tenant_id`.

## Sessions and signup handoff

- Session cookies are host-only: leave the cookie `Domain` attribute unset, and set it per request only if a hostname must differ. Never use `.pendagraphics.co.za`, because that sends one tenant's cookies to every other tenant and to the control plane.
- Because the cookie is host-only, the signup session cannot be shared with the tenant host. After signup, redirect with `window.location.href` (never an Inertia client-side redirect) to a signed, single-use handoff URL on the tenant host, in the style of Laravel signed routes: expiry of one minute, bound to the tenant and user, with a nonce stored so it can be redeemed once. Redeeming it logs the user in and regenerates the session ID.

## Plans, pricing, and usage metering

- Model plans as data, not code: `plans`, `plan_modules`, `plan_limits` (seats, tickets per month, processed volume per month, custom domain allowed), `plan_prices` (plan, currency, cycle), and `tenant_addons` so a future module can be bundled into a plan or sold as an add-on without code changes.
- Seed the catalogue:

| Plan | Seats | Tickets / month | Volume / month (ZAR or USD) | URL | Monthly (ZAR / USD) | Annual monthly-equivalent |
|---|---|---|---|---|---|---|
| Starter | 2 | 50 | R50,000 / $3,000 | Shared subdomain | R249 / $19 | R199 / $15 |
| Professional | 10 | 500 | R500,000 / $30,000 | Shared subdomain | R699 / $49 | R559 / $39 |
| Enterprise | Unlimited | Unlimited | Unlimited | Custom domain | from R2,499 / from $149 | R1,999 / $119 |

- Annual charge is twelve times the monthly equivalent, which is 80% of the monthly price rounded down. Store prices in minor units (cents) as integers.
- Billing currency is ZAR when `country_code` is `ZA`, otherwise USD, and is stored on the tenant.
- `CoreUsageMetering` keeps a counter table keyed on `(tenant_id, metric, period)` and updates it with atomic upserts. Financial and Support dispatch `UsageRecorded` events carrying `UsageRecordedDTO`; Core listens. Seats are counted from active workspace users. The seat limit is enforced in a transaction with a row lock. Volume and ticket limits only notify, at 80% and 100%.
- Operator billing processor: build `PlatformBillingEngine` behind a `BillingProcessor` interface with Stripe and Chargebee adapters. Confirm which one can settle ZAR for a South African merchant before choosing the first adapter.

## Reporting

- Pre-aggregated per-module summaries are tenant-scoped, composite-indexed database views or materialized views, refreshed on a schedule suited to each metric.
- Merging large multi-month datasets in PHP at the application layer scales poorly and risks memory exhaustion. Reserve the PHP merge for small result sets.

## Frontend

- The shared UI component library lives under `resources/js/shared`.
- After signup, cross the hostname boundary with a hard browser redirect (`window.location.href`), never an Inertia client-side redirect.
- Share `auth.user`, `auth.permissions`, and the tenant's active modules as Inertia global props on every request after login.
- Return the updated theme as Inertia shared props, and bind it to a `:style` attribute on the root App layout node in Vue rather than only writing CSS custom properties as a side effect.
- Apply the theme through Vue's own reactivity on the root layout binding, not only through a shared-props side effect, to avoid a flash of unstyled content or stale colours.
- Show usage against plan limits on the tenant admin's subscription screen.

## Inbound email intake

- `CoreInboundEmailRouting` has one core, `InboundEmailRouter::route(InboundEmailDTO)`, which verifies the signed Reply-To token, resolves the tenant and record, dedupes, and dispatches `InboundEmailReceived`. It knows nothing about how a message arrived.
- Entry points are thin adapters whose only job is to build an `InboundEmailDTO` and call the router. Launch with the poller adapter; a webhook adapter replaces it later by swapping the entry point only, with no change to the router, DTO, or events.
- Poller (launch): a scheduled Artisan command every minute with `withoutOverlapping()`, reading the catch-all mailbox over IMAP (for example with `webklex/php-imap`). Keep the mailbox credentials in environment secrets, not in tenant settings.
  - Read the original recipient from `Delivered-To` or `X-Original-To`, not only `To`, because a catch-all can lose it when the mail was BCC'd or forwarded. Put that value in the DTO's recipient field, since the signature lives in the `reply+...` local part.
  - Fetch unseen messages only, and cap message size. Dedupe on `Message-ID` with a unique index so re-polling is harmless.
  - After handling, move each message to a `Processed` or `Rejected` folder (invalid signature, no match, oversized) instead of deleting, so failures can be inspected.
  - The poller runs outside any tenant. It reads no tenant data until the signature is verified; the router then sets `app.current_tenant_id` using the same helper as the queue job middleware before touching tenant-scoped tables.
- Webhook (later): a JSON route that verifies the provider's signature, builds the same DTO, and calls the same router. When added, it becomes the fifth JSON route under `/api` and is exempt from CSRF; keep the `Message-ID` dedupe because providers retry.
- Tests: write the router tests against `InboundEmailDTO` directly, so they stay valid when the entry point changes. Test each adapter only for DTO construction.

## Email security

- Signed Reply-To tokens should be a genuine cryptographic signature, in the style of Laravel's signed routes: HMAC-SHA256 over the tenant id and record reference with the application key, verified with `hash_equals` before any tenant lookup. Address format: `reply+{record}_{signature}@reply.pendagraphics.co.za`.
- Microsoft 365 and custom SMTP delivery are offered to Enterprise tenants only; everyone else sends through the platform's shared, pre-verified sending domain.
- Store third-party credentials encrypted per tenant (Laravel encrypted casts) and never log gateway secrets or card details.
