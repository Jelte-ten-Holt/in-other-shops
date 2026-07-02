<?php

declare(strict_types=1);

namespace InOtherShops\Support\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The lock–re-read frame shared by actions that mutate a single row under
 * contention (order confirm/status, payment refund). Opens a transaction,
 * re-reads the row `FOR UPDATE` inside it so concurrent callers serialise on
 * it, and hands the *locked* instance (or null if the row no longer exists) to
 * the closure. The closure owns every guard, and owns syncing the locked row's
 * attributes back onto the caller's instance (`setRawAttributes`) at the point
 * it needs to — that point is ordering-sensitive (e.g. it must precede an event
 * dispatch that still runs inside this transaction), so it stays per-action
 * rather than being forced to the tail here.
 */
trait RunsLockedTransactions
{
    /**
     * @template TReturn
     *
     * @param  Closure(Model|null): TReturn  $fn  receives the locked row, or null if it no longer exists
     * @return TReturn
     */
    protected function withLocked(Model $model, Closure $fn): mixed
    {
        return DB::transaction(fn () => $fn(
            $model->newQuery()->lockForUpdate()->find($model->getKey()),
        ));
    }
}
