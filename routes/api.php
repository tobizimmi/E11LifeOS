<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutomationEditorController;
use App\Http\Controllers\Api\V1\AutomationsController;
use App\Http\Controllers\Api\V1\BookmarksController;
use App\Http\Controllers\Api\V1\BrainController;
use App\Http\Controllers\Api\V1\BusinessBooksController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\BusinessExtrasController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\CareerController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\DocumentLinksController;
use App\Http\Controllers\Api\V1\DocumentsController;
use App\Http\Controllers\Api\V1\DrivesController;
use App\Http\Controllers\Api\V1\EmergencyController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\FinanceExtrasController;
use App\Http\Controllers\Api\V1\FocusController;
use App\Http\Controllers\Api\V1\GroupsController;
use App\Http\Controllers\Api\V1\HabitController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HouseController;
use App\Http\Controllers\Api\V1\HouseholdController;
use App\Http\Controllers\Api\V1\InvestingController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\KidController;
use App\Http\Controllers\Api\V1\KnowledgeController;
use App\Http\Controllers\Api\V1\LearningController;
use App\Http\Controllers\Api\V1\LettersController;
use App\Http\Controllers\Api\V1\LifeController;
use App\Http\Controllers\Api\V1\LoyaltyCardController;
use App\Http\Controllers\Api\V1\MarketController;
use App\Http\Controllers\Api\V1\MealPlanController;
use App\Http\Controllers\Api\V1\MealPlannerController;
use App\Http\Controllers\Api\V1\OffersController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\PantryController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Controllers\Api\V1\PortfolioController;
use App\Http\Controllers\Api\V1\PortfolioMarketController;
use App\Http\Controllers\Api\V1\PortfolioReportsController;
use App\Http\Controllers\Api\V1\PortfolioWriteController;
use App\Http\Controllers\Api\V1\ProvisionController;
use App\Http\Controllers\Api\V1\QuotesController;
use App\Http\Controllers\Api\V1\RealEstateAnalysisController;
use App\Http\Controllers\Api\V1\RealEstateApplicantsController;
use App\Http\Controllers\Api\V1\RealEstateController;
use App\Http\Controllers\Api\V1\RealEstateFinanceController;
use App\Http\Controllers\Api\V1\RealEstateHandoverController;
use App\Http\Controllers\Api\V1\RealEstateLettingController;
use App\Http\Controllers\Api\V1\RealEstateRentalController;
use App\Http\Controllers\Api\V1\RecipeCategoryController;
use App\Http\Controllers\Api\V1\RecipeController;
use App\Http\Controllers\Api\V1\RecipeImportController;
use App\Http\Controllers\Api\V1\RecommendationController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SmartHomeController;
use App\Http\Controllers\Api\V1\SpacesController;
use App\Http\Controllers\Api\V1\SplitController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\TaskDetailsController;
use App\Http\Controllers\Api\V1\TodayController;
use App\Http\Controllers\Api\V1\TripCollabController;
use App\Http\Controllers\Api\V1\TripController;
use App\Http\Controllers\Api\V1\TripExtrasController;
use App\Http\Controllers\Api\V1\VehicleController;
use App\Http\Controllers\Api\V1\WatchlistController;
use App\Http\Controllers\Api\V1\WealthPlanController;
use App\Http\Controllers\Api\V1\WishlistController;
use App\Http\Controllers\Api\V1\WorkController;
use App\Http\Controllers\SmartHomeWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Mobile API (Expo app). Sign in with POST /api/v1/auth/login, then send "Authorization: Bearer <token>".
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login')->name('login');
    // Siri/Shortcut one-action quick-add: token + text in the query, no header or JSON body needed.
    Route::get('quick/{type}', [LifeController::class, 'quickViaToken'])->where('type', 'shopping|task|note|inbox|auto')->middleware('throttle:60,1')->name('quick');
    // Home Assistant reports state changes here (secret in the address, see Smart home › Settings).
    Route::post('smart-home/webhook/{secret}', SmartHomeWebhookController::class)->middleware('throttle:240,1')->where('secret', '[A-Za-z0-9]{32,64}')->name('smart-home.webhook');

    Route::middleware(['auth.token', 'throttle:600,1', 'module'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('auth/confirm-password', [AuthController::class, 'confirmPassword'])->name('confirm-password');
        Route::delete('auth/confirm-password', [AuthController::class, 'forgetConfirmation'])->name('confirm-password.forget');

        Route::get('tasks', [LifeController::class, 'tasks'])->name('tasks.index');
        Route::post('tasks', [LifeController::class, 'storeTask'])->name('tasks.store');
        Route::put('tasks/{task}', [LifeController::class, 'updateTask'])->whereNumber('task')->name('tasks.update');
        Route::delete('tasks/{task}', [LifeController::class, 'destroyTask'])->whereNumber('task')->name('tasks.destroy');
        Route::post('tasks/{task}/toggle', [LifeController::class, 'toggleTask'])->whereNumber('task')->name('tasks.toggle');
        Route::post('tasks/order', [TaskDetailsController::class, 'reorder'])->name('tasks.order');
        Route::get('tasks/options', [TaskDetailsController::class, 'options'])->name('tasks.options');
        Route::get('task-templates', [TaskDetailsController::class, 'templates'])->name('tasks.templates');
        Route::post('task-templates/{template}/apply', [TaskDetailsController::class, 'applyTemplate'])->where('template', '[a-z0-9_-]+')->name('tasks.templates.apply');
        Route::delete('task-templates/{template}', [TaskDetailsController::class, 'destroyTemplate'])->whereNumber('template')->name('tasks.templates.destroy');
        Route::post('tasks/{task}/template', [TaskDetailsController::class, 'saveAsTemplate'])->whereNumber('task')->name('tasks.template.store');
        Route::post('task-categories', [TaskDetailsController::class, 'storeCategory'])->name('tasks.categories.store');
        Route::put('task-categories/{category}', [TaskDetailsController::class, 'updateCategory'])->where('category', '[A-Za-z0-9_-]+')->name('tasks.categories.update');
        Route::delete('task-categories/{category}', [TaskDetailsController::class, 'destroyCategory'])->where('category', '[A-Za-z0-9_-]+')->name('tasks.categories.destroy');
        Route::get('tasks/{task}', [TaskDetailsController::class, 'show'])->whereNumber('task')->name('tasks.show');
        Route::put('tasks/{task}/details', [TaskDetailsController::class, 'update'])->whereNumber('task')->name('tasks.details');
        Route::post('tasks/{task}/subtasks', [TaskDetailsController::class, 'addSubtask'])->whereNumber('task')->name('tasks.subtasks.store');
        Route::post('tasks/{task}/subtasks/{subtask}/toggle', [TaskDetailsController::class, 'toggleSubtask'])->whereNumber('task')->where('subtask', '[A-Za-z0-9]+')->name('tasks.subtasks.toggle');
        Route::delete('tasks/{task}/subtasks/{subtask}', [TaskDetailsController::class, 'destroySubtask'])->whereNumber('task')->where('subtask', '[A-Za-z0-9]+')->name('tasks.subtasks.destroy');
        Route::post('tasks/{task}/rotation/{member}', [TaskDetailsController::class, 'toggleRotation'])->whereNumber(['task', 'member'])->name('tasks.rotation');
        Route::post('tasks/{task}/pages', [TaskDetailsController::class, 'linkPage'])->whereNumber('task')->name('tasks.pages.store');
        Route::delete('tasks/{task}/pages/{page}', [TaskDetailsController::class, 'unlinkPage'])->whereNumber(['task', 'page'])->name('tasks.pages.destroy');
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
        Route::get('finance/bookings', [FinanceExtrasController::class, 'bookings'])->name('finance.bookings.index');
        Route::post('finance/bookings', [FinanceExtrasController::class, 'store'])->name('finance.bookings.store');
        Route::post('finance/bookings/receipt', [FinanceExtrasController::class, 'readReceipt'])->middleware('throttle:api-20')->name('finance.bookings.receipt');
        Route::post('finance/bookings/{transaction}/remember', [FinanceExtrasController::class, 'remember'])->whereNumber('transaction')->name('finance.bookings.remember');
        Route::put('finance/accounts/{account}', [FinanceExtrasController::class, 'renameAccount'])->whereNumber('account')->name('finance.accounts.update');
        Route::delete('finance/accounts/{account}', [FinanceExtrasController::class, 'destroyAccount'])->whereNumber('account')->name('finance.accounts.destroy');
        Route::get('finance/rules', [FinanceExtrasController::class, 'rules'])->name('finance.rules.index');
        Route::post('finance/rules/apply', [FinanceExtrasController::class, 'applyRules'])->name('finance.rules.apply');
        Route::delete('finance/rules/{rule}', [FinanceExtrasController::class, 'destroyRule'])->whereNumber('rule')->name('finance.rules.destroy');
        Route::post('finance/categorize', [FinanceExtrasController::class, 'categorizeWithAi'])->middleware('throttle:api-20')->name('finance.categorize');
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
        Route::get('trips/templates', [TripExtrasController::class, 'templates'])->name('trips.templates');
        Route::post('trips', [TripExtrasController::class, 'store'])->name('trips.store');
        Route::put('trips/{trip}', [TripExtrasController::class, 'update'])->whereNumber('trip')->name('trips.update');
        Route::delete('trips/{trip}', [TripExtrasController::class, 'destroy'])->whereNumber('trip')->name('trips.destroy');
        Route::post('trips/{trip}/template', [TripExtrasController::class, 'applyTemplate'])->whereNumber('trip')->name('trips.template');
        Route::post('trips/{trip}/unpack', [TripExtrasController::class, 'unpackAll'])->whereNumber('trip')->name('trips.unpack');
        Route::post('trip-stops/{stop}/done', [TripExtrasController::class, 'stopDone'])->whereNumber('stop')->name('trips.stops.done');
        Route::get('trips/{trip}/weather', [TripCollabController::class, 'weather'])->whereNumber('trip')->name('trips.weather.api');
        Route::get('trips/{trip}/feedback', [TripCollabController::class, 'feedback'])->whereNumber('trip')->name('trips.feedback.api');
        Route::post('trips/{trip}/feedback', [TripCollabController::class, 'comment'])->whereNumber('trip')->name('trips.feedback.comment.api');
        Route::post('trip-feedback/{feedback}/approve', [TripCollabController::class, 'approve'])->whereNumber('feedback')->name('trip-feedback.approve.api');
        Route::post('trip-feedback/{feedback}/decline', [TripCollabController::class, 'decline'])->whereNumber('feedback')->name('trip-feedback.decline.api');
        Route::delete('trip-feedback/{feedback}', [TripCollabController::class, 'destroyFeedback'])->whereNumber('feedback')->name('trip-feedback.destroy.api');
        Route::post('trips/{trip}/days/{day}/optimize', [TripCollabController::class, 'optimizeDay'])->whereNumber('trip')->name('trips.days.optimize.api');
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

        Route::get('review', [ReviewController::class, 'show'])->name('review.show');
        Route::get('brain/status', [BrainController::class, 'status'])->name('brain.status');
        Route::post('brain/ask', [BrainController::class, 'ask'])->middleware('throttle:api-20')->name('brain.ask');
        Route::post('brain/action', [BrainController::class, 'action'])->name('brain.action');
        Route::get('letters/templates', [LettersController::class, 'templates'])->name('letters.templates');
        Route::post('letters/fill', [LettersController::class, 'fill'])->name('letters.fill');
        Route::post('letters/pdf', [LettersController::class, 'pdf'])->middleware('throttle:api-20')->name('letters.pdf');

        Route::get('work', [WorkController::class, 'index'])->name('work.index');
        Route::post('work/clock', [WorkController::class, 'clock'])->name('work.clock');
        Route::post('work/leave', [WorkController::class, 'planLeave'])->name('work.leave.plan');
        Route::delete('work/leave', [WorkController::class, 'cancelLeave'])->name('work.leave.cancel');
        Route::put('work/settings', [WorkController::class, 'saveSettings'])->name('work.settings');
        Route::put('work/days/{date}', [WorkController::class, 'saveDay'])->where('date', '\d{4}-\d{2}-\d{2}')->name('work.days.save');
        Route::delete('work/days/{date}', [WorkController::class, 'clearDay'])->where('date', '\d{4}-\d{2}-\d{2}')->name('work.days.clear');

        Route::get('career', [CareerController::class, 'index'])->name('career.index');
        Route::post('career', [CareerController::class, 'store'])->name('career.store');
        Route::get('career/jobs', [CareerController::class, 'jobs'])->middleware('throttle:api-20')->name('career.jobs');
        Route::post('career/jobs/remember', [CareerController::class, 'rememberJob'])->name('career.jobs.remember');
        Route::put('career/profile', [CareerController::class, 'saveProfile'])->name('career.profile');
        Route::get('career/cv', [CareerController::class, 'cv'])->middleware('throttle:api-20')->name('career.cv');
        Route::put('career/{entry}', [CareerController::class, 'update'])->whereNumber('entry')->name('career.update');
        Route::delete('career/{entry}', [CareerController::class, 'destroy'])->whereNumber('entry')->name('career.destroy');
        Route::post('career/{entry}/status', [CareerController::class, 'status'])->whereNumber('entry')->name('career.status');

        Route::get('household', [HouseholdController::class, 'show'])->name('household.show');
        Route::get('household/mail', [HouseholdController::class, 'mail'])->name('household.mail');
        Route::post('household/mail', [HouseholdController::class, 'assignMail'])->name('household.mail.assign');
        Route::delete('household/mail', [HouseholdController::class, 'removeMail'])->name('household.mail.remove');
        Route::post('household/invite', [HouseholdController::class, 'invite'])->middleware('throttle:api-20')->name('household.invite');
        Route::post('household/addons/{addon}/toggle', [HouseholdController::class, 'toggleAddon'])->where('addon', '[a-z_]+')->name('household.addons.toggle');
        Route::post('household/members/{member}/addons/{addon}/toggle', [HouseholdController::class, 'toggleMemberAddon'])->whereNumber('member')->where('addon', '[a-z_]+')->name('household.members.addons.toggle');
        Route::post('household/members/{member}/smart-home-control/toggle', [HouseholdController::class, 'toggleMemberControl'])->whereNumber('member')->name('household.members.control.toggle');

        Route::get('document-links', [DocumentLinksController::class, 'index'])->name('documents.links.index');
        Route::post('document-links', [DocumentLinksController::class, 'store'])->name('documents.links.store');
        Route::delete('document-links/{link}', [DocumentLinksController::class, 'destroy'])->whereNumber('link')->name('documents.links.destroy');

        Route::get('groups', [GroupsController::class, 'index'])->name('groups.index');
        Route::post('groups', [GroupsController::class, 'store'])->name('groups.store');
        Route::post('groups/{group}/invite', [GroupsController::class, 'createInvite'])->whereNumber('group')->name('groups.invite');
        Route::delete('groups/{group}/invite', [GroupsController::class, 'revokeInvite'])->whereNumber('group')->name('groups.invite.revoke');
        Route::delete('groups/{group}/members/{member}', [GroupsController::class, 'removeMember'])->whereNumber(['group', 'member'])->name('groups.members.destroy');
        Route::post('groups/{group}/leave', [GroupsController::class, 'leave'])->whereNumber('group')->name('groups.leave');
        Route::delete('groups/{group}', [GroupsController::class, 'destroy'])->whereNumber('group')->name('groups.destroy');
        Route::delete('shares/{share}', [GroupsController::class, 'unshare'])->whereNumber('share')->name('shares.destroy');

        Route::get('quote-comparisons', [QuotesController::class, 'index'])->name('quote-comparisons.index');
        Route::post('quote-comparisons', [QuotesController::class, 'store'])->name('quote-comparisons.store');
        Route::get('quote-comparisons/{comparison}', [QuotesController::class, 'show'])->whereNumber('comparison')->name('quote-comparisons.show');
        Route::put('quote-comparisons/{comparison}', [QuotesController::class, 'update'])->whereNumber('comparison')->name('quote-comparisons.update');
        Route::delete('quote-comparisons/{comparison}', [QuotesController::class, 'destroy'])->whereNumber('comparison')->name('quote-comparisons.destroy');
        Route::post('quote-comparisons/{comparison}/offers', [QuotesController::class, 'addQuote'])->whereNumber('comparison')->name('quote-comparisons.offers.store');
        Route::put('quote-comparisons/{comparison}/offers/{quote}', [QuotesController::class, 'updateQuote'])->whereNumber(['comparison', 'quote'])->name('quote-comparisons.offers.update');
        Route::delete('quote-comparisons/{comparison}/offers/{quote}', [QuotesController::class, 'destroyQuote'])->whereNumber(['comparison', 'quote'])->name('quote-comparisons.offers.destroy');
        Route::post('quote-comparisons/{comparison}/choose', [QuotesController::class, 'choose'])->whereNumber('comparison')->name('quote-comparisons.choose');

        Route::get('provision/insurance', [ProvisionController::class, 'insurance'])->name('provision.insurance');
        Route::post('provision/insurance', [ProvisionController::class, 'storeInsurance'])->name('provision.insurance.store');
        Route::post('provision/answer', [ProvisionController::class, 'answer'])->name('provision.answer');
        Route::put('provision/contracts/{item}', [ProvisionController::class, 'assign'])->whereNumber('item')->name('provision.assign');
        Route::get('provision/retirement', [ProvisionController::class, 'retirement'])->name('provision.retirement');
        Route::put('provision/retirement', [ProvisionController::class, 'updateRetirement'])->name('provision.retirement.update');
        Route::delete('provision/retirement', [ProvisionController::class, 'resetRetirement'])->name('provision.retirement.reset');

        Route::get('automations', [AutomationsController::class, 'index'])->name('automations.index');
        Route::get('automations/catalog', [AutomationEditorController::class, 'catalog'])->name('automations.catalog');
        Route::post('automations', [AutomationEditorController::class, 'store'])->name('automations.store');
        Route::post('automations/test', [AutomationEditorController::class, 'test'])->middleware('throttle:api-30')->name('automations.test');
        Route::get('automations/{automation}', [AutomationEditorController::class, 'show'])->whereNumber('automation')->name('automations.show');
        Route::put('automations/{automation}', [AutomationEditorController::class, 'update'])->whereNumber('automation')->name('automations.update');
        Route::post('automations/draft', [AutomationsController::class, 'draft'])->middleware('throttle:api-20')->name('automations.draft');
        Route::post('automations/templates/{template}', [AutomationsController::class, 'fromTemplate'])->name('automations.template');
        Route::post('automations/packages/{package}', [AutomationsController::class, 'installPackage'])->name('automations.package');
        Route::post('automations/modes/{key}', [AutomationsController::class, 'mode'])->where('key', '[a-z0-9_-]+')->name('automations.mode');
        Route::post('automations/{automation}/toggle', [AutomationsController::class, 'toggle'])->whereNumber('automation')->name('automations.toggle');
        Route::post('automations/{automation}/run', [AutomationsController::class, 'run'])->whereNumber('automation')->middleware('throttle:api-30')->name('automations.run');
        Route::get('automations/{automation}/log', [AutomationsController::class, 'log'])->whereNumber('automation')->name('automations.log');
        Route::delete('automations/{automation}', [AutomationsController::class, 'destroy'])->whereNumber('automation')->name('automations.destroy');

        Route::get('real-estate', [RealEstateController::class, 'index'])->name('real-estate.index');
        Route::post('real-estate', [RealEstateController::class, 'store'])->name('real-estate.store');
        Route::get('real-estate/{estate}', [RealEstateController::class, 'show'])->whereNumber('estate')->name('real-estate.show');
        Route::put('real-estate/{estate}', [RealEstateController::class, 'update'])->whereNumber('estate')->name('real-estate.update');
        Route::delete('real-estate/{estate}', [RealEstateController::class, 'destroy'])->whereNumber('estate')->name('real-estate.destroy');
        Route::post('real-estate/{estate}/items', [RealEstateController::class, 'addItem'])->whereNumber('estate')->name('real-estate.items.store');
        Route::put('real-estate/{estate}/items/{item}', [RealEstateController::class, 'updateItem'])->whereNumber(['estate', 'item'])->name('real-estate.items.update');
        Route::delete('real-estate/{estate}/items/{item}', [RealEstateController::class, 'destroyItem'])->whereNumber(['estate', 'item'])->name('real-estate.items.destroy');
        Route::post('real-estate/{estate}/invoices', [RealEstateController::class, 'addInvoice'])->whereNumber('estate')->name('real-estate.invoices.store');
        Route::put('real-estate/{estate}/invoices/{invoice}', [RealEstateController::class, 'updateInvoice'])->whereNumber(['estate', 'invoice'])->name('real-estate.invoices.update');
        Route::post('real-estate/{estate}/invoices/{invoice}/paid', [RealEstateController::class, 'togglePaid'])->whereNumber(['estate', 'invoice'])->name('real-estate.invoices.paid');
        Route::delete('real-estate/{estate}/invoices/{invoice}', [RealEstateController::class, 'destroyInvoice'])->whereNumber(['estate', 'invoice'])->name('real-estate.invoices.destroy');
        Route::get('real-estate/analyses', [RealEstateAnalysisController::class, 'index'])->name('real-estate.analyses.index');
        Route::post('real-estate/analyses/calculate', [RealEstateAnalysisController::class, 'calculate'])->middleware('throttle:api-60')->name('real-estate.analyses.calculate');
        Route::get('real-estate/analyses/prefill/{estate}', [RealEstateAnalysisController::class, 'prefill'])->whereNumber('estate')->name('real-estate.analyses.prefill');
        Route::post('real-estate/analyses', [RealEstateAnalysisController::class, 'store'])->name('real-estate.analyses.store');
        Route::get('real-estate/analyses/{analysis}', [RealEstateAnalysisController::class, 'show'])->whereNumber('analysis')->name('real-estate.analyses.show');
        Route::put('real-estate/analyses/{analysis}', [RealEstateAnalysisController::class, 'update'])->whereNumber('analysis')->name('real-estate.analyses.update');
        Route::delete('real-estate/analyses/{analysis}', [RealEstateAnalysisController::class, 'destroy'])->whereNumber('analysis')->name('real-estate.analyses.destroy');
        Route::get('real-estate/{estate}/rental', [RealEstateRentalController::class, 'show'])->whereNumber('estate')->name('real-estate.rental.show');
        Route::post('real-estate/{estate}/tenants', [RealEstateRentalController::class, 'storeTenant'])->whereNumber('estate')->name('real-estate.tenants.store');
        Route::put('real-estate/{estate}/tenants/{tenant}', [RealEstateRentalController::class, 'updateTenant'])->whereNumber(['estate', 'tenant'])->name('real-estate.tenants.update');
        Route::delete('real-estate/{estate}/tenants/{tenant}', [RealEstateRentalController::class, 'destroyTenant'])->whereNumber(['estate', 'tenant'])->name('real-estate.tenants.destroy');
        Route::post('real-estate/{estate}/tenants/{tenant}/payments', [RealEstateRentalController::class, 'togglePayment'])->whereNumber(['estate', 'tenant'])->name('real-estate.tenants.payments');
        Route::get('real-estate/{estate}/utility', [RealEstateLettingController::class, 'utility'])->whereNumber('estate')->name('real-estate.utility.show');
        Route::put('real-estate/{estate}/utility', [RealEstateLettingController::class, 'saveUtility'])->whereNumber('estate')->name('real-estate.utility.save');
        Route::post('real-estate/{estate}/utility/{year}/sent', [RealEstateLettingController::class, 'utilitySent'])->whereNumber(['estate', 'year'])->name('real-estate.utility.sent');
        Route::get('real-estate/{estate}/utility/{year}/tenants/{tenant}/pdf', [RealEstateLettingController::class, 'utilityPdf'])->whereNumber(['estate', 'year', 'tenant'])->middleware('throttle:api-30')->name('real-estate.utility.pdf');
        Route::get('real-estate/{estate}/tenants/{tenant}/lease', [RealEstateLettingController::class, 'leasePdf'])->whereNumber(['estate', 'tenant'])->middleware('throttle:api-30')->name('real-estate.tenants.lease');
        Route::get('real-estate/{estate}/tenants/{tenant}/dunning', [RealEstateLettingController::class, 'dunningPdf'])->whereNumber(['estate', 'tenant'])->middleware('throttle:api-30')->name('real-estate.tenants.dunning');
        Route::post('real-estate/{estate}/tenants/{tenant}/index-rent/preview', [RealEstateLettingController::class, 'indexPreview'])->whereNumber(['estate', 'tenant'])->name('real-estate.tenants.index.preview');
        Route::post('real-estate/{estate}/tenants/{tenant}/index-rent/apply', [RealEstateLettingController::class, 'indexApply'])->whereNumber(['estate', 'tenant'])->name('real-estate.tenants.index.apply');
        Route::get('real-estate/{estate}/tenants/{tenant}/index-rent/pdf', [RealEstateLettingController::class, 'indexPdf'])->whereNumber(['estate', 'tenant'])->middleware('throttle:api-30')->name('real-estate.tenants.index.pdf');
        Route::get('real-estate/{estate}/tenants/{tenant}/portal', [RealEstateLettingController::class, 'portal'])->whereNumber(['estate', 'tenant'])->name('real-estate.tenants.portal.show');
        Route::post('real-estate/{estate}/tenants/{tenant}/portal', [RealEstateLettingController::class, 'createPortal'])->whereNumber(['estate', 'tenant'])->name('real-estate.tenants.portal.create');
        Route::delete('real-estate/{estate}/tenants/{tenant}/portal', [RealEstateLettingController::class, 'revokePortal'])->whereNumber(['estate', 'tenant'])->name('real-estate.tenants.portal.revoke');
        Route::get('real-estate/{estate}/handovers', [RealEstateHandoverController::class, 'index'])->whereNumber('estate')->name('real-estate.handovers.index');
        Route::post('real-estate/{estate}/handovers', [RealEstateHandoverController::class, 'store'])->whereNumber('estate')->name('real-estate.handovers.store');
        Route::put('real-estate/{estate}/handovers/{handover}', [RealEstateHandoverController::class, 'update'])->whereNumber(['estate', 'handover'])->name('real-estate.handovers.update');
        Route::delete('real-estate/{estate}/handovers/{handover}', [RealEstateHandoverController::class, 'destroy'])->whereNumber(['estate', 'handover'])->name('real-estate.handovers.destroy');
        Route::post('real-estate/{estate}/handovers/{handover}/photos', [RealEstateHandoverController::class, 'addPhoto'])->whereNumber(['estate', 'handover'])->name('real-estate.handovers.photos.store');
        Route::delete('real-estate/{estate}/handovers/{handover}/photos/{index}', [RealEstateHandoverController::class, 'removePhoto'])->whereNumber(['estate', 'handover', 'index'])->name('real-estate.handovers.photos.destroy');
        Route::get('real-estate/{estate}/handovers/{handover}/photos/{index}', [RealEstateHandoverController::class, 'photo'])->whereNumber(['estate', 'handover', 'index'])->middleware('throttle:api-60')->name('real-estate.handovers.photos.show');
        Route::get('real-estate/{estate}/handovers/{handover}/pdf', [RealEstateHandoverController::class, 'pdf'])->whereNumber(['estate', 'handover'])->middleware('throttle:api-30')->name('real-estate.handovers.pdf');
        Route::get('real-estate/{estate}/applicants', [RealEstateApplicantsController::class, 'index'])->whereNumber('estate')->name('real-estate.applicants.index');
        Route::post('real-estate/{estate}/applicants/open', [RealEstateApplicantsController::class, 'open'])->whereNumber('estate')->name('real-estate.applicants.open');
        Route::post('real-estate/{estate}/applicants/close', [RealEstateApplicantsController::class, 'close'])->whereNumber('estate')->name('real-estate.applicants.close');
        Route::put('real-estate/{estate}/applicants/{applicant}', [RealEstateApplicantsController::class, 'update'])->whereNumber(['estate', 'applicant'])->name('real-estate.applicants.update');
        Route::delete('real-estate/{estate}/applicants/{applicant}', [RealEstateApplicantsController::class, 'destroy'])->whereNumber(['estate', 'applicant'])->name('real-estate.applicants.destroy');
        Route::post('real-estate/{estate}/applicants/{applicant}/accept', [RealEstateApplicantsController::class, 'accept'])->whereNumber(['estate', 'applicant'])->name('real-estate.applicants.accept');
        Route::get('real-estate/{estate}/applicants/{applicant}/pdf', [RealEstateApplicantsController::class, 'pdf'])->whereNumber(['estate', 'applicant'])->middleware('throttle:api-30')->name('real-estate.applicants.pdf');
        Route::get('real-estate/{estate}/finance', [RealEstateFinanceController::class, 'show'])->whereNumber('estate')->name('real-estate.finance.show');
        Route::post('real-estate/{estate}/loans', [RealEstateFinanceController::class, 'storeLoan'])->whereNumber('estate')->name('real-estate.loans.store');
        Route::put('real-estate/{estate}/loans/{loan}', [RealEstateFinanceController::class, 'updateLoan'])->whereNumber(['estate', 'loan'])->name('real-estate.loans.update');
        Route::delete('real-estate/{estate}/loans/{loan}', [RealEstateFinanceController::class, 'destroyLoan'])->whereNumber(['estate', 'loan'])->name('real-estate.loans.destroy');
        Route::post('real-estate/{estate}/subsidies', [RealEstateFinanceController::class, 'storeSubsidy'])->whereNumber('estate')->name('real-estate.subsidies.store');
        Route::put('real-estate/{estate}/subsidies/{subsidy}', [RealEstateFinanceController::class, 'updateSubsidy'])->whereNumber(['estate', 'subsidy'])->name('real-estate.subsidies.update');
        Route::post('real-estate/{estate}/subsidies/{subsidy}/status', [RealEstateFinanceController::class, 'subsidyStatus'])->whereNumber(['estate', 'subsidy'])->name('real-estate.subsidies.status');
        Route::delete('real-estate/{estate}/subsidies/{subsidy}', [RealEstateFinanceController::class, 'destroySubsidy'])->whereNumber(['estate', 'subsidy'])->name('real-estate.subsidies.destroy');
        Route::post('real-estate/{estate}/draws', [RealEstateFinanceController::class, 'storeDraw'])->whereNumber('estate')->name('real-estate.draws.store');
        Route::put('real-estate/{estate}/draws/{draw}', [RealEstateFinanceController::class, 'updateDraw'])->whereNumber(['estate', 'draw'])->name('real-estate.draws.update');
        Route::post('real-estate/{estate}/draws/{draw}/done', [RealEstateFinanceController::class, 'drawDone'])->whereNumber(['estate', 'draw'])->name('real-estate.draws.done');
        Route::delete('real-estate/{estate}/draws/{draw}', [RealEstateFinanceController::class, 'destroyDraw'])->whereNumber(['estate', 'draw'])->name('real-estate.draws.destroy');

        Route::get('support', [SupportController::class, 'index'])->name('support.index');
        Route::post('support', [SupportController::class, 'store'])->middleware('throttle:api-20')->name('support.store');
        Route::get('support/{ticket}', [SupportController::class, 'show'])->whereNumber('ticket')->name('support.show');
        Route::post('support/{ticket}/reply', [SupportController::class, 'reply'])->whereNumber('ticket')->middleware('throttle:api-20')->name('support.reply');
        Route::post('support/{ticket}/solve', [SupportController::class, 'solve'])->whereNumber('ticket')->name('support.solve');
        Route::post('support/{ticket}/rate', [SupportController::class, 'rate'])->whereNumber('ticket')->name('support.rate');
        Route::get('support/{ticket}/messages/{message}/files/{index}', [SupportController::class, 'file'])->whereNumber(['ticket', 'message', 'index'])->middleware('throttle:api-60')->name('support.file');

        Route::get('business', [BusinessController::class, 'index'])->name('business.index');
        Route::post('business/invoices', [BusinessController::class, 'storeInvoice'])->name('business.invoices.store');
        Route::get('business/invoices/{invoice}', [BusinessController::class, 'showInvoice'])->whereNumber('invoice')->name('business.invoices.show');
        Route::post('business/invoices/{invoice}/issue', [BusinessController::class, 'issueInvoice'])->whereNumber('invoice')->name('business.invoices.issue');
        Route::post('business/invoices/{invoice}/pay', [BusinessController::class, 'pay'])->whereNumber('invoice')->name('business.invoices.pay');
        Route::get('business/invoices/{invoice}/pdf', [BusinessController::class, 'pdf'])->whereNumber('invoice')->middleware('throttle:api-30')->name('business.invoices.pdf');
        Route::delete('business/invoices/{invoice}', [BusinessController::class, 'destroyInvoice'])->whereNumber('invoice')->name('business.invoices.destroy');
        Route::post('business/customers', [BusinessController::class, 'storeCustomer'])->name('business.customers.store');
        Route::put('business/customers/{customer}', [BusinessController::class, 'updateCustomer'])->whereNumber('customer')->name('business.customers.update');
        Route::delete('business/customers/{customer}', [BusinessController::class, 'destroyCustomer'])->whereNumber('customer')->name('business.customers.destroy');
        Route::get('business/time', [BusinessController::class, 'time'])->name('business.time');
        Route::post('business/time/start', [BusinessController::class, 'startTimer'])->name('business.time.start');
        Route::post('business/time/stop', [BusinessController::class, 'stopTimer'])->name('business.time.stop');
        Route::delete('business/time/{entry}', [BusinessController::class, 'destroyTime'])->whereNumber('entry')->name('business.time.destroy');
        Route::post('business/time', [BusinessExtrasController::class, 'storeTime'])->name('business.time.store');
        Route::put('business/time/{entry}', [BusinessExtrasController::class, 'updateTime'])->whereNumber('entry')->name('business.time.update');
        Route::post('business/time/bill', [BusinessExtrasController::class, 'billTime'])->name('business.time.bill');
        Route::get('business/bookings', [BusinessBooksController::class, 'bookings'])->name('business.bookings.index');
        Route::post('business/bookings', [BusinessBooksController::class, 'store'])->name('business.bookings.store');
        Route::post('business/bookings/read-receipt', [BusinessBooksController::class, 'readReceipt'])->middleware('throttle:api-20')->name('business.bookings.read');
        Route::post('business/bookings/{entry}', [BusinessBooksController::class, 'update'])->whereNumber('entry')->name('business.bookings.update');
        Route::get('business/bookings/{entry}/receipt', [BusinessBooksController::class, 'receipt'])->whereNumber('entry')->middleware('throttle:api-30')->name('business.bookings.receipt');
        Route::delete('business/bookings/{entry}', [BusinessBooksController::class, 'destroy'])->whereNumber('entry')->name('business.bookings.destroy');
        Route::get('business/report', [BusinessBooksController::class, 'report'])->name('business.report');
        Route::get('business/report/download', [BusinessBooksController::class, 'download'])->middleware('throttle:api-30')->name('business.report.download');
        Route::get('business/products', [BusinessExtrasController::class, 'products'])->name('business.products.index');
        Route::post('business/products', [BusinessExtrasController::class, 'storeProduct'])->name('business.products.store');
        Route::put('business/products/{product}', [BusinessExtrasController::class, 'updateProduct'])->whereNumber('product')->name('business.products.update');
        Route::delete('business/products/{product}', [BusinessExtrasController::class, 'destroyProduct'])->whereNumber('product')->name('business.products.destroy');
        Route::get('business/recurring', [BusinessExtrasController::class, 'recurring'])->name('business.recurring.index');
        Route::post('business/invoices/{invoice}/recurring', [BusinessExtrasController::class, 'storeRecurring'])->whereNumber('invoice')->name('business.recurring.store');
        Route::put('business/recurring/{template}', [BusinessExtrasController::class, 'updateRecurring'])->whereNumber('template')->name('business.recurring.update');
        Route::post('business/recurring/{template}/run', [BusinessExtrasController::class, 'runRecurring'])->whereNumber('template')->name('business.recurring.run');
        Route::delete('business/recurring/{template}', [BusinessExtrasController::class, 'destroyRecurring'])->whereNumber('template')->name('business.recurring.destroy');

        Route::get('spaces', [SpacesController::class, 'index'])->name('spaces.index');
        Route::post('spaces', [SpacesController::class, 'store'])->name('spaces.store');
        Route::get('spaces/{space}', [SpacesController::class, 'show'])->whereNumber('space')->name('spaces.show');
        Route::put('spaces/{space}', [SpacesController::class, 'update'])->whereNumber('space')->name('spaces.update');
        Route::delete('spaces/{space}', [SpacesController::class, 'destroy'])->whereNumber('space')->name('spaces.destroy');
        Route::post('spaces/{space}/tasks', [SpacesController::class, 'addTask'])->whereNumber('space')->name('spaces.tasks.store');
        Route::put('tasks/{task}/space', [SpacesController::class, 'assignTask'])->whereNumber('task')->name('tasks.space');

        Route::get('bookmarks', [BookmarksController::class, 'index'])->name('bookmarks.index');
        Route::post('bookmarks/preview', [BookmarksController::class, 'preview'])->middleware('throttle:api-30')->name('bookmarks.preview');
        Route::post('bookmarks', [BookmarksController::class, 'store'])->name('bookmarks.store');
        Route::put('bookmarks/{bookmark}', [BookmarksController::class, 'update'])->whereNumber('bookmark')->name('bookmarks.update');
        Route::delete('bookmarks/{bookmark}', [BookmarksController::class, 'destroy'])->whereNumber('bookmark')->name('bookmarks.destroy');
        Route::post('bookmarks/{bookmark}/read', [BookmarksController::class, 'toggleRead'])->whereNumber('bookmark')->name('bookmarks.read');
        Route::post('bookmarks/{bookmark}/summary', [BookmarksController::class, 'summarise'])->whereNumber('bookmark')->middleware('throttle:api-20')->name('bookmarks.summary');
        Route::post('bookmarks/{bookmark}/wishlist', [BookmarksController::class, 'toWishlist'])->whereNumber('bookmark')->name('bookmarks.wishlist');
        Route::post('bookmarks/{bookmark}/place', [BookmarksController::class, 'toPlace'])->whereNumber('bookmark')->name('bookmarks.place');
        Route::post('bookmark-categories', [BookmarksController::class, 'storeCategory'])->name('bookmarks.categories.store');
        Route::delete('bookmark-categories/{category}', [BookmarksController::class, 'destroyCategory'])->whereNumber('category')->name('bookmarks.categories.destroy');

        Route::get('drives', [DrivesController::class, 'index'])->name('drives.index');
        Route::post('drives', [DrivesController::class, 'store'])->name('drives.store');
        Route::put('drives/{drive}', [DrivesController::class, 'update'])->whereNumber('drive')->name('drives.update');
        Route::delete('drives/{drive}', [DrivesController::class, 'destroy'])->whereNumber('drive')->name('drives.destroy');
        Route::get('drives/{drive}/browse', [DrivesController::class, 'browse'])->whereNumber('drive')->middleware('throttle:api-60')->name('drives.browse');
        Route::get('drives/{drive}/download', [DrivesController::class, 'download'])->whereNumber('drive')->middleware('throttle:api-60')->name('drives.download');
        Route::post('drives/{drive}/upload', [DrivesController::class, 'upload'])->whereNumber('drive')->middleware('throttle:api-30')->name('drives.upload');

        // Document archive (paperless) and scanning.
        Route::get('documents', [DocumentsController::class, 'index'])->name('documents.index');
        Route::post('documents/scan/preview', [DocumentsController::class, 'preview'])->middleware('throttle:api-60')->name('documents.scan.preview');
        Route::post('documents/scan', [DocumentsController::class, 'scan'])->middleware('throttle:api-20')->name('documents.scan');
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
            Route::get('recipes/import', [RecipeImportController::class, 'sources'])->name('recipes.import.sources');
            Route::post('recipes/import', [RecipeImportController::class, 'import'])->middleware('throttle:api-20')->name('recipes.import');
            Route::get('recipe-categories', [RecipeCategoryController::class, 'index'])->name('recipe-categories.index');
            Route::post('recipe-categories', [RecipeCategoryController::class, 'store'])->name('recipe-categories.store');
            Route::put('recipe-categories/{category}', [RecipeCategoryController::class, 'update'])->whereNumber('category')->name('recipe-categories.update');
            Route::post('recipe-categories/{category}/move', [RecipeCategoryController::class, 'move'])->whereNumber('category')->name('recipe-categories.move');
            Route::delete('recipe-categories/{category}', [RecipeCategoryController::class, 'destroy'])->whereNumber('category')->name('recipe-categories.destroy');
            Route::get('recipes', [RecipeController::class, 'index'])->name('recipes.index');
            Route::post('recipes', [RecipeController::class, 'store'])->name('recipes.store');
            Route::get('recipes/{recipe}', [RecipeController::class, 'show'])->whereNumber('recipe')->name('recipes.show');
            Route::get('recipes/{recipe}/image', [RecipeController::class, 'image'])->whereNumber('recipe')->name('recipes.image.api');
            Route::put('recipes/{recipe}', [RecipeController::class, 'update'])->whereNumber('recipe')->name('recipes.update');
            Route::delete('recipes/{recipe}', [RecipeController::class, 'destroy'])->whereNumber('recipe')->name('recipes.destroy');

            Route::get('meal-plan', [MealPlanController::class, 'index'])->name('meal-plan.index');
            Route::post('meal-plan', [MealPlanController::class, 'store'])->name('meal-plan.store');
            Route::get('meal-planner', [MealPlannerController::class, 'show'])->name('meal-planner.show');
            Route::put('meal-planner/preferences', [MealPlannerController::class, 'savePreferences'])->name('meal-planner.preferences');
            Route::post('meal-planner/generate', [MealPlannerController::class, 'generate'])->middleware('throttle:api-20')->name('meal-planner.generate');
            Route::post('meal-planner/add', [MealPlannerController::class, 'addToPlan'])->name('meal-planner.add');
            Route::post('meal-planner/shopping-list', [MealPlannerController::class, 'createList'])->name('meal-planner.shopping-list');
            Route::get('meal-plan/ingredients', [MealPlanController::class, 'ingredients'])->name('meal-plan.ingredients');
            Route::post('meal-plan/shopping', [MealPlanController::class, 'toShopping'])->name('meal-plan.shopping');
            Route::put('meal-plan/{entry}', [MealPlanController::class, 'update'])->whereNumber('entry')->name('meal-plan.update');
            Route::delete('meal-plan/{entry}', [MealPlanController::class, 'destroy'])->whereNumber('entry')->name('meal-plan.destroy');
        });

        Route::middleware('addon:portfolio')->group(function () {
            Route::get('portfolios', [PortfolioController::class, 'index'])->name('portfolios.index');
            Route::post('portfolios', [PortfolioWriteController::class, 'storePortfolio'])->name('portfolios.store');
            Route::put('portfolios/{portfolio}', [PortfolioWriteController::class, 'updatePortfolio'])->whereNumber('portfolio')->name('portfolios.update');
            Route::delete('portfolios/{portfolio}', [PortfolioWriteController::class, 'destroyPortfolio'])->whereNumber('portfolio')->name('portfolios.destroy');
            Route::post('portfolios/{portfolio}/transactions', [PortfolioWriteController::class, 'storeTransaction'])->whereNumber('portfolio')->name('portfolios.transactions.store');
            Route::put('transactions-portfolio/{transaction}', [PortfolioWriteController::class, 'updateTransaction'])->whereNumber('transaction')->name('portfolios.transactions.update');
            Route::delete('transactions-portfolio/{transaction}', [PortfolioWriteController::class, 'destroyTransaction'])->whereNumber('transaction')->name('portfolios.transactions.destroy');
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

            Route::get('portfolio-reports/market', [PortfolioMarketController::class, 'market'])->middleware('throttle:api-30')->name('portfolios.reports.market');
            Route::get('portfolio-reports/news', [PortfolioMarketController::class, 'news'])->name('portfolios.reports.news');
            Route::post('portfolio-reports/news/refresh', [PortfolioMarketController::class, 'refreshNews'])->middleware('throttle:api-20')->name('portfolios.reports.news.refresh');
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
