<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use App\Kernel\Contracts\DashboardWidgetRegistrar;
use App\Kernel\Contracts\EmailSender;
use App\Kernel\Contracts\ExtensionPointRegistrar;
use App\Kernel\Contracts\PartyDirectory;
use App\Kernel\Contracts\ProvisionsTenantAdmin;
use App\Kernel\Contracts\RecordMetadataStore;
use App\Kernel\Contracts\ReplyAddressIssuer;
use App\Kernel\Contracts\ReportingRegistrar;
use App\Kernel\Contracts\TerminologyResolver;
use App\Kernel\Dtos\NotificationRequested;
use App\Kernel\Dtos\UsageRecorded;
use App\Kernel\Dtos\UsageThresholdReached;
use App\Tenancy\TenantContextSet;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Console\RefreshSummariesCommand;
use Modules\Core\Dashboard\DashboardRegistry;
use Modules\Core\Email\EmailGateway;
use Modules\Core\Email\MicrosoftGraphClient;
use Modules\Core\Email\ReplyAddressService;
use Modules\Core\Email\ReplyTokenSigner;
use Modules\Core\Email\SmtpConnectionTester;
use Modules\Core\Email\SmtpHostGuard;
use Modules\Core\Email\SymfonySmtpConnectionTester;
use Modules\Core\Extensions\ExtensionPointRegistry;
use Modules\Core\Notifications\HandleNotificationRequested;
use Modules\Core\Notifications\NotificationEmailSender;
use Modules\Core\Notifications\NotificationTypeRegistry;
use Modules\Core\Notifications\NotifyAdminsOfUsageThreshold;
use Modules\Core\Notifications\PlatformMailerEmailSender;
use Modules\Core\Parties\PartyDirectoryService;
use Modules\Core\Parties\Party;
use Modules\Core\Permissions\PermissionKey;
use Modules\Core\Permissions\RolePolicy;
use Modules\Core\Permissions\TenantAdminGrants;
use Modules\Core\Services\CoreTenantSeeder;
use Modules\Core\Records\OpenEntityRegistry;
use Modules\Core\Reporting\CoreCustomersMetricSource;
use Modules\Core\Reporting\ReportingRegistry;
use Modules\Core\Records\RecordMetadataService;
use Modules\Core\Services\TenantAdminProvisioner;
use Modules\Core\Settings\SettingRegistry;
use Modules\Core\Terminology\TerminologyRegistry;
use Modules\Core\Terminology\TerminologyService;
use Modules\Core\Theming\ThemeService;
use Modules\Core\Usage\RecordUsageListener;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds outbound email (CoreEmailIntegration).
 * Adds the dashboard widget registry and the reporting pipeline.
 * Adds the Party directory and terminology bindings, plus (from overlay 6) the settings/notification registries, theme setting keys,
 * notification listeners and the Core workspace routes.
 */
