<?php

declare(strict_types=1);

namespace InOtherShops\Translation\Filament;

use InOtherShops\Translation\Contracts\HasTranslations;
use Filament\Forms\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TranslationSchema
{
    /**
     * @param  array<string, Component>  $fields
     */
    public static function fields(
        array $fields,
        ?string $slugSource = null,
        ?string $slugTarget = null,
    ): Tabs {
        $locales = config('translation.locales', ['en']);
        $default = config('translation.default', 'en');

        $tabs = array_map(
            fn (string $locale) => self::buildTab($locale, $fields, $locale === $default, $slugSource, $slugTarget),
            $locales,
        );

        return Tabs::make('translations')
            ->schema($tabs)
            ->columnSpanFull();
    }

    /** @return array<string, array<string, string>> */
    public static function fillFormData(Model&HasTranslations $record): array
    {
        $locales = config('translation.locales', ['en']);
        $data = [];

        foreach ($locales as $locale) {
            foreach ($record->translatableFields() as $field) {
                $data[$locale][$field] = $record->translations
                    ->where('locale', $locale)
                    ->where('field', $field)
                    ->first()
                    ?->value ?? '';
            }
        }

        return ['translations' => $data];
    }

    /** @param  array<string, mixed>  $formData */
    public static function saveFormData(Model&HasTranslations $record, array $formData): void
    {
        $translations = $formData['translations'] ?? [];

        foreach ($translations as $locale => $fields) {
            foreach ($fields as $field => $value) {
                if (! in_array($field, $record->translatableFields(), true)) {
                    continue;
                }

                if ($value === '' || $value === null) {
                    $record->translations()
                        ->where('locale', $locale)
                        ->where('field', $field)
                        ->delete();

                    continue;
                }

                // The value column is a string. A non-scalar here means the
                // caller passed raw form state, not the dehydrated state — a
                // Filament RichEditor's raw state is a TipTap document (array),
                // whose dehydrated form is the HTML string this column stores.
                // Fail loud with the fix rather than silently corrupting the row
                // (or throwing an opaque "Array to string conversion").
                if (! is_scalar($value)) {
                    throw new InvalidArgumentException(sprintf(
                        'TranslationSchema cannot persist a non-scalar value for field "%s" (locale "%s"): got %s. '
                        .'Feed afterCreate()/afterSave() the dehydrated form state — use the SyncsManualFormState '
                        .'trait or $this->form->getState() — not the raw $this->data.',
                        $field,
                        $locale,
                        get_debug_type($value),
                    ));
                }

                $record->translations()->updateOrCreate(
                    ['locale' => $locale, 'field' => $field],
                    ['value' => $value],
                );
            }
        }

        $record->unsetRelation('translations');
    }

    /**
     * @param  array<string, Component>  $fields
     */
    private static function buildTab(
        string $locale,
        array $fields,
        bool $isDefault,
        ?string $slugSource,
        ?string $slugTarget,
    ): Tab {
        $tabFields = self::buildTabFields($locale, $fields, $isDefault, $slugSource, $slugTarget);

        $tab = Tab::make(strtoupper($locale))
            ->schema($tabFields);

        if ($isDefault) {
            $tab->icon('heroicon-s-star');
        }

        return $tab;
    }

    /**
     * @param  array<string, Component>  $fields
     * @return array<Component>
     */
    private static function buildTabFields(
        string $locale,
        array $fields,
        bool $isDefault,
        ?string $slugSource,
        ?string $slugTarget,
    ): array {
        $tabFields = [];

        foreach ($fields as $name => $component) {
            $clone = clone $component;
            $statePath = "translations.{$locale}.{$name}";
            $clone->statePath($statePath);

            // The slug is derived from the default-locale source field while the
            // record is being created, and never touched by that field again: a
            // published record's slug is its URL, and retitling it must not move
            // every inbound link. An editor who means to rename edits the slug
            // field itself.
            if ($isDefault && $slugSource !== null && $slugTarget !== null && $name === $slugSource) {
                $clone->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, ?string $state, string $operation) use ($slugTarget): void {
                        if ($operation === 'create') {
                            $set($slugTarget, Str::slug($state ?? ''));
                        }
                    });
            }

            $tabFields[] = $clone;
        }

        return $tabFields;
    }
}
