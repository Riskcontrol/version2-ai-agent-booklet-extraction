<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\GithubController;
use App\Http\Controllers\SearchController;

// These routes rely on session-based auth (CheckAuth uses Session::get('authenticated')).
// API routes do not include session middleware by default, so we explicitly enable the `web`
// middleware group for the authenticated endpoints.
Route::middleware(['web', 'App\Http\Middleware\CheckAuth'])->group(function () {
    Route::post('/upload', [DocumentController::class, 'upload']);
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::delete('/documents/{doc}', [DocumentController::class, 'delete']);
});

Route::get('/download/{doc}', [DocumentController::class, 'download'])
    ->name('documents.download')
    ->middleware('signed');
Route::get('/download-output/{doc}/{type}', [DocumentController::class, 'downloadOutput'])
    ->name('documents.downloadOutput')
    ->where('type', 'csv|xlsx')
    ->middleware('signed');

Route::post('/github/callback', [GithubController::class, 'callback'])->name('github.callback');
Route::post('/github/upload-results', [GithubController::class, 'uploadResults'])->name('github.uploadResults');
