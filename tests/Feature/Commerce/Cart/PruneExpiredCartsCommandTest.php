<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Customer\Models\Customer;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PruneExpiredCartsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_deletes_a_guest_cart_whose_expires_at_is_in_the_past(): void
    {
        $expired = Cart::factory()->create([
            'owner_id' => null,
            'expires_at' => Carbon::now()->subHour(),
        ]);

        $this->artisan('commerce:prune-carts')
            ->expectsOutputToContain('Deleted 1 expired guest cart')
            ->assertExitCode(0);

        $this->assertNull(Cart::query()->find($expired->id));
    }

    #[Test]
    public function it_keeps_a_guest_cart_whose_expires_at_is_in_the_future(): void
    {
        $fresh = Cart::factory()->create([
            'owner_id' => null,
            'expires_at' => Carbon::now()->addHour(),
        ]);

        $this->artisan('commerce:prune-carts')->assertExitCode(0);

        $this->assertNotNull(Cart::query()->find($fresh->id));
    }

    #[Test]
    public function it_keeps_a_guest_cart_with_no_expires_at_set(): void
    {
        // expires_at is nullable; carts that haven't been time-boxed must
        // never be pruned by this command — guest carts can be long-lived
        // until a TTL is explicitly stamped on them.
        $unbounded = Cart::factory()->create([
            'owner_id' => null,
            'expires_at' => null,
        ]);

        $this->artisan('commerce:prune-carts')->assertExitCode(0);

        $this->assertNotNull(Cart::query()->find($unbounded->id));
    }

    #[Test]
    public function it_keeps_an_owned_cart_even_if_its_expires_at_is_in_the_past(): void
    {
        // The command's contract is "guest carts only". An expired cart that
        // belongs to a customer (because they later signed in / claimed it)
        // is the customer's data — pruning it would silently delete user
        // history. Pin this contract.
        $customer = Customer::factory()->create();
        $owned = Cart::factory()->create([
            'owner_type' => $customer->getMorphClass(),
            'owner_id' => $customer->getKey(),
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $this->artisan('commerce:prune-carts')->assertExitCode(0);

        $this->assertNotNull(Cart::query()->find($owned->id),
            'Owned carts must not be pruned even when expired.');
    }

    #[Test]
    public function it_reports_zero_deleted_when_no_carts_qualify(): void
    {
        $this->artisan('commerce:prune-carts')
            ->expectsOutputToContain('Deleted 0 expired guest cart')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_is_idempotent_a_second_run_finds_nothing_to_prune(): void
    {
        Cart::factory()->create([
            'owner_id' => null,
            'expires_at' => Carbon::now()->subHour(),
        ]);

        $this->artisan('commerce:prune-carts')->assertExitCode(0);
        $this->artisan('commerce:prune-carts')
            ->expectsOutputToContain('Deleted 0 expired guest cart')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_deletes_only_the_expired_subset_when_mixed_carts_exist(): void
    {
        $expired1 = Cart::factory()->create(['owner_id' => null, 'expires_at' => Carbon::now()->subHour()]);
        $expired2 = Cart::factory()->create(['owner_id' => null, 'expires_at' => Carbon::now()->subDay()]);
        $fresh = Cart::factory()->create(['owner_id' => null, 'expires_at' => Carbon::now()->addHour()]);
        $unbounded = Cart::factory()->create(['owner_id' => null, 'expires_at' => null]);

        $this->artisan('commerce:prune-carts')
            ->expectsOutputToContain('Deleted 2 expired guest cart')
            ->assertExitCode(0);

        $this->assertNull(Cart::query()->find($expired1->id));
        $this->assertNull(Cart::query()->find($expired2->id));
        $this->assertNotNull(Cart::query()->find($fresh->id));
        $this->assertNotNull(Cart::query()->find($unbounded->id));
    }
}
