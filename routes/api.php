<?php

use App\Http\Controllers\Api\V1\BuildingController;
use App\Http\Controllers\Api\V1\BuildingDeletionController;
use App\Http\Controllers\Api\V1\BuildingMovementController;
use App\Http\Controllers\Api\V1\ClearedObstacleController;
use App\Http\Controllers\Api\V1\FarmController;
use App\Http\Controllers\Api\V1\ProductionCollectionController;
use App\Http\Controllers\Api\V1\ProductionController;
use App\Http\Middleware\AuthenticatePlayer;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware(AuthenticatePlayer::class)
    ->group(function (): void {
        Route::get('/farm', [FarmController::class, 'show'])
            ->name('api.v1.farm.show');

        Route::post('/obstacles/{obstacleId}/clear', [ClearedObstacleController::class, 'store'])
            ->where('obstacleId', '[A-Za-z0-9-]+')
            ->name('api.v1.obstacles.clear');

        Route::post('/buildings', [BuildingController::class, 'store'])
            ->name('api.v1.buildings.store');

        Route::patch('/buildings/{buildingId}/move', [BuildingMovementController::class, 'update'])
            ->whereUuid('buildingId')
            ->name('api.v1.buildings.move');

        Route::delete('/buildings/{buildingId}', [BuildingDeletionController::class, 'destroy'])
            ->whereUuid('buildingId')
            ->name('api.v1.buildings.destroy');

        Route::post('/buildings/{buildingId}/productions', [ProductionController::class, 'store'])
            ->whereUuid('buildingId')
            ->name('api.v1.building-productions.store');

        Route::post('/productions/{productionId}/collect', [ProductionCollectionController::class, 'store'])
            ->whereUuid('productionId')
            ->name('api.v1.productions.collect');
    });
