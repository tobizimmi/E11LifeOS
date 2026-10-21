<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\LifeController;
use App\Http\Controllers\Api\V1\MarketController;
use App\Http\Controllers\Api\V1\PortfolioController;
use App\Http\Controllers\Api\V1\RecommendationController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SmartHomeController;
use App\Http\Controllers\Api\V1\TodayController;
use App\Http\Controllers\Api\V1\TripController;
use App\Http\Controllers\Api\V1\WatchlistController;
use App\Http\Controllers\SmartHomeWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Mobile API (Expo app). Sign in with POST /api/v1/auth/login, then send "Authorization: Bearer <token>".
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');
    // Siri/Shortcut one-action quick-add: token + text in the query, no header or JSON body needed.
    Route::get('quick/{type}', [LifeController::class, 'quickViaToken'])->where('type', 'shopping|task|note|inbox|auto')->middleware('throttle:60,1')->name('quick');
    // Home Assistant reports state changes here (secret in the address, see Smart home › Settings).
    Route::post('smart-home/webhook/{secret}', SmartHomeWebhookController::class)->middleware('throttle:240,1')->where('secret', '[A-Za-z0-9]{32,64}')->name('smart-home.webhook');

    Route::middleware(['auth.token', 'throttle:120,1', 'module'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('tasks', [LifeController::class, 'tasks'])->name('tasks.index');
        Route::post('tasks', [LifeController::class, 'storeTask'])->name('tasks.store');
        Route::post('tasks/{task}/toggle', [LifeController::class, 'toggleTask'])->whereNumber('task')->name('tasks.toggle');
        Route::get('calendar', [LifeController::class, 'calendar'])->name('calendar');
        Route::get('notifications', [LifeController::class, 'notifications'])->name('notifications.index');
        Route::post('notifications/read', [LifeController::class, 'readNotifications'])->name('notifications.read');
        Route::get('shopping', [LifeController::class, 'shopping'])->name('shopping.index');
        Route::post('shopping', [LifeController::class, 'storeShopping'])->name('shopping.store');
        Route::post('shopping/quick', [LifeController::class, 'quickShopping'])->name('shopping.quick');
        Route::post('shopping/{entry}/toggle', [LifeController::class, 'toggleShopping'])->whereNumber('entry')->name('shopping.toggle');
        Route::get('net-worth', [LifeController::class, 'netWorth'])->name('net-worth');
        Route::get('today', [TodayController::class, 'today'])->name('today');
        Route::get('widgets', [TodayController::class, 'widgets'])->name('widgets');
        Route::post('capture', [TodayController::class, 'capture'])->name('capture');
        Route::post('presence', [SmartHomeController::class, 'presence'])->name('presence');

        // Generic collections/items (notes, pantry/supplies, custom databases) for offline editing.
        Route::get('collections', [CollectionController::class, 'index'])->name('collections.index');
        Route::get('collections/{collection}/items', [CollectionController::class, 'items'])->whereNumber('collection')->name('collections.items');
        Route::post('collections/{collection}/items', [CollectionController::class, 'storeItem'])->whereNumber('collection')->name('collections.items.store');
        Route::put('items/{item}', [CollectionController::class, 'updateItem'])->whereNumber('item')->name('items.update');
        Route::delete('items/{item}', [CollectionController::class, 'destroyItem'])->whereNumber('item')->name('items.destroy');

        // Trips (read-only for now): list + itinerary/places/packing/bookings for offline viewing.
        Route::get('trips', [TripController::class, 'index'])->name('trips.index');
        Route::get('trips/{trip}', [TripController::class, 'show'])->whereNumber('trip')->name('trips.show');
        Route::post('trips/{trip}/stops', [TripController::class, 'storeStop'])->whereNumber('trip')->name('trips.stops.store');
        Route::put('trip-stops/{stop}', [TripController::class, 'updateStop'])->whereNumber('stop')->name('trip-stops.update');
        Route::delete('trip-stops/{stop}', [TripController::class, 'destroyStop'])->whereNumber('stop')->name('trip-stops.destroy');
        Route::post('trips/{trip}/packing', [TripController::class, 'storePacking'])->whereNumber('trip')->name('trips.packing.store');
        Route::put('trip-packing/{packingItem}', [TripController::class, 'updatePacking'])->whereNumber('packingItem')->name('trip-packing.update');
        Route::delete('trip-packing/{packingItem}', [TripController::class, 'destroyPacking'])->whereNumber('packingItem')->name('trip-packing.destroy');
        Route::post('trips/{trip}/bookings', [TripController::class, 'storeBooking'])->whereNumber('trip')->name('trips.bookings.store');
        Route::put('trip-bookings/{booking}', [TripController::class, 'updateBooking'])->whereNumber('booking')->name('trip-bookings.update');
        Route::delete('trip-bookings/{booking}', [TripController::class, 'destroyBooking'])->whereNumber('booking')->name('trip-bookings.destroy');

        Route::middleware('addon:smart_home')->group(function () {
            Route::get('smart-home', [SmartHomeController::class, 'index'])->name('smart-home.index');
            Route::post('smart-home/devices/{entity}/press', [SmartHomeController::class, 'press'])->whereNumber('entity')->name('smart-home.press');
            Route::post('smart-home/modes/{key}', [SmartHomeController::class, 'mode'])->where('key', '[a-z_]{1,40}')->name('smart-home.mode');
        });

        Route::middleware('addon:portfolio')->group(function () {
            Route::get('portfolios', [PortfolioController::class, 'index'])->name('portfolios.index');
            Route::get('portfolios/{portfolio}/positions', [PortfolioController::class, 'positions'])->name('portfolios.positions');
            Route::post('portfolios/{portfolio}/positions', [PortfolioController::class, 'storePosition'])->name('portfolios.positions.store');
            Route::put('positions/{position}', [PortfolioController::class, 'updatePosition'])->whereNumber('position')->name('positions.update');
            Route::delete('positions/{position}', [PortfolioController::class, 'destroyPosition'])->whereNumber('position')->name('positions.destroy');
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