final class CorePlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProvisionsTenantAdmin::class, TenantAdminProvisioner::class);

        // Registries are process-wide singletons; other modules register their keys/types in boot().
        $this->app->singleton(SettingRegistry::class);
        $this->app->singleton(NotificationTypeRegistry::class);
        $this->app->bind(NotificationEmailSender::class, PlatformMailerEmailSender::class);

        $this->app->singleton(TerminologyRegistry::class);
        $this->app->bind(TerminologyService::class);
        $this->app->bind(TerminologyResolver::class, TerminologyService::class);
        $this->app->bind(PartyDirectory::class, PartyDirectoryService::class);

        // Extension points + record extensions. Hooks are published in register() (not boot()) so they
        // exist before ANY module provider's boot() tries to register a component against them.
        $this->app->singleton(ExtensionPointRegistry::class);
        $this->app->bind(ExtensionPointRegistrar::class, ExtensionPointRegistry::class);
        $this->app->singleton(OpenEntityRegistry::class);
        $this->app->singleton(RecordMetadataService::class);
        $this->app->bind(RecordMetadataStore::class, RecordMetadataService::class);

        // Dashboard widgets + cross-module reporting: registries are singletons; modules register in boot().
        $this->app->singleton(DashboardRegistry::class);
        $this->app->bind(DashboardWidgetRegistrar::class, DashboardRegistry::class);
        $this->app->singleton(ReportingRegistry::class);
        $this->app->bind(ReportingRegistrar::class, ReportingRegistry::class);

        // Outbound email: modules depend on the two kernel contracts only.
        $this->app->bind(EmailSender::class, EmailGateway::class);
        $this->app->singleton(ReplyTokenSigner::class, fn () => ReplyTokenSigner::fromAppKey((string) config('app.key')));
        $this->app->bind(ReplyAddressIssuer::class, ReplyAddressService::class);
        $this->app->singleton(SmtpHostGuard::class);
        $this->app->bind(SmtpConnectionTester::class, SymfonySmtpConnectionTester::class);
        $this->app->singleton(MicrosoftGraphClient::class);

        $hooks = $this->app->make(ExtensionPointRegistry::class);
        $hooks->publish('CustomerProfileTabs', 'Tabs on the customer profile screen');
        $hooks->publish('DashboardWidgets', 'Widgets on the dashboard');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        $this->loadRoutesFrom(dirname(__DIR__, 2).'/routes/web.php');
        $this->loadRoutesFrom(dirname(__DIR__, 2).'/routes/platform.php');

        // Record types open for module metadata, and Core's own tab that shows it.
        $this->app->make(OpenEntityRegistry::class)->open(
            'customers', 'Core',
            fn (string $id) => ctype_digit($id) && Party::query()->whereKey((int) $id)->exists(),
            writePermission: 'core.customers.manage',
        );
        $this->app->make(ExtensionPointRegistry::class)->register('CustomerProfileTabs', 'Core', 'Core/ModuleDataTab', 'Module data', 900);

        // Core's own dashboard widgets and report metric.
        $widgets = $this->app->make(DashboardRegistry::class);
        $widgets->register('Core', 'customers', 'Customers', '/core/dashboard/widgets/customers', 0, 0, 4, 2);
        $widgets->register('Core', 'customers-trend', 'New customers', '/core/dashboard/widgets/customers-trend', 4, 0, 8, 3);
        $this->app->make(ReportingRegistry::class)->register(new CoreCustomersMetricSource());

        if ($this->app->runningInConsole()) {
            $this->commands([RefreshSummariesCommand::class]);
        }

        // Core's own generic entity names; other modules register theirs the same way.
        $this->app->make(TerminologyRegistry::class)->register('Party', 'Parties');

        ThemeService::registerSettings($this->app->make(SettingRegistry::class));
        $this->app->make(NotificationTypeRegistry::class)->register(NotifyAdminsOfUsageThreshold::TYPE, inApp: true, email: true);

        // Roles are tenant-scoped: point Spatie's "team" AND its cache at the current tenant.
        // RLS filters each permission's roles to the active tenant, so one shared cache would
        // hold whichever tenant loaded first and deny (or mis-grant) everyone else.
        Event::listen(TenantContextSet::class, function (TenantContextSet $e): void {
            $registrar = app(PermissionRegistrar::class);

            config(['permission.cache.key' => 'spatie.permission.cache.tenant.'.$e->tenantId]);
            $registrar->initializeCache();
            $registrar->setPermissionsTeamId($e->tenantId);

            // Drop the in-memory copy so the next check loads THIS tenant's cache entry.
            if (method_exists($registrar, 'clearPermissionsCollection')) {
                $registrar->clearPermissionsCollection();
            } elseif (method_exists($registrar, 'clearClassPermissions')) {
                $registrar->clearClassPermissions();
            }
        });

        // Cross-module input arrives by event only.
        Event::listen(UsageRecorded::class, RecordUsageListener::class);
        Event::listen(NotificationRequested::class, [HandleNotificationRequested::class, 'handle']);
        Event::listen(UsageThresholdReached::class, [NotifyAdminsOfUsageThreshold::class, 'handle']);

        Gate::policy(Role::class, RolePolicy::class);

        // Any module toggle re-syncs Tenant Admin. Idempotent, so enabled/disabled needn't be inspected.
        Event::listen(ModuleStateChanged::class, fn (ModuleStateChanged $e) => app(TenantAdminGrants::class)->sync($e->tenantId()));

        Gate::before(function ($user, string $ability, array $arguments = []) {
            $subject = $arguments[0] ?? null;
            if ($subject instanceof Role && $subject->name === PermissionKey::SUPER_ADMIN_ROLE) {
                return null;
            }

            if (method_exists($user, 'hasRole')
                && ($user->hasRole(PermissionKey::SUPER_ADMIN_ROLE) || $user->checkPermissionTo(PermissionKey::WILDCARD))) {
                return true;
            }

            return null;
        });
    }
}
