<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\DataExportController;
use App\Http\Controllers\ItemLinkController;
use App\Http\Controllers\PublicRecipeController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\RecipeImageController;
use App\Http\Controllers\RecipePdfController;
use App\Models\Collection;
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
    Volt::route('shopping-list', 'shopping-list.index')->name('shopping-list.index');
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

// Public recipe links (no login; only recipes someone shared, see PublicRecipeController).
Route::middleware('throttle:60,1')->group(function () {
    Route::get('r/{token}', [PublicRecipeController::class, 'show'])->name('recipes.public');
    Route::get('r/{token}/image', [PublicRecipeController::class, 'image'])->name('recipes.public.image');
});

require __DIR__.'/auth.php';
