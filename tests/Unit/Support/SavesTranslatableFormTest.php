<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Unit\Support;

use InOtherShops\Support\Filament\FormSync;
use InOtherShops\Support\Filament\SavesTranslatableForm;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * T-S1 — the SavesTranslatableForm trait contract, exercised without booting
 * Filament (the full create/edit render round-trip is verified manually in a
 * consumer panel per the ticket).
 *
 * The load-bearing guarantee: the manual-sync save runs against the DEHYDRATED
 * form state Filament hands to the before-save hook, NOT the page's raw
 * `$this->data`. That is what closes the RichEditor/TipTap trap — raw state is a
 * document array, dehydrated state is the stored HTML string.
 */
final class SavesTranslatableFormTest extends TestCase
{
    #[Test]
    public function the_save_runs_against_the_dehydrated_state_not_the_pages_raw_data(): void
    {
        $captured = null;
        $sync = new FormSync(
            keys: ['translations'],
            fill: null,
            save: function ($record, array $data) use (&$captured): void {
                $captured = $data;
            },
        );

        $page = new FakeTranslatablePage(new stdClass, [$sync]);
        // The page's raw form state — a TipTap-style array that would corrupt the
        // save if it were used. It must be ignored.
        $page->data = ['translations' => ['type' => 'doc', 'content' => []]];

        // Filament hands the DEHYDRATED state (HTML string) to the before-save hook.
        $columns = $page->beforeSave(['name' => 'Widget', 'translations' => ['en' => '<p>Hi</p>']]);

        // Sync-owned keys are stripped from the record's own column data.
        $this->assertSame(['name' => 'Widget'], $columns);

        // The save replays the dehydrated state captured before-save — NOT $page->data.
        $page->runAfterSave();
        $this->assertSame(['name' => 'Widget', 'translations' => ['en' => '<p>Hi</p>']], $captured);
    }

    #[Test]
    public function every_participants_keys_are_stripped_from_the_column_data(): void
    {
        $page = new FakeTranslatablePage(new stdClass, [
            new FormSync(['translations'], null, fn () => null),
            new FormSync(['_media'], null, fn () => null),
            new FormSync(['_values'], null, fn () => null),
        ]);

        $columns = $page->beforeSave([
            'name' => 'X',
            'translations' => 1,
            '_media' => 2,
            '_values' => 3,
        ]);

        $this->assertSame(['name' => 'X'], $columns);
    }

    #[Test]
    public function the_fill_step_merges_each_participants_fields_into_the_edit_state(): void
    {
        $page = new FakeTranslatablePage(new stdClass, [
            new FormSync(
                keys: ['translations'],
                fill: fn ($record, array $data): array => array_merge($data, ['translations' => ['en' => 'Filled']]),
                save: fn () => null,
            ),
            new FormSync(
                keys: ['_media'],
                fill: fn ($record, array $data): array => array_merge($data, ['_media' => ['cover']]),
                save: fn () => null,
            ),
        ]);

        $filled = $page->beforeFill(['name' => 'X']);

        $this->assertSame(['name' => 'X', 'translations' => ['en' => 'Filled'], '_media' => ['cover']], $filled);
    }
}

/**
 * A minimal stand-in for a Filament Create/Edit page that uses the trait,
 * exposing its protected lifecycle hooks for direct assertion.
 */
final class FakeTranslatablePage
{
    use SavesTranslatableForm;

    public object $record;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @param list<FormSync> $schemas */
    public function __construct(object $record, private array $schemas)
    {
        $this->record = $record;
    }

    protected function syncSchemas(): array
    {
        return $this->schemas;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function beforeSave(array $data): array
    {
        return $this->mutateFormDataBeforeSave($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function beforeFill(array $data): array
    {
        return $this->mutateFormDataBeforeFill($data);
    }

    public function runAfterSave(): void
    {
        $this->afterSave();
    }
}
