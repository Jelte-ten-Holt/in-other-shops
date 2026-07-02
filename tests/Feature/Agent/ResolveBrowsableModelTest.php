<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Agent\Support\ResolveBrowsableModel;
use InOtherShops\Agent\Support\ResolveStockableModel;
use InOtherShops\Tests\Stubs\TestBrowsable;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * T-A1 — the browsable/stockable type resolution lives in one place, so the
 * unknown-type error contract is identical across every browsable and stock
 * tool (it used to be triplicated with a drifting "Unknown type" vs "Unknown
 * browsable type" message).
 */
final class ResolveBrowsableModelTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('storefront.models', [
            'thing' => TestBrowsable::class,      // HasStorefrontPresence + HasStock
            'stockonly' => TestStockable::class,  // HasStock, NOT HasStorefrontPresence
        ]);
    }

    #[Test]
    public function it_resolves_a_configured_browsable_type_to_its_model_class(): void
    {
        $this->assertSame(TestBrowsable::class, (new ResolveBrowsableModel)('thing'));
    }

    #[Test]
    public function stockable_resolver_composes_the_browsable_resolver_for_a_stockable_model(): void
    {
        $this->assertSame(TestBrowsable::class, (new ResolveStockableModel)('thing'));
    }

    #[Test]
    public function the_unknown_type_message_is_identical_across_the_browsable_and_stockable_resolvers(): void
    {
        $browsableMessage = $this->messageFrom(fn () => (new ResolveBrowsableModel)('mystery'));
        $stockableMessage = $this->messageFrom(fn () => (new ResolveStockableModel)('mystery'));

        $this->assertStringContainsString('Unknown browsable type "mystery"', $browsableMessage);
        $this->assertSame($browsableMessage, $stockableMessage);
    }

    #[Test]
    public function the_stockable_resolver_rejects_a_browsable_that_is_not_stockable(): void
    {
        // 'stockonly' maps to a model that lacks HasStorefrontPresence, so the
        // browsable check fails first — proving the composed resolver still runs
        // the storefront-presence gate before the stock gate.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not implement HasStorefrontPresence/');

        (new ResolveStockableModel)('stockonly');
    }

    private function messageFrom(callable $fn): string
    {
        try {
            $fn();
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        $this->fail('Expected an InvalidArgumentException.');
    }
}
