# Translation Domain

Multilingual toolkit for the package. Ships two complementary primitives, each suited to a different shape of multilingualism:

- **Column-translations** (`HasTranslations` + `translations` table) — for short labels on a single referent. One model row, multiple locale-specific values for declared fields. Use this when the entity is a single concept that should carry multilingual labels (categories, tags, option values).
- **Row-translations** (`HasLocaleGroup` + `locale_groups` table) — for long-form, independently-published content where each language is its own first-class row. Each translation has its own slug, draft state, and lifecycle; siblings are linked via a polymorphic group. Use this for editorial content (articles, videos, artwork) and for product editions where a German-market and English-market product are genuinely different SKUs that may share inventory.

Both primitives can coexist on the same project; pick whichever fits the model. A model can in principle adopt both, but in practice each model picks one.

---

## When to use which

| Question | Pick |
|---|---|
| Does each language version need its own slug, draft state, hero image? | Row-translations (`HasLocaleGroup`) |
| Is the entity a single concept that needs multilingual labels? | Column-translations (`HasTranslations`) |
| Do siblings need to share inventory across locales? | Row-translations (set `shares_inventory=true` on the group) |
| Is the field a short string (name, label)? | Column-translations |
| Is the field long-form prose (body, description)? | Row-translations preferred (each row owns its body natively); column-translations works but the row's *other* fields likely also want to vary by language |

---

## Design Decision: Why No Fallback Columns?

Many translation systems treat one locale as the default and store it directly on the model, with other locales in a separate table. This creates confusion:

- Where does the "real" name live? On the model or in translations?
- What happens when the default locale changes?
- European shops often have multiple equal languages (Belgian shops: nl, fr, de). Picking a "main" language is arbitrary.

By storing **all** text in the translations table, the mental model is simple: structural data on the model, text in translations. Always. No exceptions.

The trade-off is that Translation becomes a foundational dependency for any domain with user-facing strings. A single-locale shop still uses the Translation domain — it just has one locale configured.

---

## Model

### Translation (`Models/Translation.php`)

| Field | Type | Description |
|-------|------|-------------|
| `translatable_type` | `string` | Morph type (e.g. `'category'`, `'option'`) |
| `translatable_id` | `unsignedBigInteger` | Morph ID |
| `locale` | `string` | Locale code (e.g. `'en'`, `'nl'`, `'de'`) |
| `field` | `string` | Field name (e.g. `'name'`, `'description'`) |
| `value` | `text` | The translated content |

**Indexes:**

- Composite unique: `[translatable_type, translatable_id, locale, field]` — one value per field per locale per model.
- Index on `[translatable_type, translatable_id, locale]` — efficient eager loading for a single locale.

**Morph alias:** `'translation'`

---

## Configuration (`config/translation.php`)

```php
return [
    // Available locales in the system
    'locales' => ['en'],

    // Default locale used when no locale is specified
    'default' => 'en',

    // Fallback locale when a translation is missing
    // Set to null to disable fallback (return null for missing translations)
    'fallback' => 'en',
];
```

A single-locale shop sets `locales` to `['en']` and everything works — there's just one row per field per model. The system cost is a join, not a conceptual shift.

---

## Contracts

### `HasTranslations`

For any model that has translatable text fields.

```php
interface HasTranslations
{
    /**
     * The fields that are translatable.
     *
     * @return array<string>
     */
    public function translatableFields(): array;

    public function translations(): MorphMany;
}
```

Each model declares which fields are translatable. This serves as both documentation and validation — the domain can reject attempts to translate a field that isn't in the list.

---

## Concerns

### `InteractsWithTranslations`

Implements the `HasTranslations` contract and provides locale-aware accessors.

