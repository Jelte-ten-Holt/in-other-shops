<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Logging\Handlers\DatabaseLogHandler;
use InOtherShops\Pricing\Models\Price;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages\EditStubEditable;
use InOtherShops\Tests\Stubs\TestEditable;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * X1 through the real admin page: the price repeater is a Filament
 * relationship repeater, so Filament writes each Price row itself and the old
 * CreatePrice/UpdatePrice actions never ran — no audit row from either shop's
 * admin. With the events on the model hooks, every repeater save that changes
 * a price leaves one. Pricing logs to the commerce channel.
 */
final class PriceRepeaterAuditTest extends TestCase
{
    use BootsFilament {
        defineEnvironment as bootFilamentEnvironment;
    }
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $this->bootFilamentEnvironment($app);

        $app['config']->set('domain-log.channels.commerce', [
            ['handler' => DatabaseLogHandler::class, 'with' => []],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new GenericUser(['id' => 1]));
    }

    #[Test]
    public function adding_a_price_in_the_repeater_writes_an_audit_row(): void
    {
        $record = $this->editable();

        Livewire::test(EditStubEditable::class, ['record' => $record->getKey()])
            ->fillForm(['prices' => [['currency' => 'EUR', 'amount' => '40.00', 'minimum_quantity' => 1]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(4000, $record->prices()->sole()->amount);
        $this->assertSame(1, $this->rows('Price created%'));
    }

    #[Test]
    public function changing_a_price_writes_an_audit_row_and_an_unchanged_save_writes_none(): void
    {
        $record = $this->editable();
        $price = $this->priceOn($record, 4000);
        $baseline = $this->allRows();

        Livewire::test(EditStubEditable::class, ['record' => $record->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($baseline, $this->allRows(), 'saving the page without touching the price must leave no row');

        Livewire::test(EditStubEditable::class, ['record' => $record->getKey()])
            ->fillForm(["prices.record-{$price->id}.amount" => '45.00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(4500, $price->fresh()->amount);
        $this->assertSame(1, $this->rows('Price updated%'));
    }

    #[Test]
    public function removing_a_price_from_the_repeater_writes_a_deleted_row(): void
    {
        $record = $this->editable();
        $this->priceOn($record, 4000);

        Livewire::test(EditStubEditable::class, ['record' => $record->getKey()])
            ->fillForm(['prices' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, $record->prices()->count());
        $this->assertSame(1, $this->rows('Price deleted%'));
    }

    /**
     * Filament drops the hidden end-date field once the strikethrough is
     * cleared, so the elapsed date is still on the row when the model saves.
     * The model clears it; the old guard would have been a 500 here.
     */
    #[Test]
    public function clearing_a_strikethrough_whose_end_date_has_passed_saves(): void
    {
        $record = $this->editable();
        $price = $this->priceOn($record, 4000, compareAt: 5000);
        Price::query()->whereKey($price->id)->update(['compare_at_until' => now()->subHour()]);

        Livewire::test(EditStubEditable::class, ['record' => $record->getKey()])
            ->fillForm(["prices.record-{$price->id}.compare_at_amount" => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $price->refresh();
        $this->assertNull($price->compare_at_amount);
        $this->assertNull($price->compare_at_until);
    }

    /** The model's invariant reaches the admin as a field error, not a 500. */
    #[Test]
    public function a_strikethrough_not_above_the_price_is_a_field_error(): void
    {
        $record = $this->editable();
        $price = $this->priceOn($record, 4000);

        Livewire::test(EditStubEditable::class, ['record' => $record->getKey()])
            ->fillForm(["prices.record-{$price->id}.compare_at_amount" => '40.00'])
            ->call('save')
            ->assertHasFormErrors(["prices.record-{$price->id}.compare_at_amount"]);

        $this->assertNull($price->fresh()->compare_at_amount);
    }

    #[Test]
    public function an_end_date_in_the_past_is_a_field_error_when_set(): void
    {
        $record = $this->editable();
        $price = $this->priceOn($record, 4000, compareAt: 5000);

        Livewire::test(EditStubEditable::class, ['record' => $record->getKey()])
            ->fillForm(["prices.record-{$price->id}.compare_at_until" => now()->subDay()->format('Y-m-d H:i:s')])
            ->call('save')
            ->assertHasFormErrors(["prices.record-{$price->id}.compare_at_until"])
            ->assertHasNoFormErrors(["prices.record-{$price->id}.compare_at_amount"]);

        $this->assertNull($price->fresh()->compare_at_until);
    }

    /**
     * Found by the 2026-11-08 pathology review: the Omnibus rule compared the
     * strikethrough with the stored price on every save, and a running sale's
     * strikethrough is always above it, so no product on sale could be saved.
     */
    #[Test]
    public function a_product_on_sale_saves_untouched_and_leaves_no_row(): void
    {
        $record = $this->editable();
        $price = $this->priceOn($record, 4000, compareAt: 5000);
        $baseline = $this->allRows();

        Livewire::test(EditStubEditable::class, ['record' => $record->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(5000, $price->fresh()->compare_at_amount);
        $this->assertSame($baseline, $this->allRows());
    }

    /**
     * The hour between a strikethrough's end and the sweep. The stored date
     * has seconds the picker drops, so the save rewrites it; that must still
     * be an unrelated edit, not "setting a date in the past".
     */
    #[Test]
    public function an_unrelated_edit_saves_while_an_elapsed_strikethrough_waits_for_the_sweep(): void
    {
        $record = $this->editable();
        $price = $this->priceOn($record, 4000, compareAt: 5000);
        Price::query()->whereKey($price->id)->update(['compare_at_until' => now()->subMinutes(10)->setSecond(27)]);

        Livewire::test(EditStubEditable::class, ['record' => $record->getKey()])
            ->fillForm(["prices.record-{$price->id}.minimum_quantity" => 2])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, $price->fresh()->minimum_quantity);
        $this->assertSame(5000, $price->fresh()->compare_at_amount);
    }

    private function editable(): TestEditable
    {
        $record = TestEditable::create(['slug' => 'piece']);
        $record->setTranslation('name', 'en', 'Piece');
        $record->save();

        return $record;
    }

    private function priceOn(TestEditable $record, int $amount, ?int $compareAt = null): Price
    {
        return $record->prices()->create([
            'currency' => Currency::EUR,
            'amount' => $amount,
            'compare_at_amount' => $compareAt,
            'minimum_quantity' => 1,
        ]);
    }

    private function rows(string $message): int
    {
        return DB::table('domain_logs')->where('channel', 'commerce')->where('message', 'like', $message)->count();
    }

    private function allRows(): int
    {
        return DB::table('domain_logs')->count();
    }
}
