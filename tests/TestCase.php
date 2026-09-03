<?php

declare(strict_types=1);

namespace InOtherShops\Tests;

use InOtherShops\Agent\AgentServiceProvider;
use InOtherShops\Commerce\CommerceServiceProvider;
use InOtherShops\Currency\CurrencyServiceProvider;
use InOtherShops\FlowChain\FlowChainServiceProvider;
use InOtherShops\Inventory\InventoryServiceProvider;
use InOtherShops\Location\LocationServiceProvider;
use InOtherShops\Logging\LoggingServiceProvider;
use InOtherShops\Media\MediaServiceProvider;
use InOtherShops\Payment\PaymentServiceProvider;
use InOtherShops\Pricing\PricingServiceProvider;
use InOtherShops\Purchasing\PurchasingServiceProvider;
use InOtherShops\Shipping\ShippingServiceProvider;
use InOtherShops\Storefront\StorefrontServiceProvider;
use InOtherShops\Support\SupportServiceProvider;
use InOtherShops\Tax\TaxServiceProvider;
use InOtherShops\Taxonomy\TaxonomyServiceProvider;
use InOtherShops\Tests\Stubs\StubModel;
use InOtherShops\Tracking\TrackingServiceProvider;
use InOtherShops\Translation\TranslationServiceProvider;
use InOtherShops\Variants\VariantsServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use OPGG\LaravelMcpServer\LaravelMcpServerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Default to in-memory SQLite, but let a real DB_CONNECTION env var win.
     *
     * This used to hardcode sqlite unconditionally, which silently defeated
     * CI's MySQL leg: phpunit.xml deliberately declares DB_CONNECTION without
     * force="true" so a real env var can override it, but this method then
     * overrode the override. The whole matrix ran SQLite, and a MySQL-only
     * bug (an unsigned-column underflow in MaintainCategoryCounts) shipped
     * green. Anything reading the connection must go through here.
     */
    protected function defineEnvironment($app): void
    {
        $connection = (string) (env('DB_CONNECTION') ?: 'sqlite');

        $app['config']->set('database.default', $connection);

        if ($connection === 'sqlite') {
            $app['config']->set('database.connections.sqlite', [
                'driver' => 'sqlite',
                'database' => env('DB_DATABASE', ':memory:'),
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        } else {
            // Merge over Testbench's defaults so driver-level flags we rely on
            // (notably MySQL's strict mode, which is what surfaces unsigned
            // underflow as an error rather than a silent clamp) survive.
            $app['config']->set("database.connections.{$connection}", array_merge(
                (array) $app['config']->get("database.connections.{$connection}", []),
                array_filter([
                    'driver' => $connection,
                    'host' => env('DB_HOST'),
                    'port' => env('DB_PORT'),
                    'database' => env('DB_DATABASE'),
                    'username' => env('DB_USERNAME'),
                    'password' => env('DB_PASSWORD'),
                ], static fn ($value): bool => $value !== null),
            ));
        }

        Relation::morphMap(StubModel::stubClasses());
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Stubs/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            SupportServiceProvider::class,
            CurrencyServiceProvider::class,
            TranslationServiceProvider::class,
            LoggingServiceProvider::class,
            LocationServiceProvider::class,
            MediaServiceProvider::class,
            TaxonomyServiceProvider::class,
            PricingServiceProvider::class,
            InventoryServiceProvider::class,
            PurchasingServiceProvider::class,
            TaxServiceProvider::class,
            ShippingServiceProvider::class,
            PaymentServiceProvider::class,
            CommerceServiceProvider::class,
            VariantsServiceProvider::class,
            FlowChainServiceProvider::class,
            StorefrontServiceProvider::class,
            TrackingServiceProvider::class,
            LaravelMcpServerServiceProvider::class,
            AgentServiceProvider::class,
        ];
    }
}
