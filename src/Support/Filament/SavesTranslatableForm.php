<?php

declare(strict_types=1);

namespace InOtherShops\Support\Filament;

/**
 * Drives the manual-sync dance for a translatable Filament Create/Edit page:
 * fill the sidecar fields (translations, media, option values) into Edit form
 * state, strip them from the record's own columns before the row is written,
 * then persist them afterwards. A page declares only its participants via
 * {@see self::syncSchemas()}; this trait wires every lifecycle hook.
 *
 * Correctness — the save runs against the DEHYDRATED form state captured in the
 * before-save hook (via {@see SyncsManualFormState}), NOT the page's raw
 * `$this->data`. For a RichEditor the two differ: raw state is a TipTap document
 * array, dehydrated state is the HTML string the `translations.value` column
 * stores. Passing raw state throws "Array to string conversion" (and orphans a
 * row); this trait closes that gap for every page that uses it.
 *
 * The same trait serves Create and Edit pages: Filament calls
 * mutateFormDataBeforeCreate()/afterCreate() on Create pages and
 * mutateFormDataBeforeFill()/mutateFormDataBeforeSave()/afterSave() on Edit
 * pages, so the unused hooks simply never fire.
 */
trait SavesTranslatableForm
{
    use SyncsManualFormState;

    /**
     * The manual-sync participants for this page.
     *
     * @return list<FormSync>
     */
    abstract protected function syncSchemas(): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach ($this->syncSchemas() as $sync) {
            if ($sync->fill !== null) {
                $data = ($sync->fill)($this->record, $data);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->captureThenStrip($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->captureThenStrip($data);
    }

    protected function afterCreate(): void
    {
        $this->runSaveSyncs();
    }

    protected function afterSave(): void
    {
        $this->runSaveSyncs();
    }

    /**
     * Remember the full dehydrated state (for the after-hooks) before stripping
     * the sync-owned keys off the record's own column data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function captureThenStrip(array $data): array
    {
        $this->rememberDehydratedFormState($data);

        foreach ($this->syncSchemas() as $sync) {
            foreach ($sync->keys as $key) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    private function runSaveSyncs(): void
    {
        $state = $this->manualFormState();

        foreach ($this->syncSchemas() as $sync) {
            ($sync->save)($this->record, $state);
        }
    }
}
