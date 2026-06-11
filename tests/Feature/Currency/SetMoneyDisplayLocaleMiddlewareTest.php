<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Currency;

use Illuminate\Support\Facades\Route;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Currency\Http\Middleware\SetMoneyDisplayLocale;
use InOtherShops\Currency\Support\DisplayLocale;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The admin-panel money convention contract: inside a request wrapped by
 * SetMoneyDisplayLocale, server-rendered money text follows the browser's
 * Accept-Language (matching what the browser does to type="number" inputs
 * natively), while the application locale — and with it the panel's UI
 * language — stays untouched. The override never leaks past the request.
 */
final class SetMoneyDisplayLocaleMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/money', fn (): array => [
            'formatted' => str_replace(["\u{00A0}", "\u{202F}"], ' ', Currency::EUR->format(1250)),
            'app_locale' => app()->getLocale(),
        ])->middleware(SetMoneyDisplayLocale::class);
    }

    #[Test]
    public function german_accept_language_renders_comma_convention(): void
    {
        $response = $this->getJson('/_test/money', ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8']);

        $response->assertOk()->assertJson(['formatted' => '12,50 €']);
    }

    #[Test]
    public function english_accept_language_renders_period_convention(): void
    {
        $response = $this->getJson('/_test/money', ['Accept-Language' => 'en-US,en;q=0.9']);

        $response->assertOk()->assertJson(['formatted' => '€12.50']);
    }

    #[Test]
    public function app_locale_and_ui_language_are_untouched(): void
    {
        $this->app->setLocale('en');

        $response = $this->getJson('/_test/money', ['Accept-Language' => 'de-DE,de;q=0.9']);

        // The money convention flips, the app locale does not — Filament UI
        // translations must never follow the money display locale.
        $response->assertOk()->assertJson(['app_locale' => 'en']);
    }

    #[Test]
    public function override_is_cleared_after_the_request(): void
    {
        $this->getJson('/_test/money', ['Accept-Language' => 'de-DE,de;q=0.9'])->assertOk();

        $this->app->setLocale('en');

        $this->assertSame('en', DisplayLocale::resolve());
        $this->assertSame('€12.50', Currency::EUR->format(1250));
    }

    #[Test]
    public function missing_header_falls_back_to_the_app_locale_convention(): void
    {
        $this->app->setLocale('en');

        $response = $this->getJson('/_test/money');

        $response->assertOk()->assertJson(['formatted' => '€12.50']);
    }
}
