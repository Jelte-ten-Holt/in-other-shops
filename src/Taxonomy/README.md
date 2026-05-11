# Taxonomy Domain

Hierarchical categories and flat typed tags, attachable to any model via polymorphic many-to-many relationships.

## Architecture

### Category Model

Hierarchical via self-referential `parent_id`. Implements `HasTranslations` — `name` and `description` are stored in the Translation domain's `translations` table.

**`categories` table:**

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `parent_id` | FK, nullable | self-referential, restrictOnDelete (deletion of a parent with children is refused at the DB level) |
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

### Subtree Counts

`category_morph_counts` is a denormalized counter table maintained by `MaintainCategoryCounts` (an event subscriber) and `CategoryObserver` (model lifecycle). For every `(category_id, morph_alias)` pair, it stores the number of items of that alias attached to the category **or any of its descendants**.

**`category_morph_counts` table:**

| column | type | purpose |
|---|---|---|
| `category_id` | FK to categories, cascadeOnDelete | which category |
| `morph_alias` | string, max 64 | the consumer's morph alias (e.g. `product`, `bundle`, `content`) |
| `count` | unsigned int | items of this alias in the subtree |

Primary key `(category_id, morph_alias)`. Index on `(morph_alias, count)` for "categories with any X" queries.

The table is kept consistent by four events:

| event | trigger | effect |
|---|---|---|
| `CategoryAttached` | `AttachCategory` action | +1 on the category and every ancestor, for the model's morph alias |
| `CategoryDetached` | `DetachCategory` action when a pivot row was actually removed | -1 on the category and every ancestor |
| `CategoryMoved` | Category's `parent_id` changes (via Eloquent save) | shifts the moved category's totals from the old ancestor chain to the new one |
| `CategoryDeleted` | Category being deleted (no children — see Deleting Categories below) | decrements old ancestors by the deleted category's totals; row cascades away |

All updates are atomic single-statement upserts (`ON DUPLICATE KEY UPDATE` on MySQL/MariaDB, `ON CONFLICT DO UPDATE` on SQLite/Postgres).

This is **denormalized state, not a cache** — there is no TTL, and reads trust the table. If the invariant ever drifts (bulk pivot writes that bypass the actions, hard-deleted categorizables leaving orphan pivot rows, raw `Category::query()->update(['parent_id' => …])` bypassing the observer), use the recovery command.

### Filament Integration

**`TaxonomySchema`** — reusable form components:

- `categoriesSelect(relationship)` — returns a multi-select with search, preload, and translation-aware labels
- `tagsSelect(relationship)` — returns a multi-select with search, preload, and translation-aware labels

**`CategoryResource`** / **`TagResource`** — full Filament resources with CRUD pages. Categories use `TranslationSchema` for locale-tabbed name/description fields. Tags use `TranslationSchema` for locale-tabbed name. Category delete is disabled when children exist; when attached items exist, the delete confirmation modal names the count so the action isn't blind.

**`CategoriesRelationManager`** / **`TagsRelationManager`** — attach/detach UIs for edit pages. Support both attaching existing records and creating new ones inline. Not `final` — subclassable for project customization.

## Usage

### Attaching and detaching categories

**Always use the `AttachCategory` and `DetachCategory` actions** — never call `$model->categories()->attach($category)` or `->detach($category)` directly.

```php
use InOtherShops\Taxonomy\Actions\AttachCategory;
use InOtherShops\Taxonomy\Actions\DetachCategory;

(new AttachCategory)($product, $category);
(new DetachCategory)($product, $category);
```

The actions dispatch `CategoryAttached` / `CategoryDetached`, which `MaintainCategoryCounts` listens for to keep `category_morph_counts` accurate. Raw pivot writes (`attach`/`detach`/`sync`/`syncWithoutDetaching` on the relation, raw `DB::table('categorizables')` inserts, `Categorizable` factories in seeders) bypass the events and silently desync the counts table. The recovery command can rebuild after the fact, but it's a recovery — not the workflow.

Filament's `CategoriesRelationManager` dispatches the events from its `->after()` hooks rather than routing through the actions. Functionally equivalent for the counts invariant — but note that `CategoryDetached` will fire even on a UI-driven detach where the pivot row didn't exist (rare; the table only shows already-attached rows). The `DetachCategory` action gates dispatch on the affected row count; the relation manager does not.

### Registering the morph alias

Consumers must register their categorizable models in `Relation::morphMap()` so the `morph_alias` column stores a stable short key (`product`, `content`) rather than a fully-qualified class name. Without this, the counts still work but consumer-side queries (`'morph_alias' => 'product'`) won't match the stored FQCNs.

```php
// AppServiceProvider::boot()
Relation::morphMap([
    'product' => Product::class,
    'bundle' => Bundle::class,
    'content' => Content::class,
]);
```

### Listing the category tree for navigation

`ListCategoryTree` returns the active category tree filtered to nodes that have anything of the given morph aliases attached in their subtree. Each node has a `relevant_count` attribute (sum across the queried aliases) and its `children` relation set.

```php
use InOtherShops\Taxonomy\Actions\ListCategoryTree;

// Shop-side nav: categories with any product or bundle in their subtree
$shopTree = (new ListCategoryTree)(['product', 'bundle']);

// Content-side nav: categories with any content in their subtree
$contentTree = (new ListCategoryTree)(['content']);
```

Intermediate ancestors appear automatically — if `cyberpunk` (under `roleplaying`) has 3 content items attached, both surface in the tree (the parent inherits its descendants' subtree counts via the maintained table). Categories with `is_active = false` are filtered out, and their children promote up to fill the visible-tree root.

### Deleting categories

Two rules, enforced at different layers:

- **Children block deletion.** `parent_id` is `restrictOnDelete` at the DB level, and `CategoryObserver::deleting` throws `CategoryHasChildrenException` before the FK check ever runs (so the typed exception surfaces in PHP and `CategoryDeleted` is never dispatched for a refused delete). Reparent or delete the children first.
- **Attached items don't block deletion** but the Filament UI surfaces the count in a confirmation modal — `"3 items are attached to this category. Deleting it will detach them; the items themselves are not deleted. Are you sure?"`. Outside the Filament admin (tinker, scripts, controllers) there is no equivalent prompt; `categorizables.category_id` is `cascadeOnDelete`, so the pivot rows go silently and the attached models lose this tag.

### Recomputing counts

```bash
php artisan taxonomy:recompute-category-counts
```

Idempotent, transactional, rebuilds `category_morph_counts` from the categorizables pivot. Run after:

- First deploy of a new install (already handled by the migration backfill — re-run if you suspect anything went wrong).
- Any operation that bypassed the events (bulk pivot writes, raw category parent_id updates, seeders writing pivot rows directly).

**What the recompute cannot fix:** orphan pivot rows. Hard-deleting a categorizable model without first detaching its categories leaves `categorizables` rows pointing at a model that no longer exists. The recompute aggregates the pivot, so it counts those orphans as real attachments. Consumers that hard-delete categorizable models should detach categories first (or call `DetachCategory` from a model `deleting` hook).

## Dependencies

- **Translation** — Category and Tag models implement `HasTranslations` for multilingual name/description
