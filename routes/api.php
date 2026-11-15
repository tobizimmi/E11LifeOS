<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\DocumentsController;
use App\Http\Controllers\Api\V1\EmergencyController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\FocusController;
use App\Http\Controllers\Api\V1\HabitController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HouseController;
use App\Http\Controllers\Api\V1\InvestingController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\KidController;
use App\Http\Controllers\Api\V1\KnowledgeController;
use App\Http\Controllers\Api\V1\LearningController;
use App\Http\Controllers\Api\V1\LifeController;
use App\Http\Controllers\Api\V1\LoyaltyCardController;
use App\Http\Controllers\Api\V1\MarketController;
use App\Http\Controllers\Api\V1\MealPlanController;
use App\Http\Controllers\Api\V1\OffersController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\PantryController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Controllers\Api\V1\PortfolioController;
use App\Http\Controllers\Api\V1\PortfolioReportsController;
use App\Http\Controllers\Api\V1\RecipeController;
use App\Http\Controllers\Api\V1\RecommendationController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SmartHomeController;
use App\Http\Controllers\Api\V1\SplitController;
use App\Http\Controllers\Api\V1\TodayController;
use App\Http\Controllers\Api\V1\TripController;
use App\Http\Controllers\Api\V1\VehicleController;
use App\Http\Controllers\Api\V1\WatchlistController;
use App\Http\Controllers\Api\V1\WealthPlanController;
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
        Route::post('auth/confirm-password', [AuthController::class, 'confirmPassword'])->name('confirm-password');
        Route::delete('auth/confirm-password', [AuthController::class, 'forgetConfirmation'])->name('confirm-password.forget');

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
        Route::get('shopping/offers', [OffersController::class, 'index'])->name('shopping.offers.index');
        Route::get('shopping/offers/settings', [OffersController::class, 'settings'])->name('shopping.offers.settings');
        Route::put('shopping/offers/settings', [OffersController::class, 'saveSettings'])->name('shopping.offers.settings.save');
        Route::post('shopping/offers/watches', [OffersController::class, 'storeWatch'])->name('shopping.offers.watches.store');
        Route::delete('shopping/offers/watches/{watch}', [OffersController::class, 'destroyWatch'])->whereNumber('watch')->name('shopping.offers.watches.destroy');
        Route::post('shopping/offers/add', [OffersController::class, 'add'])->name('shopping.offers.add');
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
        Route::get('cards/{card}/image/{side}', [LoyaltyCardController::class, 'image'])->whereNumber('card')->whereIn('side', ['front', 'back'])->name('cards.image.show');
        Route::post('cards/{card}/image/{side}', [LoyaltyCardController::class, 'storeImage'])->whereNumber('card')->whereIn('side', ['front', 'back'])->name('cards.image.store');
        Route::delete('cards/{card}/image/{side}', [LoyaltyCardController::class, 'destroyImage'])->whereNumber('card')->whereIn('side', ['front', 'back'])->name('cards.image.destroy');

        Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
        Route::post('wishlist', [WishlistController::class, 'store'])->name('wishlist.store');
        Route::put('wishlist/{wish}', [WishlistController::class, 'update'])->whereNumber('wish')->name('wishlist.update');
        Route::post('wishlist/{wish}/decide', [WishlistController::class, 'decide'])->whereNumber('wish')->name('wishlist.decide');
        Route::delete('wishlist/{wish}', [WishlistController::class, 'destroy'])->whereNumber('wish')->name('wishlist.destroy');

        Route::get('journal', [JournalController::class, 'index'])->name('journal.index');
        Route::get('journal/{date}', [JournalController::class, 'show'])->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')->name('journal.show');
        Route::put('journal/{date}', [JournalController::class, 'update'])->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')->name('journal.update');
        Route::post('journal/{date}/photos', [JournalController::class, 'storePhoto'])->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')->name('journal.photos.store');
        Route::get('journal/{date}/photos/{index}', [JournalController::class, 'photo'])->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')->whereNumber('index')->name('journal.photos.show');
        Route::delete('journal/{date}/photos/{index}', [JournalController::class, 'destroyPhoto'])->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')->whereNumber('index')->name('journal.photos.destroy');

        Route::get('habits', [HabitController::class, 'index'])->name('journal.habits.index');
        Route::post('habits', [HabitController::class, 'store'])->name('journal.habits.store');
        Route::put('habits/{habit}', [HabitController::class, 'update'])->whereNumber('habit')->name('journal.habits.update');
        Route::post('habits/{habit}/tap', [HabitController::class, 'tap'])->whereNumber('habit')->name('journal.habits.tap');
        Route::post('habits/{habit}/archive', [HabitController::class, 'archive'])->whereNumber('habit')->name('journal.habits.archive');
        Route::delete('habits/{habit}', [HabitController::class, 'destroy'])->whereNumber('habit')->name('journal.habits.destroy');

        Route::get('pantry', [PantryController::class, 'index'])->name('pantry.index');
        Route::post('pantry', [PantryController::class, 'store'])->name('pantry.store');
        Route::post('pantry/shopping', [PantryController::class, 'toShopping'])->name('pantry.shopping');
        Route::post('pantry/{item}/adjust', [PantryController::class, 'adjust'])->whereNumber('item')->name('pantry.adjust');
        Route::delete('pantry/{item}', [PantryController::class, 'destroy'])->whereNumber('item')->name('pantry.destroy');

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

        // Money: contract costs and split costs.
        Route::get('wealth-plan', [WealthPlanController::class, 'show'])->name('wealth-plan.show');
        Route::get('contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::post('contracts', [ContractController::class, 'store'])->name('contracts.store');

        Route::get('splits', [SplitController::class, 'index'])->name('splits.index');
        Route::post('splits', [SplitController::class, 'store'])->name('splits.store');
        Route::get('splits/{group}', [SplitController::class, 'show'])->whereNumber('group')->name('splits.show');
        Route::delete('splits/{group}', [SplitController::class, 'destroy'])->whereNumber('group')->name('splits.destroy');
        Route::post('splits/{group}/archive', [SplitController::class, 'archive'])->whereNumber('group')->name('splits.archive');
        Route::post('splits/{group}/members', [SplitController::class, 'storeMember'])->whereNumber('group')->name('splits.members.store');
        Route::delete('splits/{group}/members/{member}', [SplitController::class, 'destroyMember'])->whereNumber(['group', 'member'])->name('splits.members.destroy');
        Route::post('splits/{group}/expenses', [SplitController::class, 'storeExpense'])->whereNumber('group')->name('splits.expenses.store');
        Route::put('splits/{group}/expenses/{expense}', [SplitController::class, 'updateExpense'])->whereNumber(['group', 'expense'])->name('splits.expenses.update');
        Route::delete('splits/{group}/expenses/{expense}', [SplitController::class, 'destroyExpense'])->whereNumber(['group', 'expense'])->name('splits.expenses.destroy');
        Route::post('splits/{group}/settle', [SplitController::class, 'settle'])->whereNumber('group')->name('splits.settle');

        // Productivity and growth: focus & time boxing, lessons learned.
        Route::get('focus', [FocusController::class, 'index'])->name('focus.index');
        Route::post('focus', [FocusController::class, 'store'])->name('focus.store');
        Route::post('focus/import-tasks', [FocusController::class, 'importTasks'])->name('focus.import');
        Route::post('focus/suggest-top', [FocusController::class, 'suggestTop'])->name('focus.suggest');
        Route::put('focus/{entry}', [FocusController::class, 'update'])->whereNumber('entry')->name('focus.update');
        Route::delete('focus/{entry}', [FocusController::class, 'destroy'])->whereNumber('entry')->name('focus.destroy');

        // Document archive (paperless) and scanning.
        Route::get('documents', [DocumentsController::class, 'index'])->name('documents.index');
        Route::post('documents/scan', [DocumentsController::class, 'scan'])->middleware('throttle:20,1')->name('documents.scan');
        Route::get('documents/{document}', [DocumentsController::class, 'show'])->whereNumber('document')->name('documents.show');
        Route::put('documents/{document}', [DocumentsController::class, 'update'])->whereNumber('document')->name('documents.update');
        Route::delete('documents/{document}', [DocumentsController::class, 'destroy'])->whereNumber('document')->name('documents.destroy');
        Route::post('documents/{document}/notes', [DocumentsController::class, 'addNote'])->whereNumber('document')->name('documents.notes.store');
        Route::get('documents/{document}/{variant}', [DocumentsController::class, 'file'])->whereNumber('document')->whereIn('variant', ['thumb', 'preview', 'download'])->name('documents.file');

        Route::get('knowledge/tips', [KnowledgeController::class, 'tips'])->name('knowledge.tips');
        Route::get('knowledge/tips/{key}', [KnowledgeController::class, 'tip'])->where('key', '[A-Za-z0-9_.\\-]+')->name('knowledge.tip');
        Route::get('knowledge/news', [KnowledgeController::class, 'news'])->name('knowledge.news');
        Route::get('knowledge/favorites', [KnowledgeController::class, 'favorites'])->name('knowledge.favorites');
        Route::post('knowledge/favorites', [KnowledgeController::class, 'toggle'])->name('knowledge.favorites.toggle');
        Route::delete('knowledge/favorites/{favorite}', [KnowledgeController::class, 'destroyFavorite'])->whereNumber('favorite')->name('knowledge.favorites.destroy');

        Route::get('learnings', [LearningController::class, 'index'])->name('learnings.index');
        Route::post('learnings', [LearningController::class, 'store'])->name('learnings.store');
        Route::put('learnings/{lesson}', [LearningController::class, 'update'])->whereNumber('lesson')->name('learnings.update');
        Route::post('learnings/{lesson}/review', [LearningController::class, 'review'])->whereNumber('lesson')->name('learnings.review');
        Route::post('learnings/{lesson}/applied', [LearningController::class, 'applied'])->whereNumber('lesson')->name('learnings.applied');
        Route::post('learnings/{lesson}/archive', [LearningController::class, 'archive'])->whereNumber('lesson')->name('learnings.archive');
        Route::delete('learnings/{lesson}', [LearningController::class, 'destroy'])->whereNumber('lesson')->name('learnings.destroy');
        // Home & family: vehicles, maintenance and meters, kids and pocket money.
        Route::get('vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
        Route::post('vehicles', [VehicleController::class, 'store'])->name('vehicles.store');
        Route::get('vehicles/{vehicle}', [VehicleController::class, 'show'])->whereNumber('vehicle')->name('vehicles.show');
        Route::put('vehicles/{vehicle}', [VehicleController::class, 'update'])->whereNumber('vehicle')->name('vehicles.update');
        Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy'])->whereNumber('vehicle')->name('vehicles.destroy');
        Route::post('vehicles/{vehicle}/logs', [VehicleController::class, 'storeLog'])->whereNumber('vehicle')->name('vehicles.logs.store');
        Route::delete('vehicles/{vehicle}/logs/{log}', [VehicleController::class, 'destroyLog'])->whereNumber(['vehicle', 'log'])->name('vehicles.logs.destroy');

        Route::get('house', [HouseController::class, 'index'])->name('house.index');
        Route::post('house/tasks', [HouseController::class, 'storeTask'])->name('house.tasks.store');
        Route::post('house/tasks/{task}/done', [HouseController::class, 'taskDone'])->whereNumber('task')->name('house.tasks.done');
        Route::delete('house/tasks/{task}', [HouseController::class, 'destroyTask'])->whereNumber('task')->name('house.tasks.destroy');
        Route::post('house/meters', [HouseController::class, 'storeMeter'])->name('house.meters.store');
        Route::put('house/meters/{meter}', [HouseController::class, 'updateMeter'])->whereNumber('meter')->name('house.meters.update');
        Route::delete('house/meters/{meter}', [HouseController::class, 'destroyMeter'])->whereNumber('meter')->name('house.meters.destroy');
        Route::post('house/meters/{meter}/readings', [HouseController::class, 'storeReading'])->whereNumber('meter')->name('house.readings.store');
        Route::delete('house/meters/{meter}/readings/{reading}', [HouseController::class, 'destroyReading'])->whereNumber(['meter', 'reading'])->name('house.readings.destroy');

        Route::get('kids', [KidController::class, 'index'])->name('kids.index');
        Route::post('kids', [KidController::class, 'store'])->name('kids.store');
        Route::put('kids/{kid}', [KidController::class, 'update'])->whereNumber('kid')->name('kids.update');
        Route::delete('kids/{kid}', [KidController::class, 'destroy'])->whereNumber('kid')->name('kids.destroy');
        Route::post('kids/{kid}/chores/{chore}/done', [KidController::class, 'choreDone'])->whereNumber(['kid', 'chore'])->name('kids.chores.done');
        Route::post('kids/{kid}/transactions', [KidController::class, 'storeTransaction'])->whereNumber('kid')->name('kids.transactions.store');
        Route::post('kid-chores', [KidController::class, 'storeChore'])->name('kids.chores.store');
        Route::put('kid-chores/{chore}', [KidController::class, 'updateChore'])->whereNumber('chore')->name('kids.chores.update');
        Route::delete('kid-chores/{chore}', [KidController::class, 'destroyChore'])->whereNumber('chore')->name('kids.chores.destroy');
        Route::post('kid-completions/{completion}/review', [KidController::class, 'review'])->whereNumber('completion')->name('kids.completions.review');
        Route::delete('kid-transactions/{transaction}', [KidController::class, 'destroyTransaction'])->whereNumber('transaction')->name('kids.transactions.destroy');

        // Sensitive areas: the password must have been confirmed for the token (15 minutes).
        Route::middleware('api.confirmed')->group(function () {
            Route::middleware('sensitive.log:health')->group(function () {
                Route::get('health', [HealthController::class, 'index'])->name('health.index');
                Route::post('health/profiles', [HealthController::class, 'storeProfile'])->name('health.profiles.store');
                Route::put('health/profiles/{profile}', [HealthController::class, 'updateProfile'])->whereNumber('profile')->name('health.profiles.update');
                Route::delete('health/profiles/{profile}', [HealthController::class, 'destroyProfile'])->whereNumber('profile')->name('health.profiles.destroy');
                Route::post('health/profiles/{profile}/entries', [HealthController::class, 'storeEntry'])->whereNumber('profile')->name('health.entries.store');
                Route::post('health/profiles/{profile}/adopt', [HealthController::class, 'adopt'])->whereNumber('profile')->name('health.entries.adopt');
                Route::post('health/profiles/{profile}/entries/{entry}/done', [HealthController::class, 'done'])->whereNumber(['profile', 'entry'])->name('health.entries.done');
                Route::post('health/profiles/{profile}/entries/{entry}/toggle', [HealthController::class, 'toggleActive'])->whereNumber(['profile', 'entry'])->name('health.entries.toggle');
                Route::delete('health/profiles/{profile}/entries/{entry}', [HealthController::class, 'destroyEntry'])->whereNumber(['profile', 'entry'])->name('health.entries.destroy');
            });

            Route::middleware('sensitive.log:emergency')->group(function () {
                Route::get('emergency', [EmergencyController::class, 'index'])->name('emergency.index');
                Route::post('emergency/entries', [EmergencyController::class, 'store'])->name('emergency.entries.store');
                Route::put('emergency/entries/{entry}', [EmergencyController::class, 'update'])->whereNumber('entry')->name('emergency.entries.update');
                Route::delete('emergency/entries/{entry}', [EmergencyController::class, 'destroy'])->whereNumber('entry')->name('emergency.entries.destroy');
            });
        });

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

            Route::get('meal-plan', [MealPlanController::class, 'index'])->name('meal-plan.index');
            Route::post('meal-plan', [MealPlanController::class, 'store'])->name('meal-plan.store');
            Route::get('meal-plan/ingredients', [MealPlanController::class, 'ingredients'])->name('meal-plan.ingredients');
            Route::post('meal-plan/shopping', [MealPlanController::class, 'toShopping'])->name('meal-plan.shopping');
            Route::put('meal-plan/{entry}', [MealPlanController::class, 'update'])->whereNumber('entry')->name('meal-plan.update');
            Route::delete('meal-plan/{entry}', [MealPlanController::class, 'destroy'])->whereNumber('entry')->name('meal-plan.destroy');
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

            Route::get('portfolio-reports/dividends', [PortfolioReportsController::class, 'dividends'])->name('portfolios.reports.dividends');
            Route::get('portfolio-reports/taxes', [PortfolioReportsController::class, 'taxes'])->name('portfolios.reports.taxes');
            Route::get('portfolio-reports/analysis', [PortfolioReportsController::class, 'analysis'])->name('portfolios.reports.analysis');
            Route::get('portfolio-reports/rebalancing', [PortfolioReportsController::class, 'rebalancing'])->name('portfolios.reports.rebalancing');
            Route::put('portfolio-reports/targets', [PortfolioReportsController::class, 'saveTargets'])->name('portfolios.reports.targets');

            Route::get('alerts', [InvestingController::class, 'alerts'])->name('portfolios.alerts.index');
            Route::post('alerts', [InvestingController::class, 'storeAlert'])->name('portfolios.alerts.store');
            Route::post('alerts/{alert}/rearm', [InvestingController::class, 'rearmAlert'])->whereNumber('alert')->name('portfolios.alerts.rearm');
            Route::delete('alerts/{alert}', [InvestingController::class, 'destroyAlert'])->whereNumber('alert')->name('portfolios.alerts.destroy');

            Route::get('savings-plans', [InvestingController::class, 'savingsPlans'])->name('portfolios.savings.index');
            Route::post('savings-plans', [InvestingController::class, 'storeSavingsPlan'])->name('portfolios.savings.store');
            Route::put('savings-plans/{plan}', [InvestingController::class, 'updateSavingsPlan'])->whereNumber('plan')->name('portfolios.savings.update');
            Route::post('savings-plans/{plan}/toggle', [InvestingController::class, 'toggleSavingsPlan'])->whereNumber('plan')->name('portfolios.savings.toggle');
            Route::delete('savings-plans/{plan}', [InvestingController::class, 'destroySavingsPlan'])->whereNumber('plan')->name('portfolios.savings.destroy');

            Route::get('recommendations', [RecommendationController::class, 'index'])->name('recommendations');
            Route::get('strategy', [PortfolioController::class, 'strategy'])->name('strategy');

            Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
            Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
        });
    });
});
