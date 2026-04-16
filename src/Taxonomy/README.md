# Taxonomy Domain

Hierarchical categories and flat typed tags, attachable to any model via polymorphic many-to-many relationships.

## Architecture

### Category Model

Hierarchical via self-referential `parent_id`. Implements `HasTranslations` — `name` and `description` are stored in the Translation domain's `translations` table.

**`categories` table:**

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `parent_id` | FK, nullable | self-referential, nullOnDelete |
| `slug` | string, unique | URL-safe identifier |
| `position` | unsigned int | ordering within parent (default 0) |
| `is_active` | boolean | visibility toggle (default true) |
| `timestamps` | | |

Index on `[parent_id, position]`.

**`categorizables` pivot table:** `category_id` (FK cascadeOnDelete), `categorizable_type`, `categorizable_id`, timestamps. Unique constraint on all three.

### Tag Model

Flat (no hierarchy). Optional `type` column for grouping (e.g., `color`, `material`, `season`). Implements `HasTranslations` — `name` is stored in the translations table.

**`tags` table:**

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `slug` | string, unique | URL-safe identifier |
| `type` | string, nullable | grouping key |
| `position` | unsigned int | ordering (default 0); consuming projects decide whether to surface ordering or treat tags as flat |
| `is_active` | boolean | visibility toggle (default true) |
| `timestamps` | | |

**`taggables` pivot table:** `tag_id` (FK cascadeOnDelete), `taggable_type`, `taggable_id`, timestamps. Unique constraint on all three.

### Contracts & Traits

```php
interface HasCategories
{
    public function categories(): MorphToMany;
}

interface HasTags
{
    public function tags(): MorphToMany;
}
```

`InteractsWithCategories` and `InteractsWithTags` traits provide the `morphToMany` relationships with timestamps.

### Filament Integration

**`TaxonomySchema`** — reusable form components:

- `categoriesSelect(relationship)` — returns a multi-select with search, preload, and translation-aware labels
- `tagsSelect(relationship)` — returns a multi-select with search, preload, and translation-aware labels

**`CategoryResource`** / **`TagResource`** — full Filament resources with CRUD pages. Categories use `TranslationSchema` for locale-tabbed name/description fields. Tags use `TranslationSchema` for locale-tabbed name. Category delete is disabled when children exist.

**`CategoriesRelationManager`** / **`TagsRelationManager`** — attach/detach UIs for edit pages. Support both attaching existing records and creating new ones inline. Not `final` — subclassable for project customization.

## Dependencies

- **Translation** — Category and Tag models implement `HasTranslations` for multilingual name/description

## Future

- Nested set or closure table for efficient ancestor/descendant queries on deep category trees
