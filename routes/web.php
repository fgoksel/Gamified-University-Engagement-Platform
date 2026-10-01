<?php

use App\Enums\UserRole;
use App\Http\Controllers\Admin\TopicTagRequestController;
use App\Http\Controllers\AppearanceController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SetPasswordController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
| Login, activation and passwords (Tasks #14 and #15: UC-1.1, UC-2.1)
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');

    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])
        ->middleware('throttle:login')
        ->name('password.email');

    // The invitation emails (Tasks #23 and #26) and the reset email link here.
    Route::get('/auth/activate/{token}', [SetPasswordController::class, 'create'])->name('password.setup');
    Route::post('/auth/set-password', [SetPasswordController::class, 'store'])->name('password.setup.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/password/change', [ChangePasswordController::class, 'edit'])->name('password.change');
    Route::put('/password/change', [ChangePasswordController::class, 'update'])->name('password.change.update');

    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Light / dark theme from the user menu
    Route::put('/appearance', [AppearanceController::class, 'update'])->name('appearance.update');

    // Everything below needs a permanent password first.
    Route::middleware('password.changed')->group(function () {
        // Home page. The Leaderboard will replace Welcome in a later task.
        Route::get('/', function (Request $request) {
            if ($request->user()->hasRole(UserRole::Admin)) {
                return redirect('/admin');
            }

            return Inertia::render('Welcome');
        })->name('home');
    });
});

// Admin API endpoints specified in Technical Specification Table 22
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::post('/topic-tag-requests/{id}/approve', [TopicTagRequestController::class, 'approve'])
        ->name('topic-tag-requests.approve');
    Route::post('/topic-tag-requests/{id}/reject', [TopicTagRequestController::class, 'reject'])
        ->name('topic-tag-requests.reject');
});
