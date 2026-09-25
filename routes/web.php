<?php

use App\Http\Controllers\DataExportController;
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
    Volt::route('pages/{item?}', 'pages.index')->whereNumber('item')->name('pages.index');
    // Notes became pages: keep old bookmarks (/notes?note=ID) working.
    Route::get('notes', function (Request $request) {
        $noteId = $request->query('note');

        return is_string($noteId) && ctype_digit($noteId)
            ? redirect()->route('pages.index', ['item' => (int) $noteId])
            : redirect()->route('pages.index', $request->only(['new', 'tag', 'q']));
    })->name('notes.index');
    Volt::route('todos', 'todos.index')->name('todos.index');
    Volt::route('shopping-list', 'shopping-list.index')->name('shopping-list.index');
});

require __DIR__.'/auth.php';
