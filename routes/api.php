<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MarketController;
use App\Http\Controllers\Api\V1\PortfolioController;
use App\Http\Controllers\Api\V1\RecommendationController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\WatchlistController;
use Illuminate\Support\Facades\Route;

/*
 * Mobile API (Expo app). Sign in with POST /api/v1/auth/login, then send "Authorization: Bearer <token>".
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');

    Route::middleware(['auth.token', 'throttle:120,1'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');

        Route::middleware('addon:portfolio')->group(function () {
            Route::get('portfolios', [PortfolioController::class, 'index'])->name('portfolios.index');
            Route::get('portfolios/{portfolio}/positions', [PortfolioController::class, 'positions'])->name('portfolios.positions');
            Route::get('portfolios/{portfolio}/summary', [PortfolioController::class, 'summary'])->name('portfolios.summary');
            Route::get('portfolios/{portfolio}/transactions', [PortfolioController::class, 'transactions'])->name('portfolios.transactions');
            Route::get('portfolios/{portfolio}/chart', [PortfolioController::class, 'chart'])->name('portfolios.chart');

            Route::get('watchlist', [WatchlistController::class, 'index'])->name('watchlist.index');
            Route::post('watchlist', [WatchlistController::class, 'store'])->name('watchlist.store');
            Route::delete('watchlist/{symbol}', [WatchlistController::class, 'destroy'])->where('symbol', '[A-Za-z0-9.\-^=]{1,32}')->name('watchlist.destroy');

            Route::get('quotes', [MarketController::class, 'quotes'])->name('quotes');
            Route::get('quotes/{symbol}', [MarketController::class, 'quote'])->where('symbol', '[A-Za-z0-9.\-^=]{1,32}')->name('quote');
            Route::get('search', [MarketController::class, 'search'])->name('search');

            Route::get('recommendations', [RecommendationController::class, 'index'])->name('recommendations');

            Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
            Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
        });
    });
});
