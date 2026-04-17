<?php

declare(strict_types=1);

use InOtherShops\Commerce\Cart\Http\Controllers\CartController;
use InOtherShops\Commerce\Cart\Http\Controllers\CartItemController;
use InOtherShops\Commerce\Commerce;
use Illuminate\Support\Facades\Route;

Route::bind('cart_item', function (string $value) {
    $model = Commerce::cartItem()::query()->find($value);

    if ($model === null) {
        abort(404);
    }

    return $model;
});

Route::get('/', [CartController::class, 'show'])->name('cart.show');
Route::delete('/', [CartController::class, 'destroy'])->name('cart.destroy');

Route::post('items', [CartItemController::class, 'store'])->name('cart.items.store');
Route::patch('items/{cart_item}', [CartItemController::class, 'update'])->name('cart.items.update');
Route::delete('items/{cart_item}', [CartItemController::class, 'destroy'])->name('cart.items.destroy');
