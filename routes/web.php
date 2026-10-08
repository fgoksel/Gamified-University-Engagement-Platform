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
use App\Http\Controllers\RoleAssignmentController;
use App\Http\Controllers\RoleDefinitionController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\TopicController;
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
});

// The invitation emails (Tasks #23 and #26) and the reset email link here.
// Not "guest" only: the link must work even when someone else is logged in
// in the same browser (e.g. the admin who imported the students). The
// controller logs that person out first.
Route::get('/auth/activate/{token}', [SetPasswordController::class, 'create'])->name('password.setup');
Route::post('/auth/set-password', [SetPasswordController::class, 'store'])->name('password.setup.store');

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

        // Topics: the explorer, reusable roles and role assignments. Open to every
        // signed-in account: what a person sees and may do is decided per topic by
        // TopicAccess and the policies, never by the account type.
        Route::prefix('topics')->name('topics.')->group(function () {
            Route::get('/', [TopicController::class, 'index'])->name('index');
            Route::post('/', [TopicController::class, 'storeRoot'])->name('store');
            Route::get('/search', [TopicController::class, 'search'])->name('search');

            Route::get('/roles', [RoleDefinitionController::class, 'index'])->name('roles.index');
            Route::post('/roles', [RoleDefinitionController::class, 'store'])->name('roles.store');
            Route::put('/roles/{role}', [RoleDefinitionController::class, 'update'])->whereNumber('role')->name('roles.update');
            Route::post('/roles/{role}/archive', [RoleDefinitionController::class, 'archive'])->whereNumber('role')->name('roles.archive');
            Route::get('/roles/{role}/usage', [RoleDefinitionController::class, 'usage'])->whereNumber('role')->name('roles.usage');

            Route::post('/assignments/{assignment}/prepare', [RoleAssignmentController::class, 'prepare'])->whereNumber('assignment')->name('assignments.prepare');
            Route::post('/assignments/execute', [RoleAssignmentController::class, 'execute'])->name('assignments.execute');

            Route::get('/archived', [TopicController::class, 'archived'])->name('archived');
            Route::post('/delete/execute', [TopicController::class, 'deleteExecute'])->name('delete.execute');

            Route::get('/{topic}', [TopicController::class, 'show'])->whereNumber('topic')->name('show');
            Route::put('/{topic}', [TopicController::class, 'update'])->whereNumber('topic')->name('update');
            Route::get('/{topic}/children', [TopicController::class, 'children'])->whereNumber('topic')->name('children');
            Route::post('/{topic}/children', [TopicController::class, 'storeChild'])->whereNumber('topic')->name('children.store');
            Route::get('/{topic}/destinations', [TopicController::class, 'destinations'])->whereNumber('topic')->name('destinations');
            Route::post('/{topic}/archive', [TopicController::class, 'archive'])->whereNumber('topic')->name('archive');
            Route::post('/{topic}/restore', [TopicController::class, 'restore'])->whereNumber('topic')->name('restore');
            Route::post('/{topic}/delete/prepare', [TopicController::class, 'deletePrepare'])->whereNumber('topic')->name('delete.prepare');
            Route::post('/{topic}/move/preview', [TopicController::class, 'movePreview'])->whereNumber('topic')->name('move.preview');
            Route::post('/{topic}/move', [TopicController::class, 'move'])->whereNumber('topic')->name('move');
            Route::get('/{topic}/candidates', [RoleAssignmentController::class, 'candidates'])->whereNumber('topic')->name('candidates');
            Route::post('/{topic}/assignments', [RoleAssignmentController::class, 'store'])->whereNumber('topic')->name('assignments.store');
        });

        // The previous "My courses" pages now live in Topics.
        Route::redirect('/my-courses', '/topics');
        Route::get('/my-courses/{any}', fn () => redirect('/topics'))->where('any', '.*');
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
