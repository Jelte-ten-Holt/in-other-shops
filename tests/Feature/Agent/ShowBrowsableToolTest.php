<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Agent\Tools\ShowBrowsable;
use InOtherShops\Tests\Stubs\TestBrowsable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class ShowBrowsableToolTest extends TestCase
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
    public function it_returns_a_found_browsable_by_slug(): void
    {
        TestBrowsable::factory()->create(['slug' => 'the-one']);

        $result = app(ShowBrowsable::class)(['type' => 'browsable', 'slug' => 'the-one']);

        $this->assertTrue($result['ok']);
        $this->assertSame(['type' => 'browsable', 'slug' => 'the-one'], $result['target']);
        $this->assertSame('the-one', $result['data']['slug']);
    }

    #[Test]
    public function it_returns_not_found_when_slug_does_not_match(): void
    {
        $result = app(ShowBrowsable::class)(['type' => 'browsable', 'slug' => 'missing']);

        $this->assertFalse($result['ok']);
        $this->assertSame(['type' => 'browsable', 'slug' => 'missing'], $result['target']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    #[Test]
    public function it_throws_on_unknown_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ShowBrowsable::class)(['type' => 'mystery', 'slug' => 'anything']);
    }
}
