<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Support;

use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * One package-default config key per domain, asserted on a plain testbench
 * boot. The suite's TestCase sets only DB config + morph aliases, so a key
 * being present here PROVES the domain provider's mergeConfigFrom() ran —
 * the false-green this kills is a provider that silently stops merging its
 * config while every other test passes because it sets the keys it needs
 * explicitly. Guards the DomainServiceProvider base-class swap (WI-6) and
 * every future provider edit.
 */
final class BootConfigMergeTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function domainDefaultKeys(): array
    {
        return [
            'agent' => ['agent.tools'],
            'commerce' => ['commerce.models'],
            'currency' => ['currency.enabled'],
            'inventory' => ['inventory.schedule'],
            'location' => ['location.models'],
            'logging (domain-log)' => ['domain-log.channels'],
            'media' => ['media.collections'],
            'payment' => ['payment.gateways'],
            'pricing' => ['pricing.default_tax_mode'],
            'purchasing' => ['purchasing.reference_prefix'],
            'shipping' => ['shipping.auto_create_shipment'],
            'storefront' => ['storefront.models'],
            'tax' => ['tax.jurisdictions'],
            'taxonomy' => ['taxonomy.models'],
            'translation' => ['translation.locales'],
            'variants' => ['variants.models'],
        ];
    }

    #[Test]
    #[DataProvider('domainDefaultKeys')]
    public function domain_config_merges_on_boot(string $key): void
    {
        // array_key_exists via a sentinel default — config('x', sentinel)
        // returning the sentinel means the key never merged. A null value
        // (currency.enabled ships null) still counts as merged.
        $sentinel = new \stdClass;

        $this->assertNotSame(
            $sentinel,
            config($key, $sentinel),
            "config('{$key}') is absent — its domain provider did not merge its config file on boot."
        );
    }
}
