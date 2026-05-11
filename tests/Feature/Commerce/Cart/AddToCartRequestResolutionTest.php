<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Cart\Http\Requests\AddToCartRequest;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Audit M7 — `AddToCartRequest::resolveCartable` used to fall back to the raw
 * `type` input when the morph map didn't resolve, which meant `new $class`
 * could execute the constructor of any class on the autoloader before the
 * `instanceof HasCart` check fired. The fix enforces the morph map as the
 * sole trust boundary; raw FQCNs are rejected at validation time.
 *
 * resolveCartable is private; reflection is the lightest way to pin its
 * behavior without booting the full HTTP/middleware stack the cart route
 * needs to register cleanly.
 */
final class AddToCartRequestResolutionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_resolves_a_registered_morph_alias(): void
    {
        $cartable = TestCartable::factory()->create();

        $resolved = $this->resolveCartableFor(['type' => 'test_cartable', 'id' => $cartable->id]);

        $this->assertNotNull($resolved);
        $this->assertTrue($cartable->is($resolved));
    }

    #[Test]
    public function it_returns_null_for_a_raw_fqcn_even_when_class_implements_HasCart(): void
    {
        $cartable = TestCartable::factory()->create();

        $resolved = $this->resolveCartableFor(['type' => TestCartable::class, 'id' => $cartable->id]);

        $this->assertNull($resolved,
            'Raw FQCN must be rejected at the morph-map step before instantiation.');
    }

    #[Test]
    public function it_returns_null_for_an_unknown_morph_alias(): void
    {
        $resolved = $this->resolveCartableFor(['type' => 'not_a_registered_alias', 'id' => 1]);

        $this->assertNull($resolved);
    }

    #[Test]
    public function it_returns_null_for_an_arbitrary_FQCN_outside_the_morph_map(): void
    {
        // The pre-fix code did `new $class` here, instantiating an arbitrary
        // class (running its constructor) before the type check fired. The
        // post-fix code rejects this at the morph-map step before any
        // instantiation happens.
        $resolved = $this->resolveCartableFor(['type' => \stdClass::class, 'id' => 1]);

        $this->assertNull($resolved);
    }

    #[Test]
    public function it_returns_null_for_empty_or_non_string_type(): void
    {
        $this->assertNull($this->resolveCartableFor(['type' => '', 'id' => 1]));
        $this->assertNull($this->resolveCartableFor(['type' => null, 'id' => 1]));
        $this->assertNull($this->resolveCartableFor(['type' => 42, 'id' => 1]));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveCartableFor(array $input): ?Model
    {
        $request = new AddToCartRequest;
        $request->replace($input);

        $reflection = new \ReflectionMethod($request, 'resolveCartable');
        $reflection->setAccessible(true);

        return $reflection->invoke($request);
    }
}
