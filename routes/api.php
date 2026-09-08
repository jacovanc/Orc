<?php

use App\Http\Controllers\Api\AmpIntegrationController;
use App\Http\Middleware\VerifyAmpConnectionSignature;
use Illuminate\Support\Facades\Route;

Route::middleware([VerifyAmpConnectionSignature::class, 'throttle:60,1'])
    ->prefix('integrations/amp/connections/{ampProjectConnection:public_id}')
    ->group(function () {
        Route::post('/callback', [AmpIntegrationController::class, 'callback']);
        Route::post('/context', [AmpIntegrationController::class, 'context']);
    });

Route::post('/integrations/amp/stage-capability', [AmpIntegrationController::class, 'stageCapability'])
    ->middleware('throttle:120,1');

Route::post('/integrations/amp/connection-verification', [AmpIntegrationController::class, 'connectionVerification'])
    ->middleware('throttle:60,1');

Route::post('/integrations/amp/project-setup', [AmpIntegrationController::class, 'projectSetup'])
    ->middleware('throttle:30,1');
