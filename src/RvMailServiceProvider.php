<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RvWaarloos\RvMail\Audiences\AudienceRegistry;
use RvWaarloos\RvMail\Audiences\DistributionListAudience;
use RvWaarloos\RvMail\Campaigns\BladeCampaignRenderer;
use RvWaarloos\RvMail\Console\AnonymizeMemberCommand;
use RvWaarloos\RvMail\Console\PurgeCommand;
use RvWaarloos\RvMail\Console\QuotaCommand;
use RvWaarloos\RvMail\Console\ReconcileCommand;
use RvWaarloos\RvMail\Console\SimulateEventCommand;
use RvWaarloos\RvMail\Contracts\AudienceScopeResolver;
use RvWaarloos\RvMail\Contracts\BulkTransport;
use RvWaarloos\RvMail\Contracts\CampaignRenderer;
use RvWaarloos\RvMail\Contracts\MemberDirectory;
use RvWaarloos\RvMail\Contracts\SuppressionStore;
use RvWaarloos\RvMail\Listeners\LogTransactionalMail;
use RvWaarloos\RvMail\Support\EloquentSuppressionStore;
use RvWaarloos\RvMail\Support\NullMemberDirectory;
use RvWaarloos\RvMail\Support\QuotaGuard;
use RvWaarloos\RvMail\Support\UnrestrictedScopeResolver;
use RvWaarloos\RvMail\Transport\BatchChunker;
use RvWaarloos\RvMail\Transport\FakeBulkTransport;
use RvWaarloos\RvMail\Transport\MailerSendBulkTransport;
use RvWaarloos\RvMail\Webhooks\SignatureVerifier;

final class RvMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rv-mail.php', 'rv-mail');

        $this->app->singleton(AudienceRegistry::class);

        $this->app->bind(SuppressionStore::class, EloquentSuppressionStore::class);
        $this->app->bind(CampaignRenderer::class, BladeCampaignRenderer::class);

        // Tijdelijke invulling. De club-app moet dit vervangen door een resolver
        // die het echte bereik van een verantwoordelijke kent, vóór er in
        // productie iets verstuurd wordt.
        $this->app->bind(AudienceScopeResolver::class, UnrestrictedScopeResolver::class);

        $this->app->bind(QuotaGuard::class, static fn (): QuotaGuard => QuotaGuard::forCurrentPeriod());
        $this->app->bind(BatchChunker::class, static fn (): BatchChunker => BatchChunker::fromConfig());

        $this->app->singleton(SignatureVerifier::class, static function (): SignatureVerifier {
            $secret = config('rv-mail.mailersend.webhook_secret');

            return new SignatureVerifier(is_string($secret) ? $secret : '');
        });

        $this->registerTransport();

        $this->app->bind(MemberDirectory::class, NullMemberDirectory::class);
    }

    public function boot(): void
    {
        // Zelfde patroon als RV_CORE_OWNS_CENTRAL_SCHEMA: de mailtabellen leven
        // in de gedeelde rv_central database en exact één app mag ze migreren.
        if (config('rv-mail.owns_schema') === true) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'rv-mail');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'rv-mail');

        $this->registerWebhookRoute();
        $this->registerUnsubscribeRoutes();
        $this->registerTransactionalLogging();

        if ($this->app->runningInConsole()) {
            $this->commands([
                QuotaCommand::class,
                ReconcileCommand::class,
                SimulateEventCommand::class,
                PurgeCommand::class,
                AnonymizeMemberCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/rv-mail.php' => config_path('rv-mail.php'),
            ], 'rv-mail-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'rv-mail-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/rv-mail'),
            ], 'rv-mail-views');
        }

        $this->registerBuiltInAudiences();
        $this->registerSchedule();
    }

    /**
     * Alleen de app die de webhook host registreert de route. In de andere
     * apps zou hij een tweede publiek endpoint openen zonder dat MailerSend
     * er ooit naartoe wijst.
     */
    private function registerWebhookRoute(): void
    {
        if (config('rv-mail.webhook.register_route') !== true) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
    }

    /**
     * Drie drivers. `fake` wordt ook gebruikt bij RV_MAIL_DRY_RUN, zodat je
     * lokaal de volledige pijplijn kunt draaien zonder credits te verbranden.
     */
    private function registerTransport(): void
    {
        $this->app->singleton(FakeBulkTransport::class);

        $this->app->bind(BulkTransport::class, function (): BulkTransport {
            if (config('rv-mail.mailersend.dry_run') === true) {
                return $this->app->make(FakeBulkTransport::class);
            }

            $key = config('rv-mail.mailersend.api_key');

            if (! is_string($key) || $key === '') {
                throw new \RuntimeException(
                    'MAILERSEND_API_KEY ontbreekt. Zet RV_MAIL_DRY_RUN=true om lokaal zonder sleutel te werken.'
                );
            }

            return new MailerSendBulkTransport($key);
        });
    }

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

    /**
     * Elk uur reconciliëren is geen willekeurige frequentie: op het Hobby plan
     * bewaart MailerSend activity-data 24 uur, en daarna valt niet meer te
     * achterhalen of een request is aangekomen.
     */
    private function registerSchedule(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('rv-mail:reconcile')
                ->hourly()
                ->withoutOverlapping();
            $schedule->command('rv-mail:purge')
                ->dailyAt('03:30')
                ->withoutOverlapping();
        });
    }

    /**
     * De uitschrijfpagina moet publiek bereikbaar zijn en de URL blijft jaren
     * geldig, dus registreer hem op de app waarvan het domein stabiel is.
     */
    private function registerUnsubscribeRoutes(): void
    {
        if (config('rv-mail.unsubscribe.register_routes') !== true) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/unsubscribe.php');
    }

    /**
     * Zonder deze listener zie je in het beheerscherm alleen de mailings, en
     * niet dat het paswoordherstel van een lid al drie keer bouncet.
     */
    private function registerTransactionalLogging(): void
    {
        if (config('rv-mail.transactional.log') !== true) {
            return;
        }

        Event::listen(MessageSent::class, LogTransactionalMail::class);
    }
}
