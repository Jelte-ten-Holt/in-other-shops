<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Support;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use InOtherShops\Inventory\Events\StockAdjusted;
use InOtherShops\Payment\Events\PaymentRefunded;
use InOtherShops\Purchasing\Events\ItemsReceived;
use InOtherShops\Purchasing\Models\PurchaseOrder;
use InOtherShops\Taxonomy\Events\CategoryAttached;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * D7/D8 (complexity cleanup release 2, PR 7): `{key}.enabled = false` switches a
 * `DomainServiceProvider` domain off. Set before the providers boot, as a
 * consumer's config file is. Purchasing has only the base's steps; Inventory
 * and Taxonomy prove a child's extras moved into bootDomain() — an extra left
 * in boot() would still be registered here. No database: with these domains
 * off their migrations do not load, which is the point.
 */
final class DomainEnableGateTest extends TestCase
{
    use BootsFilament {
        defineEnvironment as bootFilamentEnvironment;
    }

    protected function defineEnvironment($app): void
    {
        $this->bootFilamentEnvironment($app);

        foreach (['purchasing', 'inventory', 'taxonomy'] as $domain) {
            $app['config']->set("{$domain}.enabled", false);
        }
    }

    #[Test]
    public function a_disabled_domain_loads_no_migrations(): void
    {
        $paths = array_map('realpath', app('migrator')->paths());

        foreach (['Purchasing', 'Inventory', 'Taxonomy'] as $domain) {
            $this->assertNotContains(realpath(__DIR__."/../../../src/{$domain}/Database/Migrations"), $paths, "{$domain} migrations loaded");
        }

        $this->assertContains(realpath(__DIR__.'/../../../src/Commerce/Database/Migrations'), $paths, 'an enabled domain still loads');
    }

    #[Test]
    public function a_disabled_domain_subscribes_and_observes_nothing(): void
    {
        $this->assertFalse(Event::hasListeners(ItemsReceived::class), 'Purchasing log subscriber');
        $this->assertFalse(Event::hasListeners(StockAdjusted::class), 'Inventory log subscriber');
        $this->assertFalse(Event::hasListeners(CategoryAttached::class), 'Taxonomy count maintenance');
        $this->assertFalse(Event::hasListeners('eloquent.updating: '.Category::class), 'Taxonomy observer');

        $this->assertTrue(Event::hasListeners(PaymentRefunded::class), 'an enabled domain still listens');
    }

    #[Test]
    public function a_disabled_domain_registers_no_commands_schedules_or_components(): void
    {
        $commands = array_keys(Artisan::all());

        $this->assertNotContains('purchasing:reconcile-receipts', $commands);
        $this->assertNotContains('inventory:reconcile', $commands);
        $this->assertNotContains('taxonomy:recompute-category-counts', $commands);
        $this->assertContains('commerce:reconcile-refunds', $commands);

        $scheduled = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");
        $this->assertStringNotContainsString('inventory:', $scheduled);
        $this->assertStringContainsString('pricing:expire-compare-at', $scheduled);

        $this->assertNull(app('livewire.finder')->resolveClassComponentClassName('inventory-stock-movements-table'));

        $this->assertArrayNotHasKey('shops-purchasing', app('translator')->getLoader()->namespaces());
    }

    /** Rows elsewhere still point at a disabled domain's models; the map is required. */
    #[Test]
    public function a_disabled_domain_keeps_its_morph_aliases(): void
    {
        $this->assertSame(PurchaseOrder::class, Relation::getMorphedModel('purchase_order'));
    }
}
