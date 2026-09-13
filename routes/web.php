<?php

use App\Http\Controllers\DataExportController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
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
    Volt::route('notes', 'notes.index')->name('notes.index');
    Volt::route('todos', 'todos.index')->name('todos.index');
    Volt::route('shopping-list', 'shopping-list.index')->name('shopping-list.index');
});

require __DIR__.'/auth.php';
