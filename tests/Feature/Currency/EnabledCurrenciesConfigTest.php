<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Currency;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pin the `currency.enabled` config contract through the real provider
 * merge. Before v0.36.0 no `config/currency.php` existed at all, so the
 * key could never load and `Currency::enabled()` silently returned all
 * cases regardless of intent — the README documented behavior the package
 * couldn't deliver. These tests prove the merge happens and the filter
 * works end to end.
 */
final class EnabledCurrenciesConfigTest extends TestCase
{
    #[Test]
    public function shipped_default_is_null_and_enables_all_cases(): void
    {
        // Asserting the key EXISTS (not just that enabled() is permissive)
        // is the point: a provider that silently stops merging its config
        // would make config('currency.enabled') undefined, which enabled()
        // tolerates identically. array_key_exists separates the two.
        $this->assertTrue(array_key_exists('enabled', config('currency')));
        $this->assertNull(config('currency.enabled'));
        $this->assertSame(Currency::cases(), Currency::enabled());
    }

    #[Test]
    public function configured_subset_filters_enabled_cases(): void
    {
        config(['currency.enabled' => ['EUR', 'GBP']]);

        $this->assertSame([Currency::EUR, Currency::GBP], Currency::enabled());
    }

    #[Test]
    public function empty_array_is_treated_as_unrestricted(): void
    {
        config(['currency.enabled' => []]);

        $this->assertSame(Currency::cases(), Currency::enabled());
    }

    #[Test]
    public function dead_pricing_currencies_key_is_gone(): void
    {
        // Removed in v0.36.0 — it was never read by anything. If it
        // reappears, someone resurrected the split-brain.
        $this->assertFalse(array_key_exists('currencies', config('pricing')));
    }
}
