<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.pixelforge');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [GenerationController::class, 'dashboard'])->name('dashboard');
    Route::get('/generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('/generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::post('/credits/purchase', [CreditController::class, 'store'])->name('credits.purchase');

    Route::get('/admin/report', AdminReportController::class)
        ->middleware([EnsureUserIsAdmin::class])
        ->name('admin.report');
});

require __DIR__.'/settings.php';
