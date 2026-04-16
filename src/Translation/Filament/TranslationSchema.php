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

            if ($isDefault && $slugSource !== null && $slugTarget !== null && $name === $slugSource) {
                $clone->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set($slugTarget, Str::slug($state ?? '')));
            }

            $tabFields[] = $clone;
        }

        return $tabFields;
    }
}
