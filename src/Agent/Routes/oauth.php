<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use InOtherShops\Agent\Http\Controllers\AuthorizationServerMetadataController;
use InOtherShops\Agent\Http\Controllers\DynamicClientRegistrationController;
use InOtherShops\Agent\Http\Controllers\ProtectedResourceMetadataController;

Route::get('/.well-known/oauth-protected-resource', ProtectedResourceMetadataController::class)
    ->name('agent.oauth.protected-resource-metadata');

Route::get('/.well-known/oauth-authorization-server', AuthorizationServerMetadataController::class)
    ->name('agent.oauth.authorization-server-metadata');

if ((bool) config('agent.auth.oauth.dcr.enabled', true)) {
    $rateLimit = (string) config('agent.auth.oauth.dcr.rate_limit', '5,1');

    Route::post('/oauth/register', DynamicClientRegistrationController::class)
        ->middleware("throttle:{$rateLimit}")
        ->name('agent.oauth.dynamic-client-registration');
}
