<?php

use Illuminate\Support\Facades\Route;
use TornadoEngine\AccessMicrosoftAd\Http\AdController;

Route::middleware(['web', 'auth', 'can:access.manage'])
    ->prefix('access/active-directory')
    ->name('access.ad.')
    ->group(function () {
        Route::get('/', [AdController::class, 'index'])->name('index');
        Route::post('/connection', [AdController::class, 'connection'])->name('connection');
        Route::post('/sign-in', [AdController::class, 'signIn'])->name('signin');
        Route::post('/rules', [AdController::class, 'store'])->name('rules.store');
        Route::delete('/rules/{rule}', [AdController::class, 'destroy'])->name('rules.destroy');
        Route::post('/sync', [AdController::class, 'sync'])->name('sync');
    });
