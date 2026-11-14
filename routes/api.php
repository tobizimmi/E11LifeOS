<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\LifeController;
use App\Http\Controllers\Api\V1\LoyaltyCardController;
use App\Http\Controllers\Api\V1\MarketController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Controllers\Api\V1\PortfolioController;
use App\Http\Controllers\Api\V1\RecipeController;
use App\Http\Controllers\Api\V1\RecommendationController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SmartHomeController;
use App\Http\Controllers\Api\V1\TodayController;
use App\Http\Controllers\Api\V1\TripController;
use App\Http\Controllers\Api\V1\WatchlistController;
use App\Http\Controllers\Api\V1\WishlistController;
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
        Route::put('tasks/{task}', [LifeController::class, 'updateTask'])->whereNumber('task')->name('tasks.update');
        Route::delete('tasks/{task}', [LifeController::class, 'destroyTask'])->whereNumber('task')->name('tasks.destroy');
        Route::post('tasks/{task}/toggle', [LifeController::class, 'toggleTask'])->whereNumber('task')->name('tasks.toggle');
        Route::get('calendar', [LifeController::class, 'calendar'])->name('calendar');
        Route::get('calendar/subscriptions', [CalendarController::class, 'subscriptions'])->name('calendar.subscriptions.index');
        Route::post('calendar/subscriptions', [CalendarController::class, 'storeSubscription'])->name('calendar.subscriptions.store');
        Route::delete('calendar/subscriptions/{subscription}', [CalendarController::class, 'destroySubscription'])->whereNumber('subscription')->name('calendar.subscriptions.destroy');
        Route::get('notifications', [LifeController::class, 'notifications'])->name('notifications.index');
        Route::post('notifications/read', [LifeController::class, 'readNotifications'])->name('notifications.read');
        Route::get('shopping', [LifeController::class, 'shopping'])->name('shopping.index');
        Route::post('shopping', [LifeController::class, 'storeShopping'])->name('shopping.store');
        Route::post('shopping/quick', [LifeController::class, 'quickShopping'])->name('shopping.quick');
        Route::post('shopping/{entry}/toggle', [LifeController::class, 'toggleShopping'])->whereNumber('entry')->name('shopping.toggle');
        Route::get('net-worth', [LifeController::class, 'netWorth'])->name('net-worth');
        Route::get('finances', [LifeController::class, 'finances'])->name('finances');
        Route::get('finance/categories', [FinanceController::class, 'categories'])->name('finance.categories');
        Route::put('transactions/{transaction}', [FinanceController::class, 'recategorize'])->whereNumber('transaction')->name('transactions.update');
        Route::get('budgets', [FinanceController::class, 'budgets'])->name('budgets.index');
        Route::put('budgets', [FinanceController::class, 'updateBudget'])->name('budgets.update');
        Route::get('cashflow', [FinanceController::class, 'cashflow'])->name('cashflow.index');
        Route::post('cashflow', [FinanceController::class, 'storeCashflow'])->name('cashflow.store');
        Route::put('cashflow/{cashflowEntry}', [FinanceController::class, 'updateCashflow'])->whereNumber('cashflowEntry')->name('cashflow.update');
        Route::delete('cashflow/{cashflowEntry}', [FinanceController::class, 'destroyCashflow'])->whereNumber('cashflowEntry')->name('cashflow.destroy');
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

        // Pages / notes (second brain): Markdown body, offline editable.
        Route::get('pages', [PageController::class, 'index'])->name('pages.index');
        Route::post('pages', [PageController::class, 'store'])->name('pages.store');
        Route::get('pages/{page}', [PageController::class, 'show'])->whereNumber('page')->name('pages.show');
        Route::put('pages/{page}', [PageController::class, 'update'])->whereNumber('page')->name('pages.update');
        Route::delete('pages/{page}', [PageController::class, 'destroy'])->whereNumber('page')->name('pages.destroy');
        Route::post('pages/{page}/attachments', [AttachmentController::class, 'store'])->whereNumber('page')->name('pages.attachments.store');
        Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->whereNumber('attachment')->name('attachments.api.show');

        // Trips (read + edit itinerary/packing/bookings) for offline use.
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

        // Everyday modules (loyalty cards, wishlist, journal, people, places).
        Route::get('cards', [LoyaltyCardController::class, 'index'])->name('cards.index');
        Route::post('cards', [LoyaltyCardController::class, 'store'])->name('cards.store');
        Route::put('cards/{card}', [LoyaltyCardController::class, 'update'])->whereNumber('card')->name('cards.update');
        Route::delete('cards/{card}', [LoyaltyCardController::class, 'destroy'])->whereNumber('card')->name('cards.destroy');

        Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
        Route::post('wishlist', [WishlistController::class, 'store'])->name('wishlist.store');
        Route::put('wishlist/{wish}', [WishlistController::class, 'update'])->whereNumber('wish')->name('wishlist.update');
        Route::post('wishlist/{wish}/decide', [WishlistController::class, 'decide'])->whereNumber('wish')->name('wishlist.decide');
        Route::delete('wishlist/{wish}', [WishlistController::class, 'destroy'])->whereNumber('wish')->name('wishlist.destroy');

        Route::get('journal', [JournalController::class, 'index'])->name('journal.index');
        Route::get('journal/{date}', [JournalController::class, 'show'])->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')->name('journal.show');
        Route::put('journal/{date}', [JournalController::class, 'update'])->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')->name('journal.update');

        Route::get('people', [ContactController::class, 'index'])->name('people.index');
        Route::post('people', [ContactController::class, 'store'])->name('people.store');
        Route::put('people/{person}', [ContactController::class, 'update'])->whereNumber('person')->name('people.update');
        Route::delete('people/{person}', [ContactController::class, 'destroy'])->whereNumber('person')->name('people.destroy');
        Route::post('people/{person}/contacted', [ContactController::class, 'contacted'])->whereNumber('person')->name('people.contacted');
        Route::post('people/{person}/gifts', [ContactController::class, 'storeGift'])->whereNumber('person')->name('people.gifts.store');
        Route::put('gifts/{gift}', [ContactController::class, 'updateGift'])->whereNumber('gift')->name('people.gifts.update');
        Route::delete('gifts/{gift}', [ContactController::class, 'destroyGift'])->whereNumber('gift')->name('people.gifts.destroy');

        Route::get('places', [PlaceController::class, 'index'])->name('places.index');
        Route::post('places', [PlaceController::class, 'store'])->name('places.store');
        Route::put('places/{place}', [PlaceController::class, 'update'])->whereNumber('place')->name('places.update');
        Route::post('places/{place}/visit', [PlaceController::class, 'visit'])->whereNumber('place')->name('places.visit');
        Route::post('places/{place}/rate', [PlaceController::class, 'rate'])->whereNumber('place')->name('places.rate');
        Route::delete('places/{place}', [PlaceController::class, 'destroy'])->whereNumber('place')->name('places.destroy');

        Route::middleware('addon:smart_home')->group(function () {
            Route::get('smart-home', [SmartHomeController::class, 'index'])->name('smart-home.index');
            Route::post('smart-home/devices/{entity}/press', [SmartHomeController::class, 'press'])->whereNumber('entity')->name('smart-home.press');
            Route::post('smart-home/modes/{key}', [SmartHomeController::class, 'mode'])->where('key', '[a-z_]{1,40}')->name('smart-home.mode');
        });

        Route::middleware('addon:recipes')->group(function () {
            Route::get('recipes', [RecipeController::class, 'index'])->name('recipes.index');
            Route::post('recipes', [RecipeController::class, 'store'])->name('recipes.store');
            Route::get('recipes/{recipe}', [RecipeController::class, 'show'])->whereNumber('recipe')->name('recipes.show');
            Route::get('recipes/{recipe}/image', [RecipeController::class, 'image'])->whereNumber('recipe')->name('recipes.image.api');
            Route::put('recipes/{recipe}', [RecipeController::class, 'update'])->whereNumber('recipe')->name('recipes.update');
            Route::delete('recipes/{recipe}', [RecipeController::class, 'destroy'])->whereNumber('recipe')->name('recipes.destroy');
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
            Route::get('strategy', [PortfolioController::class, 'strategy'])->name('strategy');

            Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
            Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
        });
    });
});
