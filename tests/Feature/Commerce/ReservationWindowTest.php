<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce;

use InOtherShops\Commerce\CommerceServiceProvider;
use InOtherShops\Commerce\Exceptions\ReservationTtlTooShortException;
use InOtherShops\Commerce\Order\Support\ReservationWindow;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * X6b (decided 2026-09-07): a stock reservation must outlive the window in
 * which its order can still be paid, or a late payment lands on stock that is
 * already back on sale. The package defaults used to break it (TTL 30 against
 * an abandon window of 60); Commerce now refuses to boot on a misordered pair.
 */
final class ReservationWindowTest extends TestCase
{
    #[Test]
    public function the_shipped_defaults_keep_the_ordering(): void
    {
        $this->assertSame(120, (require __DIR__.'/../../../src/Inventory/config/inventory.php')['reservation_ttl']);
        $this->assertSame(60, (require __DIR__.'/../../../src/Commerce/config/commerce.php')['order']['abandon_after_minutes']);

        ReservationWindow::assertOutlivesAbandonWindow();
        $this->addToAssertionCount(1);
    }

    #[Test]
    #[DataProvider('misordered')]
    public function a_ttl_not_longer_than_the_abandon_window_is_refused(int $ttl, int $abandon): void
    {
        config(['inventory.reservation_ttl' => $ttl, 'commerce.order.abandon_after_minutes' => $abandon]);

        $this->expectException(ReservationTtlTooShortException::class);
        $this->expectExceptionMessage("({$ttl} min)");

        ReservationWindow::assertOutlivesAbandonWindow();
    }

    /** @return array<string, array{int, int}> */
    public static function misordered(): array
    {
        return [
            'the old package defaults' => [30, 60],
            'equal' => [60, 60],
        ];
    }

    #[Test]
    public function the_consumers_settings_and_a_null_ttl_boot(): void
    {
        config(['inventory.reservation_ttl' => 90, 'commerce.order.abandon_after_minutes' => 60]);
        ReservationWindow::assertOutlivesAbandonWindow();

        config(['inventory.reservation_ttl' => null]);
        ReservationWindow::assertOutlivesAbandonWindow();

        $this->addToAssertionCount(2);
    }

    /** The check is wired into the provider's boot, not only callable. */
    #[Test]
    public function commerce_refuses_to_boot_on_a_misordered_pair(): void
    {
        config(['inventory.reservation_ttl' => 30, 'commerce.order.abandon_after_minutes' => 60]);

        $this->expectException(ReservationTtlTooShortException::class);

        $this->app->register(CommerceServiceProvider::class, force: true);
    }
}
