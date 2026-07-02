<?php

declare(strict_types=1);

namespace InOtherShops\Support\Filament;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * One manual-sync participant on a translatable Filament page: the form keys it
 * owns (stripped from the record's column data before the row is written), an
 * optional fill step (merges the participant's fields into the Edit form state),
 * and a save step (persists them from the dehydrated form state after the row
 * is written). Declared via {@see SavesTranslatableForm::syncSchemas()}.
 */
final readonly class FormSync
{
    /**
     * @param  list<string>  $keys  form keys this participant owns
     * @param  (Closure(Model, array<string, mixed>): array<string, mixed>)|null  $fill  merge fields into Edit fill state (null = no fill step, e.g. Media derives from the record)
     * @param  Closure(Model, array<string, mixed>): void  $save  persist from the dehydrated form state
     */
    public function __construct(
        public array $keys,
        public ?Closure $fill,
        public Closure $save,
    ) {}
}
