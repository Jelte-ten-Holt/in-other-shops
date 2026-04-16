<?php

declare(strict_types=1);

use InOtherShops\Storefront\Controllers\BrowsableListController;
use InOtherShops\Storefront\Controllers\BrowsableShowController;
use InOtherShops\Storefront\Controllers\CategoryListController;
use InOtherShops\Storefront\Controllers\CategoryShowController;
use Illuminate\Support\Facades\Route;

// HasStorefrontPresence model routes — registered dynamically from config('storefront.models')
foreach (config('storefront.models', []) as $key => $modelClass) {
    Route::get($key, BrowsableListController::class)
        ->name("storefront.{$key}.index")
        ->defaults('browsable_model', $modelClass);

    Route::get("{$key}/{slug}", BrowsableShowController::class)
        ->name("storefront.{$key}.show")
        ->defaults('browsable_model', $modelClass);
}

// Category routes
Route::get('categories', CategoryListController::class)->name('storefront.categories.index');
Route::get('categories/{slug}', CategoryShowController::class)->name('storefront.categories.show');
