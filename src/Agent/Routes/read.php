<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use InOtherShops\Agent\Http\Controllers\ReadApiController;

// Mounted by AgentServiceProvider under `agent.read.path`, behind the read
// bearer, only when `agent.read.enabled` is on.
Route::get('/{resource}', ReadApiController::class)
    ->whereIn('resource', ReadApiController::RESOURCES)
    ->name('agent.read');