```php
trait InteractsWithTranslations
{
    public function translations(): MorphMany
    {
        return $this->morphMany(Translation::class, 'translatable');
    }

    /**
     * Get a translated value for the given field and locale.
     * Falls back to the configured fallback locale if the translation is missing.
     */
    public function translated(string $field, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        $value = $this->translations
            ->where('locale', $locale)
            ->where('field', $field)
            ->first()
            ?->value;

        if ($value === null && ($fallback = config('translation.fallback')) && $fallback !== $locale) {
            $value = $this->translations
                ->where('locale', $fallback)
                ->where('field', $field)
                ->first()
                ?->value;
        }

        return $value;
    }

    /**
     * Get all translations for a field, keyed by locale.
     *
     * @return array<string, string>
     */
    public function translationsFor(string $field): array
    {
        return $this->translations
            ->where('field', $field)
            ->pluck('value', 'locale')
            ->all();
    }

    /**
     * Set a translation for the given field and locale.
     */
    public function setTranslation(string $field, string $locale, string $value): void
    {
        $this->translations()->updateOrCreate(
            ['locale' => $locale, 'field' => $field],
            ['value' => $value],
        );
    }

    /**
     * Set translations for multiple fields at once.
     *
     * @param array<string, string> $translations field => value
     */
    public function setTranslations(string $locale, array $translations): void
    {
        foreach ($translations as $field => $value) {
            $this->setTranslation($field, $locale, $value);
        }
    }
}
```

### Eager Loading

The trait works against the already-loaded `translations` relation (no extra queries) when properly eager-loaded. The Storefront and Filament layers should always eager-load translations:

```php
Category::with(['translations' => fn ($q) => $q->where('locale', app()->getLocale())])
    ->get();
```

For admin contexts (Filament), eager-load all locales:

```php
Category::with('translations')->get();
```

A scope on the trait simplifies this:

```php
public function scopeWithTranslations(Builder $query, ?string $locale = null): Builder
{
    if ($locale) {
        return $query->with(['translations' => fn ($q) => $q->where('locale', $locale)]);
    }

    return $query->with('translations');
}
```

---

## Row-translations: `LocaleGroup` + `HasLocaleGroup`

For models where each language is its own row, `LocaleGroup` is the join key linking siblings together. The group itself carries no per-locale data — meaningful fields live on member rows.

### Model

`LocaleGroup` has just `id`, `shares_inventory` (boolean), and timestamps. Members live on each consumer model via two columns: a nullable `locale_group_id` (FK to `locale_groups`) and a nullable `locale` string. A null group means the row is monolingual; a null locale is a backfill-transient state and should always be populated by application logic.

`shares_inventory` is consulted by the Inventory domain's `AdjustStock` action: when `true`, stock writes propagate transactionally to all members of the group (used for shared-physical-item commerce — a plushy toy that's "the same product" across languages). When `false` (the default), each member's stock is independent (book editions with separate ISBNs and print runs).

**Morph alias:** `'locale_group'`

### Contract

```php
interface HasLocaleGroup
{
    public function localeGroup(): BelongsTo;
    public function siblings(): Builder;            // same-class rows in the same group, excluding self
    public function inLocale(string $locale): ?self;
    public function locale(): string;
}
```

### Trait

`InteractsWithLocaleGroup` implements the contract plus two scopes:

- `scopeForLocale($query, string $locale)` — filter to a single locale.
- `scopeMonolingual($query)` — filter to ungrouped rows.

### Consumer migration shape

Each consumer model adds:

```php
$table->foreignId('locale_group_id')->nullable()->constrained()->nullOnDelete();
$table->string('locale', 10)->nullable();
$table->index(['locale_group_id', 'locale']);
$table->unique(['locale_group_id', 'locale']);  // one row per locale per group
```

Slug uniqueness should be `unique(slug, locale)` rather than the bare `unique(slug)` so the locale prefix in URLs disambiguates same-slug translations cleanly.

### Sibling resolution

`siblings()` returns an Eloquent **Builder** rather than a relation, because "rows in the same group excluding self" can't be expressed as a clean `HasMany`. Call `->get()` to materialise:

