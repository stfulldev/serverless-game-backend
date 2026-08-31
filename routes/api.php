<?php

use App\Http\Controllers\Api\V1\FarmController;
use App\Http\Middleware\AuthenticatePlayer;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware(AuthenticatePlayer::class)
    ->group(function (): void {
        Route::get('/farm', [FarmController::class, 'show'])
            ->name('api.v1.farm.show');
    });
