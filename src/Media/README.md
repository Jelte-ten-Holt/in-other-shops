# Media Domain

File attachments for any model. Handles storage, retrieval, and organization of uploaded files, external URLs, and embeds.

## Architecture

### Pivot-based attachment (`mediables`)

Uses `morphToMany` with an explicit **`Mediable` pivot model**. The `media` table is a pure file registry. The `mediables` pivot carries all attachment context (collection, position, morph data).

**`media` table** — the file record:

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `disk` | string, nullable | filesystem disk (null for external/embed) |
| `path` | string, nullable | path on disk (null for external/embed) |
| `filename` | string | original filename |
| `mime_type` | string | MIME type |
| `size` | unsigned int | file size in bytes |
| `alt` | string, nullable | alt text for images |
| `type` | string | `upload`, `embed`, `external` (see Media Types) |
| `url` | string, nullable | source URL for `embed`/`external` types |
| `timestamps` | | |

**`mediables` pivot table** — the attachment context:

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `media_id` | FK | the file (cascadeOnDelete) |
| `mediable_type` | string | morph type (product, category, etc.) |
| `mediable_id` | bigint | morph ID |
| `collection` | string | collection key (`images`, `documents`) |
| `position` | unsigned int | ordering within collection, 0-indexed |
| `is_cover` | bool | designates this row as the cover image; at most one row across all collections per parent |
| `timestamps` | | |

Unique constraint on `[media_id, mediable_type, mediable_id, collection]`.

### Media model

Pure file record. Knows its disk, path, mime type, size. The `deleting` hook removes the file from disk for Upload types only. Does **not** know what it's attached to — that's the pivot's job.

A single `Media` record can be attached to multiple parents via separate pivot rows (e.g., the same product image reused on a campaign page).

### Mediable pivot model

The attachment record. Extends `MorphPivot`. Knows which media is attached to which parent, in which collection, at which position.

- `media()` — BelongsTo Media
- `isImage(): bool` — delegates to media's mime type
- `url(): string` — delegates to media

### Media Types (`MediaType` enum)

The `type` column on `media` supports three kinds:

- **`upload`** — file stored on disk. `url()` resolves via `Storage::disk()->url()`. `deleting` hook removes the file.
- **`external`** — external URL (e.g., CDN-hosted image). `url()` returns the stored `url` column. No file to delete.
- **`embed`** — embeddable content (e.g., YouTube URL). `url()` returns the stored `url` column.

### Collections

Config-driven groupings defined in `config/media.php`:

```php
'collections' => [
    'images' => [
        'label' => 'Images',
    ],
    'documents' => [
        'label' => 'Documents',
    ],
],
```

The `collection` string on the `mediables` pivot references these keys.

**Cover image convention:** `$model->coverImage()` returns the row marked `is_cover = true` (across any collection), falling back to the first item in the `images` collection. The `MediaSchema` repeater enforces that at most one row per parent is marked as cover — `saveFormData` normalizes the form data before persisting.

### Contract & Trait

```php
interface HasMedia
{
    public function media(): MorphToMany;
    public function mediaInCollection(string $collection): Collection;
    public function firstMedia(?string $collection = null): ?Media;
    public function coverImage(): ?Media;
}
```

`InteractsWithMedia` trait implements all methods. The `media()` relationship uses `Mediable` as the pivot model, includes `collection` and `position` pivot columns, and orders by position.

### Filament Integration

**`MediaSchema`** — form components following the `TranslationSchema` pattern (non-relationship-bound Repeater with manual fill/save):

- `mediaRepeater(collection)` — returns a Repeater at state path `_media.{collection}`
- `fillFormData(record, data)` — loads media into form state (call from `mutateFormDataBeforeFill`)
- `saveFormData(record, data)` — syncs form state back to database (call from `afterCreate`/`afterSave`)

**`MediaRelationManager`** — full tabbed UI for managing media on edit pages.

### Actions

- **`StoreMedia`** — stores a file on disk, creates a `Media` record, attaches via pivot
- **`DeleteMedia`** — deletes the `Media` record (cascade handles pivot, `deleting` hook handles file for uploads)

### Events

- **`MediaStored`** — dispatched when a file is stored and attached. Carries the `Media` model and the collection name.
- **`MediaDeleted`** — dispatched after a media record is deleted. Carries primitives (`mediaId`, `filename`, `MediaType`) since the model no longer exists.

## Future

- Config-driven validation rules per collection (accept mimes, max files, max size)
- `StoreExternalMedia` / `StoreEmbedMedia` actions for non-upload types
- `DetachMedia` action (remove from one parent without deleting the file)
