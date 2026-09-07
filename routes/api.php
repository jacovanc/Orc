<?php

use App\Http\Controllers\Api\AmpIntegrationController;
use App\Http\Middleware\VerifyAmpCallbackSignature;
use Illuminate\Support\Facades\Route;

Route::middleware([VerifyAmpCallbackSignature::class, 'throttle:60,1'])
    ->prefix('integrations/amp')
    ->group(function () {
        Route::post('/callback', [AmpIntegrationController::class, 'callback']);
        Route::post('/context', [AmpIntegrationController::class, 'context']);
    });
