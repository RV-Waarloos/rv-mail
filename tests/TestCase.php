<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use OwenIt\Auditing\AuditingServiceProvider;
use RvWaarloos\RvMail\RvMailServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            AuditingServiceProvider::class,
            RvMailServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // In tests is dit package wél eigenaar van het schema, anders draaien de
        // migraties niet.

        $app['config']->set('app.locale', 'nl');
        $app['config']->set('app.fallback_locale', 'nl');
        $app['config']->set('rv-mail.unsubscribe.register_routes', true);

        $app['config']->set('rv-mail.webhook.register_route', true);
        $app['config']->set('rv-mail.mailersend.webhook_secret', 'test-signing-secret');

        $app['config']->set('rv-mail.owns_schema', true);
        $app['config']->set('queue.default', 'null');
        $app['config']->set('database.default', 'testing');

        $app['config']->set('rv-mail.owns_schema', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
