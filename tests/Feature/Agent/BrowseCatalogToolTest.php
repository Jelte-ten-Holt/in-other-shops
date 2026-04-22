<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Agent\Tools\BrowseCatalog;
use InOtherShops\Tests\Stubs\TestBrowsable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class BrowseCatalogToolTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('storefront.models', [
            'browsable' => TestBrowsable::class,
        ]);
    }

    #[Test]
    public function it_returns_paginated_browsables_with_meta(): void
    {
        TestBrowsable::factory()->count(3)->create();

        $result = app(BrowseCatalog::class)(['type' => 'browsable']);

        $this->assertCount(3, $result['data']);
        $this->assertSame(1, $result['meta']['current_page']);
        $this->assertSame(3, $result['meta']['total']);
        $this->assertArrayHasKey('slug', $result['data'][0]);
        $this->assertArrayHasKey('name', $result['data'][0]);
    }

    #[Test]
    public function it_applies_per_page(): void
    {
        TestBrowsable::factory()->count(5)->create();

        $result = app(BrowseCatalog::class)(['type' => 'browsable', 'per_page' => 2]);

        $this->assertCount(2, $result['data']);
        $this->assertSame(2, $result['meta']['per_page']);
        $this->assertSame(3, $result['meta']['last_page']);
    }

    #[Test]
    public function it_filters_by_search(): void
    {
        TestBrowsable::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        TestBrowsable::factory()->create(['name' => 'Beta', 'slug' => 'beta']);

        $result = app(BrowseCatalog::class)(['type' => 'browsable', 'search' => 'Alpha']);

        $this->assertCount(1, $result['data']);
        $this->assertSame('alpha', $result['data'][0]['slug']);
    }

    #[Test]
    public function it_throws_on_unknown_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown browsable type "mystery"/');

        app(BrowseCatalog::class)(['type' => 'mystery']);
    }

    #[Test]
    public function it_throws_when_no_types_are_configured(): void
    {
        config()->set('storefront.models', []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\(none configured\)/');

        app(BrowseCatalog::class)(['type' => 'browsable']);
    }
}
