<?php

use App\Http\Controllers\ScriptDashboardController;
use App\Http\Controllers\ScriptReviewController;
use App\Http\Middleware\ScriptReviewAuth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Read-only run history (optional ?key= gate).
Route::get('/scripts', [ScriptDashboardController::class, 'index'])->name('scripts.runs');

// Privileged review surface — HTTP Basic Auth (can execute scripts).
Route::middleware(ScriptReviewAuth::class)->prefix('scripts/review')->group(function () {
    Route::get('/', [ScriptReviewController::class, 'index'])->name('scripts.review');
    Route::post('/approve', [ScriptReviewController::class, 'approve'])->name('scripts.review.approve');
    Route::post('/reject', [ScriptReviewController::class, 'reject'])->name('scripts.review.reject');
    Route::post('/delete', [ScriptReviewController::class, 'delete'])->name('scripts.review.delete');
});
