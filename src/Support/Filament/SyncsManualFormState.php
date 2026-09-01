<?php

declare(strict_types=1);

namespace InOtherShops\Support\Filament;

use Filament\Resources\Pages\EditRecord;

/**
 * For Filament Create/Edit pages that feed form state to manual-sync schemas
 * (TranslationSchema, MediaSchema, InventorySchema, …) from their
 * afterCreate()/afterSave() hooks.
 *
 * Those schemas must receive the DEHYDRATED form state, never the raw
 * `$this->data`. For most components the two are identical, but not all: a
 * Filament 5 RichEditor's raw state is a TipTap document (a PHP array), while
 * its dehydrated state is the HTML string the `translations.value` column
 * stores. Passing `$this->data` there persists the array and throws
 * "Array to string conversion" (and, without a wrapping transaction, leaves an
 * orphan row whose slug then trips the unique rule on re-submit).
 *
 * Filament already hands the dehydrated state to mutateFormDataBeforeCreate()
 * and mutateFormDataBeforeSave(). This trait captures it there and replays it to
 * the after-hooks, so manual sync runs against dehydrated values without a
 * second getState() call (which would re-run validation). If a page never calls
 * rememberDehydratedFormState() it falls back to getState(), so the trait is
 * still correct when only half-wired.
 */
trait SyncsManualFormState
{
    /** @var array<string, mixed>|null */
    private ?array $dehydratedFormState = null;

    /**
     * Call from mutateFormDataBeforeCreate()/mutateFormDataBeforeSave(). Returns
     * the data unchanged so it can wrap the existing return:
     * `return $this->rememberDehydratedFormState($data);`
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function rememberDehydratedFormState(array $data): array
    {
        $this->dehydratedFormState = $data;

        return $data;
    }

    /**
     * The dehydrated form state to feed manual-sync schemas in
     * afterCreate()/afterSave().
     *
     * @return array<string, mixed>
     */
    protected function manualFormState(): array
    {
        return $this->dehydratedFormState ?? $this->form->getState();
    }

    /**
     * Re-hydrate the form from the saved record. Call it LAST in `afterSave()`
     * on every Edit page that runs a manual-sync `saveFormData`.
     *
     * Filament neither refills the form nor redirects after `save()` unless the
     * page says so, so the Livewire state that produced this save is still the
     * state the next save will read. For a manual-sync schema that is wrong in
     * two ways: a one-shot field (`_stock.adjustment_quantity`) is applied
     * again on the next save, and a media row that was created by this save
     * still has no `media_id` in the form, so the next save deletes and
     * re-creates it. `fillForm()` re-runs `mutateFormDataBeforeFill()` against
     * the record `save()` just updated, which is exactly the state a fresh page
     * load would show.
     *
     * Edit pages only — `CreateRecord::fillForm()` fills defaults with no
     * record; create pages redirect anyway. A no-op elsewhere so
     * {@see SavesTranslatableForm} can call it unconditionally.
     */
    protected function refillManualFormState(): void
    {
        if ($this instanceof EditRecord) {
            $this->fillForm();
        }
    }
}
