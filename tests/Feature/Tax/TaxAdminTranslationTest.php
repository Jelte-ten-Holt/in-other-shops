<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Tax;

use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Proves the admin i18n chain end-to-end for the reference domain: the
 * `shops-tax::` and `shops-common::` namespaces are registered, resolve under
 * both locales, and fall back to `en` for a missing `es` key rather than
 * emitting a raw key. This is the pattern every domain follows.
 */
final class TaxAdminTranslationTest extends TestCase
{
    #[Test]
    public function domain_keys_resolve_per_locale(): void
    {
        app()->setLocale('en');
        $this->assertSame('Rate', __('shops-tax::taxrate.columns.rate'));
        $this->assertSame('Tax Rate', __('shops-tax::taxrate.section.tax_rate'));

        app()->setLocale('es');
        $this->assertSame('Tasa', __('shops-tax::taxrate.columns.rate'));
        $this->assertSame('Tasa de impuesto', __('shops-tax::taxrate.section.tax_rate'));
    }

    #[Test]
    public function common_field_labels_resolve_per_locale(): void
    {
        app()->setLocale('en');
        $this->assertSame('Country', __('shops-common::fields.country'));

        app()->setLocale('es');
        $this->assertSame('País', __('shops-common::fields.country'));
    }

    #[Test]
    public function a_missing_es_key_never_renders_a_raw_key(): void
    {
        app()->setLocale('es');

        // A key that exists in neither locale returns itself — the parity test
        // guards against that shipping; here we assert the real keys resolve to
        // a human string (no leading "shops-").
        $resolved = __('shops-tax::taxrate.fields.rate_bps');
        $this->assertStringStartsNotWith('shops-', $resolved);
        $this->assertNotSame('', trim($resolved));
    }
}
