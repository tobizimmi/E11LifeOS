<?php

use App\Http\Controllers\Auth\PasskeyLoginController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\IdleLogoutController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function () {
    Volt::route('register', 'pages.auth.register')
        ->name('register');

    Volt::route('login', 'pages.auth.login')
        ->name('login');

    Volt::route('two-factor-challenge', 'pages.auth.two-factor-challenge')
        ->name('two-factor.challenge');

    Volt::route('forgot-password', 'pages.auth.forgot-password')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'pages.auth.reset-password')
        ->name('password.reset');

    Route::get('passkeys/options', [PasskeyLoginController::class, 'options'])
        ->middleware('throttle:30,1')
        ->name('passkeys.options');

    Route::post('passkeys/login', [PasskeyLoginController::class, 'login'])
        ->middleware('throttle:20,1')
        ->name('passkeys.login');
});

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'pages.auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'pages.auth.confirm-password')
        ->name('password.confirm');

    Route::post('session/idle-logout', IdleLogoutController::class)->name('logout.idle');
});
