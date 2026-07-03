<?php

use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\Ingestion\AnswerController;
use App\Http\Controllers\Api\V1\Ingestion\EventController;
use App\Http\Controllers\Api\V1\Ingestion\PlayerController;
use App\Http\Controllers\Api\V1\Ingestion\RewardController;
use App\Http\Controllers\Api\V1\Ingestion\TransactionController;
use App\Http\Controllers\Api\V1\VersionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('versions', [VersionController::class, 'index']);
    Route::post('versions', [VersionController::class, 'store']);
    Route::get('versions/{version}', [VersionController::class, 'show']);

    Route::prefix('versions/{version}')->group(function () {
        Route::post('players', [PlayerController::class, 'store']);
        Route::post('events', [EventController::class, 'store']);
        Route::post('transactions', [TransactionController::class, 'store']);
        Route::post('answers', [AnswerController::class, 'store']);
        Route::post('rewards', [RewardController::class, 'store']);

        Route::get('exports', [ExportController::class, 'index']);
        Route::post('exports', [ExportController::class, 'store']);
        Route::post('exports/preview', [ExportController::class, 'preview']);
    });

    Route::get('exports', [ExportController::class, 'all']);
    Route::get('exports/{export}', [ExportController::class, 'show']);
    Route::get('exports/{export}/download', [ExportController::class, 'download']);
    Route::delete('exports/{export}', [ExportController::class, 'destroy']);
});
