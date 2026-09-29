<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\BookkeepingReceiptController;
use App\Http\Controllers\BusinessReportController;
use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\DataExportController;
use App\Http\Controllers\EmergencyFolderController;
use App\Http\Controllers\ExternalDocumentController;
use App\Http\Controllers\FinanceReportPdfController;
use App\Http\Controllers\FinanceTaxExportController;
use App\Http\Controllers\InstagramPushController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\ItemLinkController;
use App\Http\Controllers\OfflineCaptureController;
use App\Http\Controllers\PublicCollectionController;
use App\Http\Controllers\PublicDocumentController;
use App\Http\Controllers\PublicRecipeController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\RealEstateReceiptController;
use App\Http\Controllers\RecipeImageController;
use App\Http\Controllers\RecipePdfController;
use App\Http\Controllers\RentalDocumentController;
use App\Http\Controllers\XRechnungController;
use App\Models\Collection;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome');

Volt::route('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::get('profile/export', DataExportController::class)
    ->middleware(['auth'])
    ->name('profile.export');

Route::middleware(['auth'])->group(function () {
    Volt::route('household', 'household.show')->name('household.show');
    Volt::route('inbox', 'inbox.index')->name('inbox.index');
    Volt::route('notifications', 'notifications.index')->name('notifications.index');
    Volt::route('calendar', 'calendar.index')->name('calendar.index');
    Volt::route('net-worth', 'net-worth.index')->name('net-worth.index');
    Volt::route('contracts', 'contracts.index')->name('contracts.index');
    Volt::route('cashflow', 'cashflow.index')->name('cashflow.index');
    Volt::route('knowledge', 'knowledge.index')->name('knowledge.index');
    Volt::route('emergency', 'emergency.index')->name('emergency.index');
    Volt::route('vehicles', 'vehicles.index')->name('vehicles.index');
    Volt::route('vehicles/{vehicle}', 'vehicles.show')->whereNumber('vehicle')->name('vehicles.show');
    Route::get('emergency/pdf', [EmergencyFolderController::class, 'pdf'])->name('emergency.pdf');
    Volt::route('quotes', 'quotes.index')->name('quotes.index');
    Volt::route('quotes/{comparison}', 'quotes.show')->whereNumber('comparison')->name('quotes.show');
    Volt::route('letters', 'letters.index')->name('letters.index');
    Volt::route('ask', 'brain.ask')->name('brain.ask');
    Volt::route('pages/{item?}', 'pages.index')->whereNumber('item')->name('pages.index');
    // Notes became pages: keep old bookmarks (/notes?note=ID) working.
    Route::get('notes', function (Request $request) {
        $noteId = $request->query('note');

        return is_string($noteId) && ctype_digit($noteId)
            ? redirect()->route('pages.index', ['item' => (int) $noteId])
            : redirect()->route('pages.index', $request->only(['new', 'tag', 'q']));
    })->name('notes.index');
    Route::get('items/{item}', ItemLinkController::class)->whereNumber('item')->name('items.show');
    Route::post('push/subscriptions', [PushController::class, 'store'])->name('push.subscribe');
    Route::delete('push/subscriptions', [PushController::class, 'destroy'])->name('push.unsubscribe');
    Route::get('push/pending', [PushController::class, 'pending'])->name('push.pending');
    Route::get('offline/token', [OfflineCaptureController::class, 'token'])->name('offline.token');
    Route::post('offline/capture', [OfflineCaptureController::class, 'store'])->middleware('throttle:120,1')->name('offline.capture');
    Route::get('attachments/{attachment}/{name?}', AttachmentController::class)->whereNumber('attachment')->name('attachments.show');
    Volt::route('collections', 'collections.index')->name('collections.index');
    Volt::route('collections/{collection}', 'collections.show')->whereNumber('collection')->name('collections.show');
    // Old task URL: the list views moved to the tasks page, board/calendar stay with the collection.
    Route::get('todos', function (Request $request) {
        $household = $request->user()->currentHousehold();
        abort_if(! $household, 403);

        return $request->hasAny(['view', 'month'])
            ? redirect()->route('collections.show', ['collection' => Collection::tasksFor($household->id)] + $request->only(['view', 'entry', 'month']))
            : redirect()->route('tasks.index', $request->filled('entry') ? ['task' => $request->query('entry')] : []);
    })->name('todos.index');
    Volt::route('tasks', 'tasks.index')->name('tasks.index');
    Volt::route('finance', 'finance.index')->name('finance.index')->middleware('addon:finance');
    Route::get('finance/tax/{year}.csv', FinanceTaxExportController::class)->whereNumber('year')->name('finance.tax-export')->middleware('addon:finance');
    Route::get('finance/report.pdf', FinanceReportPdfController::class)->name('finance.report')->middleware('addon:finance');
    Route::get('paperless/{instance}/documents/{document}/{variant}', [ExternalDocumentController::class, 'paperless'])->whereNumber(['instance', 'document'])->whereIn('variant', ['thumb', 'preview', 'download'])->name('paperless.document')->middleware('throttle:120,1');
    Route::get('external-links/{link}/file', [ExternalDocumentController::class, 'file'])->whereNumber('link')->name('external-links.file');
    Volt::route('real-estate', 'real-estate.index')->name('real-estate.index')->middleware('addon:real_estate');
    Volt::route('real-estate/{realEstate}', 'real-estate.show')->whereNumber('realEstate')->name('real-estate.show')->middleware('addon:real_estate');
    Route::get('real-estate/invoices/{invoice}/receipt', RealEstateReceiptController::class)->whereNumber('invoice')->name('real-estate.receipt')->middleware('addon:real_estate');
    Route::middleware('addon:real_estate')->controller(RentalDocumentController::class)->group(function () {
        Route::get('real-estate/tenants/{tenant}/lease', 'lease')->whereNumber('tenant')->name('real-estate.lease');
        Route::get('real-estate/utility-statements/{statement}/tenants/{tenant}', 'utilityStatement')->whereNumber(['statement', 'tenant'])->name('real-estate.utility-statement');
        Route::get('real-estate/applicants/{applicant}/pdf', 'applicant')->whereNumber('applicant')->name('real-estate.applicant-pdf');
        Route::get('real-estate/tenants/{tenant}/index-rent', 'indexRent')->whereNumber('tenant')->name('real-estate.index-rent');
        Route::get('real-estate/tenants/{tenant}/dunning', 'dunning')->whereNumber('tenant')->name('real-estate.dunning');
        Route::get('real-estate/handovers/{handover}/pdf', 'handover')->whereNumber('handover')->name('real-estate.handover-pdf');
        Route::get('real-estate/handovers/{handover}/photos/{index}', 'handoverPhoto')->whereNumber(['handover', 'index'])->name('real-estate.handover-photo');
        Route::get('real-estate/{realEstate}/self-disclosure', 'blankSelfDisclosure')->whereNumber('realEstate')->name('real-estate.self-disclosure-form');
    });
    Volt::route('business', 'business.index')->name('business.index')->middleware('addon:business');
    Volt::route('business/invoices/create', 'business.invoice-form')->name('business.invoices.create')->middleware('addon:business');
    Volt::route('business/invoices/{invoice}/edit', 'business.invoice-form')->whereNumber('invoice')->name('business.invoices.edit')->middleware('addon:business');
    Route::get('business/invoices/{invoice}/pdf', InvoicePdfController::class)->whereNumber('invoice')->name('business.invoices.pdf')->middleware('addon:business');
    Route::get('business/invoices/{invoice}/xrechnung', XRechnungController::class)->whereNumber('invoice')->name('business.invoices.xrechnung')->middleware('addon:business');
    Volt::route('business/recurring', 'business.recurring')->name('business.recurring')->middleware('addon:business');
    Volt::route('business/time', 'business.time')->name('business.time')->middleware('addon:business');
    Volt::route('business/receipts', 'business.bookings')->name('business.bookings')->middleware('addon:business');
    Route::get('business/receipts/{entry}/file', BookkeepingReceiptController::class)->whereNumber('entry')->name('business.receipts.show')->middleware('addon:business');
    Volt::route('business/customers', 'business.customers')->name('business.customers')->middleware('addon:business');
    Volt::route('business/products', 'business.products')->name('business.products')->middleware('addon:business');
    Volt::route('business/report', 'business.report')->name('business.report')->middleware('addon:business');
    Route::get('business/report/download', BusinessReportController::class)->name('business.report.download')->middleware('addon:business');
    Volt::route('business/settings', 'business.settings')->name('business.settings')->middleware('addon:business');
    Volt::route('shopping-list', 'shopping-list.index')->name('shopping-list.index');
    Volt::route('shopping-list/offers', 'shopping-list.offers')->name('shopping-list.offers');
});

// Recipes addon (can be switched off per household on the Household page).
Route::middleware(['auth', 'addon:recipes'])->group(function () {
    Volt::route('recipes', 'recipes.index')->name('recipes.index');
    Volt::route('recipes/create', 'recipes.form')->name('recipes.create');
    Volt::route('recipes/import', 'recipes.import')->name('recipes.import');
    Volt::route('recipes/categories', 'recipes.categories')->name('recipes.categories');
    Volt::route('recipes/plan', 'recipes.plan')->name('recipes.plan');
    Volt::route('recipes/{recipe}', 'recipes.show')->whereNumber('recipe')->name('recipes.show');
    Volt::route('recipes/{recipe}/edit', 'recipes.form')->whereNumber('recipe')->name('recipes.edit');
    Volt::route('recipes/{recipe}/cook', 'recipes.cook')->whereNumber('recipe')->name('recipes.cook');
    Route::get('recipes/{recipe}/pdf', RecipePdfController::class)->whereNumber('recipe')->name('recipes.pdf');
    Route::get('recipes/{recipe}/image', RecipeImageController::class)->whereNumber('recipe')->name('recipes.image');
    Route::get('recipes/{recipe}/photos/{index}', RecipeImageController::class)->whereNumber(['recipe', 'index'])->name('recipes.photo');
});

// Portfolio manager addon (personal securities accounts, prices, imports).
Route::middleware(['auth', 'addon:portfolio'])->prefix('portfolio')->name('portfolio.')->group(function () {
    Volt::route('/', 'portfolio.index')->name('index');
    Volt::route('transactions', 'portfolio.transactions')->name('transactions');
    Volt::route('analysis', 'portfolio.analysis')->name('analysis');
    Volt::route('recommendations', 'portfolio.recommendations')->name('recommendations');
    Volt::route('discover', 'portfolio.discover')->name('discover');
    Volt::route('news', 'portfolio.news')->name('news');
    Volt::route('dividends', 'portfolio.dividends')->name('dividends');
    Volt::route('taxes', 'portfolio.taxes')->name('taxes');
    Volt::route('rebalancing', 'portfolio.rebalancing')->name('rebalancing');
    Volt::route('savings-plans', 'portfolio.savings')->name('savings');
    Volt::route('alerts', 'portfolio.alerts')->name('alerts');
    Volt::route('watchlist', 'portfolio.watchlist')->name('watchlist');
    Volt::route('banking', 'portfolio.banking')->name('banking');
    Volt::route('instagram', 'portfolio.instagram')->name('instagram');
    Volt::route('import', 'portfolio.import')->name('import');
    Volt::route('settings', 'portfolio.settings')->name('settings');
    Volt::route('securities/{symbol}', 'portfolio.security')->where('symbol', '[A-Za-z0-9.\-^=]{1,32}')->name('security');
});

// Public recipe links (no login; only recipes someone shared, see PublicRecipeController).
Route::middleware('throttle:60,1')->group(function () {
    Route::get('r/{token}', [PublicRecipeController::class, 'show'])->name('recipes.public');
    Volt::route('apply/{token}', 'rental.apply')->where('token', '[A-Za-z0-9]+')->name('rental.apply');
    Volt::route('s/{token}', 'shopping-list.shared')->where('token', '[A-Za-z0-9]+')->name('shopping-list.shared');
    Route::get('c/{token}', PublicCollectionController::class)->where('token', '[A-Za-z0-9]+')->name('collections.public');
    Route::get('r/{token}/image', [PublicRecipeController::class, 'image'])->name('recipes.public.image');
    Volt::route('emergency/access/{token}', 'emergency.access')->where('token', '[A-Za-z0-9]+')->name('emergency.access');
    Route::get('emergency/access/{token}/pdf', [EmergencyFolderController::class, 'sharedPdf'])->where('token', '[A-Za-z0-9]+')->name('emergency.shared-pdf');
    Volt::route('tenant/{token}', 'rental.portal')->where('token', '[A-Za-z0-9]+')->name('rental.portal');
    Route::controller(RentalDocumentController::class)->where(['token' => '[A-Za-z0-9]+'])->group(function () {
        Route::get('tenant/{token}/lease', 'portalLease')->name('rental.portal.lease');
        Route::get('tenant/{token}/statements/{statement}', 'portalStatement')->whereNumber('statement')->name('rental.portal.statement');
        Route::get('tenant/{token}/handovers/{handover}', 'portalHandover')->whereNumber('handover')->name('rental.portal.handover');
    });
    Route::get('calendar/{token}.ics', CalendarFeedController::class)->where('token', '[A-Za-z0-9]+')->name('calendar.feed');
    Route::get('d/{token}', [PublicDocumentController::class, 'show'])->where('token', '[A-Za-z0-9]+')->name('documents.public');
    Route::get('d/{token}/pdf', [PublicDocumentController::class, 'pdf'])->where('token', '[A-Za-z0-9]+')->name('documents.public.pdf');
    Route::post('d/{token}/accept', [PublicDocumentController::class, 'accept'])->where('token', '[A-Za-z0-9]+')->name('documents.public.accept');
});

Volt::route('admin/settings', 'admin.settings')->middleware(['auth', 'can:admin'])->name('admin.settings');

// Instagram videos analysed on another machine (header X-Push-Key, no session).
Route::post('api/instagram/push', InstagramPushController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])->middleware('throttle:30,1')->name('instagram.push');

require __DIR__.'/auth.php';
