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
| `width`, `height` | unsigned int, nullable | intrinsic pixel dimensions, EXIF-orientation-corrected; filled at save from the file header (uploads on local disks only) |
| `variants` | json, nullable | the WebP ladder: `null` = never attempted, `{}` = attempted and nothing produced (reason logged), else `{"400": {path, width, height}, …}` |
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

#### The replace invariant

**Writing a new `path` to an existing upload row is all a caller has to do.** The model owns the rest:

- `saving` — when the row already exists, `type` is `upload`, `path` is dirty and the file is on the disk, `filename`, `mime_type` and `size` are re-read from storage. Anything stale about the replaced file is gone before the row is written.
- `saved` — the *replaced* file is deleted, inside `DB::afterCommit()`, unless another `media` row points at the same `disk` + `path`.

It lives here rather than in the admin form because there are two upload surfaces and each had its own half of the same bug. `MediaSchema`'s repeater kept `media_id` in a hidden field and then never read `$item['path']`, so a swap uploaded the new file, orphaned it, and left the site serving the old image — silently, with no error anywhere. `MediaRelationManager`'s Edit action did write `path`, but `enrichFormData` only runs on Create, so the metadata went on describing the replaced file and the old file stayed on disk. One rule on the model covers both, and any third surface that ever writes `path`.

Two deliberate edges:

- **Updates only.** On an insert the creator owns the metadata — `StoreMedia` records the *client's* original filename, which the stored path's generated basename would destroy. A replacement has no client filename to recover, so it takes the stored basename.
- **After commit.** A consumer panel may run `->databaseTransactions()` (bianka's does). Deleting the old file before the commit would leave a rolled-back row pointing at a file that is already gone. `DB::afterCommit()` runs the callback immediately when there is no transaction, so the same code is right on both consumers.

`fileIsShared(?string $path = null, ?string $disk = null)` answers the shared-file question for an arbitrary path; called with no arguments it asks about the row's current one, which is what `deleting` does.

**Changing a row's `type`** (upload ↔ external ↔ embed) in the repeater is not an update — an upload row and an external row share no state worth carrying across, and keeping the row would leave `type=upload` beside a `url` while `url()` went on serving the old file. `MediaSchema` deletes the row and creates a fresh one at the same position and cover flag; `deleting` removes the upload's file on the way out.

#### Dimensions and variants

Every uploaded image carries its intrinsic `width`/`height`, read from the file header in the `saving` hook whenever `path` is dirty — on create and on replace. No decode: `getimagesize()` is a header read, so it belongs in the request. EXIF orientations 5–8 transpose the pair, so a portrait phone photo (stored landscape, rotated by a flag the browser honours and GD does not) records the portrait pair the browser shows.

Resized copies are baked at ingest, not on demand. `saved` dispatches `Jobs\GenerateImageVariants` **after commit** when an image upload is created or re-pointed; the job decodes once with GD, applies the EXIF rotation, and writes one WebP per rung of `media.variants.widths` (default 400 / 800 / 1600) that is narrower than the source — never upscaled — as `{stem}-w{width}.webp` beside the original, alpha preserved. Then it writes the `variants` map with `saveQuietly()`. The dispatch guard (`wasRecentlyCreated || wasChanged('path')`) is the recursion breaker; the quiet save is belt and braces.

Everything that stops the ladder is **recorded, not thrown**: non-image, missing file, non-local disk, no GD decoder for the mime, more than `media.variants.max_megapixels` (40), not wider than the smallest rung — `variants = {}` plus one `Log::info` line with the reason. So `variants IS NULL` means exactly "never attempted", which is what `media:variants` lists.

The job is unique on `disk:path` for two minutes, carries scalars rather than the model (a row deleted between dispatch and run bails quietly), and adopts rung files that already exist on disk without decoding — a reseed re-creates rows, not rungs. `$timeout` is 55 s, under the consumers' 60 s workers.

The replace invariant and `deleting` both cover the rungs: a `path` change resets `variants` and removes the old file's rungs with it after commit; deleting a row removes its rungs with the file. Both keep the shared-file guard.

**Rendering.** `Support\ImagePayload::for($media)` is the one payload — `['url','alt','description','width','height','srcset']` — same convention as `OrderSummary`. `url` is always the original (WebP-only rungs are safe because the original is the last-resort candidate; `og:image` stays the original too). `srcset` is `null` until rungs exist, else an ascending candidate list with the original as the widest. Consumers assemble the attribute string and choose `sizes`; the package emits no HTML.

**Backfill.** `php artisan media:variants` once per environment after the v0.69.0 bump (queued; `--sync` runs inline when the queue is busy); it also fills `width`/`height` on rows that predate the columns. Run it again later: "Dispatched 0" is the done signal. `--all` regenerates after a ladder change.

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

### Jobs

- `Jobs\GenerateImageVariants` — the WebP ladder for one upload. `ShouldQueue`, `ShouldBeUnique` on `disk:path`; see *Dimensions and variants*.

### Commands

- `media:variants {--missing} {--all} {--sync} {--limit=}` — registered, not scheduled. Backfill and detection surface for the ladder.

## Future

- Config-driven validation rules per collection (accept mimes, max files, max size)
- `StoreExternalMedia` / `StoreEmbedMedia` actions for non-upload types
- `DetachMedia` action (remove from one parent without deleting the file)
