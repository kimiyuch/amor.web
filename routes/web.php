<?php

use App\Http\Controllers\FeedController;
use App\Http\Controllers\GoogleController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1');

// Calendar apps can't sign in, so the feed is protected by the secret in its URL instead.
Route::get('/feed/{token}.ics', FeedController::class)->name('feed');

// Google's consent screen sends the browser back here; checked by the state it was given.
Route::get('/google/callback', [GoogleController::class, 'callback'])->name('google.callback');

Route::middleware('password')->group(function () {
    Route::get('/', HomeController::class)->name('home');

    Route::post('/scans', [ScanController::class, 'store'])->name('scans.store');
    Route::get('/scans/{scan}', [ScanController::class, 'show'])->name('scans.show');
    Route::get('/scans/{scan}/status', [ScanController::class, 'status'])->name('scans.status');
    Route::get('/scans/{scan}/row.jpg', [ScanController::class, 'strip'])->name('scans.strip');
    Route::post('/scans/{scan}/save', [ScanController::class, 'save'])->name('scans.save');
    Route::get('/scans/{scan}/shifts.ics', [ScanController::class, 'ics'])->name('scans.ics');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/feed', [SettingsController::class, 'resetFeed'])->name('settings.feed');

    Route::get('/google/connect', [GoogleController::class, 'connect'])->name('google.connect');
    Route::post('/google/disconnect', [GoogleController::class, 'disconnect'])->name('google.disconnect');
    Route::post('/google/sync/{scan}', [GoogleController::class, 'sync'])->name('google.sync');

    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
