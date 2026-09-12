<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail;

use Illuminate\Support\ServiceProvider;
use RvWaarloos\RvMail\Audiences\AudienceRegistry;
use RvWaarloos\RvMail\Audiences\DistributionListAudience;
use RvWaarloos\RvMail\Contracts\AudienceScopeResolver;
use RvWaarloos\RvMail\Contracts\SuppressionStore;
use RvWaarloos\RvMail\Support\EloquentSuppressionStore;
use RvWaarloos\RvMail\Support\QuotaGuard;
use RvWaarloos\RvMail\Support\UnrestrictedScopeResolver;

final class RvMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rv-mail.php', 'rv-mail');

        $this->app->singleton(AudienceRegistry::class);

        $this->app->bind(SuppressionStore::class, EloquentSuppressionStore::class);

        // Tijdelijke invulling. De club-app moet dit vervangen door een resolver
        // die het echte bereik van een verantwoordelijke kent, vóór er in
        // productie iets verstuurd wordt.
        $this->app->bind(AudienceScopeResolver::class, UnrestrictedScopeResolver::class);

        $this->app->bind(QuotaGuard::class, static fn (): QuotaGuard => QuotaGuard::forCurrentPeriod());
    }

    public function boot(): void
    {
        // Zelfde patroon als RV_CORE_OWNS_CENTRAL_SCHEMA: de mailtabellen leven
        // in de gedeelde rv_central database en exact één app mag ze migreren.
        if (config('rv-mail.owns_schema') === true) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/rv-mail.php' => config_path('rv-mail.php'),
            ], 'rv-mail-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'rv-mail-migrations');
        }

        $this->registerBuiltInAudiences();
    }

    /**
     * Distributielijsten leven in dit package, dus die doelgroep brengt rv-mail
     * zelf mee. Alle club-specifieke doelgroepen registreert de club-app.
     */
    private function registerBuiltInAudiences(): void
    {
        $this->app->afterResolving(
            AudienceRegistry::class,
            function (AudienceRegistry $registry): void {
                if (! $registry->has('distribution_list')) {
                    $registry->register($this->app->make(DistributionListAudience::class));
                }
            },
        );
    }
}
