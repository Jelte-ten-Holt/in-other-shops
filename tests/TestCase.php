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
use InOtherShops\Shipping\ShippingServiceProvider;
use InOtherShops\Storefront\StorefrontServiceProvider;
use InOtherShops\Tax\TaxServiceProvider;
use InOtherShops\Taxonomy\TaxonomyServiceProvider;
use InOtherShops\Tests\Stubs\TestBrowsable;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\Stubs\TestPayable;
use InOtherShops\Tests\Stubs\TestShippableCartable;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Translation\TranslationServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use OPGG\LaravelMcpServer\LaravelMcpServerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        Relation::morphMap([
            'test_stockable' => TestStockable::class,
            'test_payable' => TestPayable::class,
            'test_cartable' => TestCartable::class,
            'test_shippable_cartable' => TestShippableCartable::class,
            'test_browsable' => TestBrowsable::class,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Stubs/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            CurrencyServiceProvider::class,
            TranslationServiceProvider::class,
            LoggingServiceProvider::class,
            LocationServiceProvider::class,
            MediaServiceProvider::class,
            TaxonomyServiceProvider::class,
            PricingServiceProvider::class,
            InventoryServiceProvider::class,
            TaxServiceProvider::class,
            ShippingServiceProvider::class,
            PaymentServiceProvider::class,
            CommerceServiceProvider::class,
            FlowChainServiceProvider::class,
            StorefrontServiceProvider::class,
            LaravelMcpServerServiceProvider::class,
            AgentServiceProvider::class,
        ];
    }
}