```php
$translations = $content->siblings()->get();
```

For a specific locale, prefer `inLocale()`:

```php
$german = $content->inLocale('de');  // ?Content
```

Returns `$content` itself when the locale matches the row's own; returns `null` for monolingual rows when the locale differs, or for grouped rows when no sibling exists in that locale.

---

## Filament Integration

### `TranslationSchema`

Provides locale-tabbed form components for any translatable model. Each configured locale gets a tab, and each translatable field gets an input per tab.

```php
TranslationSchema::simpleFields(
    fields: ['name', 'description'],
    descriptionFields: ['description'],  // uses Textarea instead of TextInput
)
```

This returns a Tabs component with one tab per locale, each containing the specified fields. The resource's `afterCreate`/`afterSave` hooks persist the translations via `setTranslations()`.

---

## Impact on Other Domains

### Domains that will depend on Translation

| Domain | HasTranslations fields |
|--------|-------------------|
| **Taxonomy** | Category: `name`, `description`. Tag: `name`. |
| **Option** | Option: `name`. OptionValue: `label`. |
| **Pricing** | PriceList: `name`, `description`. |
| **Storefront** | Reads translations via the trait — no schema changes needed. |

### Migration path for existing domains

When Translation is implemented, existing domains need to:

1. Drop their text columns (`name`, `description`, etc.) from migrations.
2. Implement `HasTranslations` and use `InteractsWithTranslations`.
3. Seed translations instead of setting model attributes directly.
4. Update Filament resources to use `TranslationSchema`.
5. Update tests to create translations.

This is a breaking change for existing domains but should be done early, before more domains are built on the old pattern.

---

## Search

HasTranslations fields are no longer directly queryable (`WHERE name LIKE '%boot%'` won't work). Two strategies:

### 1. Join-based search (simple, no extra infrastructure)

```sql
SELECT p.* FROM products p
JOIN translations t ON t.translatable_id = p.id
  AND t.translatable_type = 'product'
  AND t.field = 'name'
  AND t.locale = 'nl'
WHERE t.value LIKE '%laars%'
```

The trait can provide a scope:

```php
public function scopeWhereTranslation(
    Builder $query, string $field, string $operator, string $value, ?string $locale = null
): Builder
```

This works for basic filtering. For full-text search across multiple fields, it gets expensive.

### 2. Search engine (recommended for production)

Laravel Scout with Meilisearch or Typesense. The `toSearchableArray()` method on the model pulls from translations. The search engine handles relevance, typos, and multilingual stemming far better than SQL LIKE.

The Translation domain doesn't implement search itself — it provides the data. The project or Storefront domain decides how search works.

---

## Sorting

Sorting by translated fields requires a join:

```php
public function scopeOrderByTranslation(
    Builder $query, string $field, string $direction = 'asc', ?string $locale = null
): Builder
```

This is provided by the trait as a convenience scope. Performance is acceptable with proper indexing on the composite `[translatable_type, translatable_id, locale, field]` index.

---

## Updated Domain Dependency Graph

```
Currency ─────── (independent, foundational)
Translation ──── (independent, foundational)
Pricing ──────── depends on Currency, Translation
Taxonomy ─────── depends on Translation
Option ───────── depends on Translation
Location ─────── (independent)
Media ────────── (independent, skeleton)
Commerce ─────── depends on Location + Currency
Customer ─────── depends on Location
Storefront ───── depends on Pricing + Taxonomy
```

---

## File Structure

```
app/Domains/Translation/
├── TranslationServiceProvider.php
├── Contracts/
│   └── HasTranslations.php
├── Concerns/
│   └── InteractsWithTranslations.php
├── Models/
│   └── Translation.php
├── Database/
│   └── Migrations/
│       └── create_translations_table.php
├── Filament/
│   └── TranslationSchema.php
└── README.md
```
