<?php

use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\TopicTagRequestController;
use App\Http\Controllers\AppearanceController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SetPasswordController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\TreeUnitController;
use App\Http\Controllers\UnitMembershipController;
use Illuminate\Support\Facades\Route;

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
        // Home page = Leaderboard (UC-1.2, UC-2.2). No role check here on purpose:
        // refused visits are redirected to "/", so it must open for every user.
        Route::get('/', [LeaderboardController::class, 'index'])->name('home');

        // Main menu of teachers and students (AppLayout sidebar)
        Route::middleware('role:teacher|student')->group(function () {
            Route::get('/events', [EventController::class, 'index'])
                ->middleware('permission:events.view')
                ->name('events.index');

            // Teacher: events I organize (UC-1.3). Student: events I applied to (UC-2.3).
            Route::get('/my-events', [EventController::class, 'mine'])->name('events.mine');

            // Students redeem attendance QR codes (UC-2.3)
            Route::get('/scan', [ScanController::class, 'show'])
                ->middleware('permission:qr_codes.redeem')
                ->name('scan');

            // Profile Settings: profile data, change password, appearance
            Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
            Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar.update');
            Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
            Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
        });

        // "My courses": deans, teachers and co-teachers manage their branch of
        // the faculty tree. TreeService and the tree policies check every action.
        Route::middleware('role:teacher')->prefix('my-courses')->name('tree.')->group(function () {
            Route::get('/', [TreeUnitController::class, 'index'])->name('index');
            Route::get('/{unit}', [TreeUnitController::class, 'show'])->whereNumber('unit')->name('show');
            Route::post('/{unit}/subtopics', [TreeUnitController::class, 'storeSubtopic'])->whereNumber('unit')->name('subtopics.store');
            Route::get('/{unit}/candidates', [UnitMembershipController::class, 'candidates'])->whereNumber('unit')->name('candidates');
            Route::post('/{unit}/members', [UnitMembershipController::class, 'store'])->whereNumber('unit')->name('members.store');
            Route::put('/members/{membership}/permissions', [UnitMembershipController::class, 'updatePermissions'])->name('members.permissions');
            Route::post('/members/{membership}/end', [UnitMembershipController::class, 'end'])->name('members.end');
        });
    });
});

// Admin endpoints specified in Technical Specification Table 22
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Neptun CSV imports (Tasks #24 and #27)
    Route::post('/import/organizers', [ImportController::class, 'organizers'])
        ->name('import.organizers');
    Route::post('/import/students', [ImportController::class, 'students'])
        ->name('import.students');
    Route::get('/sample-csv/organizers', [ImportController::class, 'sampleOrganizersCsv'])
        ->name('sample-csv.organizers');
    Route::get('/sample-csv/students', [ImportController::class, 'sampleStudentsCsv'])
        ->name('sample-csv.students');
    Route::get('/sample-csv/enrolments', [ImportController::class, 'sampleEnrolmentsCsv'])
        ->name('sample-csv.enrolments');

    // Topic tag requests from teachers (Task #31, UC-3.4)
    Route::post('/topic-tag-requests/{id}/approve', [TopicTagRequestController::class, 'approve'])
        ->name('topic-tag-requests.approve');
    Route::post('/topic-tag-requests/{id}/reject', [TopicTagRequestController::class, 'reject'])
        ->name('topic-tag-requests.reject');
});
