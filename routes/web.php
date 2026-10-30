<?php

use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\TopicTagRequestController as AdminTopicTagRequestController;
use App\Http\Controllers\AppearanceController;
use App\Http\Controllers\ApplicantController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SetPasswordController;
use App\Http\Controllers\EventApplicationController;
use App\Http\Controllers\EventCheckinController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventCreditController;
use App\Http\Controllers\EventEditController;
use App\Http\Controllers\EventPointsImportController;
use App\Http\Controllers\EventQrCodeController;
use App\Http\Controllers\EventQrController;
use App\Http\Controllers\EventStatusController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PassportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleAssignmentController;
use App\Http\Controllers\RoleDefinitionController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\TopicImportController;
use App\Http\Controllers\TopicTagRequestController;
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

            // One event's detail page and direct URL (Task #40); visibility follows EventPolicy::view
            Route::get('/events/{event}', [EventController::class, 'show'])
                ->whereNumber('event')
                ->middleware('permission:events.view')
                ->name('events.show');

            // Student apply to an event (Task #39, UC-2.3)
            Route::post('/events/{event}/apply', [EventApplicationController::class, 'store'])
                ->middleware('permission:events.apply')
                ->name('events.apply');

            // Student withdraw from an event (Task #50, UC-2.3)
            Route::post('/events/{event}/withdraw', [EventApplicationController::class, 'withdraw'])
                ->middleware('permission:events.apply')
                ->name('events.withdraw');

            // Organizer: applicants list and selection (Task #41, UC-1.6)
            Route::get('/events/{event}/applicants', [ApplicantController::class, 'index'])
                ->middleware('permission:applications.manage')
                ->name('events.applicants');
            Route::post('/events/{event}/applicants/select', [ApplicantController::class, 'select'])
                ->middleware('permission:applications.manage')
                ->name('events.applicants.select');
            Route::post('/events/{event}/applicants/{application}/reject', [ApplicantController::class, 'reject'])
                ->middleware('permission:applications.manage')
                ->name('events.applicants.reject');

            // Organizer: QR Passport scanner and attendance crediting (Task #43, UC-1.7)
            Route::get('/events/{event}/scan', [EventCreditController::class, 'showScan'])
                ->name('events.scan');
            Route::post('/events/{event}/credit', [EventCreditController::class, 'credit'])
                ->name('events.credit');
            // Checklist mode: credit every ticked student in one request (Task #42)
            Route::post('/events/{event}/credit/bulk', [EventCreditController::class, 'creditMany'])
                ->name('events.credit.bulk');

            // Organizer: give an event's points to an imported list of students (Task #85).
            // EventPolicy::credit is checked in the controller, like the routes above.
            Route::get('/events/{event}/points-import', [EventPointsImportController::class, 'show'])
                ->whereNumber('event')->name('events.points-import');
            Route::post('/events/{event}/points-import/preview', [EventPointsImportController::class, 'preview'])
                ->whereNumber('event')->name('events.points-import.preview');
            Route::post('/events/{event}/points-import', [EventPointsImportController::class, 'store'])
                ->whereNumber('event')->name('events.points-import.store');

            // Event Form: create an event as a draft or publish it (Task #38, UC-1.3)
            // Creating events follows the events.create capability at a topic (Task #65),
            // checked in the controller and the form request, not an account-wide permission.
            Route::get('/events/create', [EventController::class, 'create'])
                ->name('events.create');
            Route::post('/events', [EventController::class, 'store'])
                ->name('events.store');

            // Making, drawing and revoking event QR codes (Tasks #45, #46). EventPolicy::manageQr checks the
            // qr_codes.manage capability at the owning topic.
            Route::post('/events/{event}/qr-tokens', [EventQrController::class, 'store'])
                ->whereNumber('event')->name('events.qr.store');
            Route::get('/events/{event}/qr-tokens/{token}/image.{format}', [EventQrController::class, 'image'])
                ->whereNumber(['event', 'token'])->whereIn('format', ['svg', 'png'])->name('events.qr.image');
            Route::post('/events/{event}/qr-tokens/{token}/revoke', [EventQrController::class, 'revoke'])
                ->whereNumber(['event', 'token'])->name('events.qr.revoke');

            // Live attendance check-in (Task #80): the code that changes every 10 seconds. EventPolicy::manageQr
            // is checked in the service; students only ask whether check-in is open.
            Route::prefix('events/{event}/checkin')->whereNumber('event')->name('events.checkin.')->group(function () {
                Route::get('/', [EventCheckinController::class, 'state'])->name('state');
                Route::post('/open', [EventCheckinController::class, 'open'])->name('open');
                Route::post('/pause', [EventCheckinController::class, 'pause'])->name('pause');
                Route::post('/stop', [EventCheckinController::class, 'stop'])->name('stop');
                Route::get('/image.svg', [EventCheckinController::class, 'image'])->name('image');
            });
            Route::get('/events/{event}/checkin/status', [EventCheckinController::class, 'status'])
                ->whereNumber('event')->middleware('permission:events.view')->name('events.checkin.status');

            // The organiser's QR page: make, print (posters and slips) and revoke codes (Task #44).
            Route::prefix('events/{event}/qr-codes')->whereNumber('event')->name('events.qr-codes.')->group(function () {
                Route::get('/', [EventQrCodeController::class, 'index'])->name('index');
                Route::post('/', [EventQrCodeController::class, 'store'])->name('store');
                Route::post('/{token}/revoke', [EventQrCodeController::class, 'revoke'])->whereNumber('token')->name('revoke');
                Route::get('/print/{type}', [EventQrCodeController::class, 'print'])->whereIn('type', ['poster', 'slip'])->name('print');
            });

            // Edit an event after it was created (Task #68, UC-1.3). EventPolicy::update checks
            // the capability at the owning topic; EventEditor decides which fields are open.
            Route::get('/events/{event}/edit', [EventEditController::class, 'edit'])
                ->whereNumber('event')->name('events.edit');
            Route::put('/events/{event}', [EventEditController::class, 'update'])
                ->whereNumber('event')->name('events.update');

            // Lifecycle actions on "Organized by me" (Task #37, UC-1.3). EventPolicy::manage
            // checks the events.manage capability at the owning topic (Task #65).
            Route::prefix('events/{event}')->whereNumber('event')->group(function () {
                Route::post('/publish', [EventStatusController::class, 'publish'])->name('events.publish');
                Route::post('/hold', [EventStatusController::class, 'hold'])->name('events.hold');
                Route::post('/restore', [EventStatusController::class, 'restore'])->name('events.restore');
                Route::post('/cancel', [EventStatusController::class, 'cancel'])->name('events.cancel');
                Route::delete('/', [EventStatusController::class, 'destroy'])->name('events.destroy');
            });

            // In-app notifications, e.g. "You were selected for ..." (Task #53)
            Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
            Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
                ->whereUuid('notification')->name('notifications.read');

            // Teacher: events I organize (UC-1.3). Student: events I applied to (UC-2.3).
            Route::get('/my-events', [EventController::class, 'mine'])->name('events.mine');

            // Teacher topic tag requests (UC-1.9, Task #47)
            Route::get('/topic-tag-requests', [TopicTagRequestController::class, 'index'])
                ->middleware('permission:topic_tags.request')
                ->name('topic-tag-requests.index');
            Route::post('/topic-tag-requests', [TopicTagRequestController::class, 'store'])
                ->middleware('permission:topic_tags.request')
                ->name('topic-tag-requests.store');

            // Students redeem attendance QR codes (UC-2.3). Opening a code only shows the
            // page; points are given by the POST, which is limited per student.
            Route::middleware('permission:qr_codes.redeem')->group(function () {
                // The student's QR passport, shown to an organiser's "Scan attendance" (Task #52)
                Route::get('/passport', [PassportController::class, 'show'])->name('passport');
                Route::get('/scan', [ScanController::class, 'show'])->name('scan');
                Route::get('/qr/redeem/{code}', [ScanController::class, 'show'])
                    ->where('code', '[A-Za-z0-9.]+')->name('qr.show');
                Route::post('/qr/redeem', [ScanController::class, 'redeem'])
                    ->middleware('throttle:qr-redeem')->name('qr.redeem');
            });

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
            // Task #72: give a role to a list of students from a file of Neptun codes.
            Route::post('/{topic}/imports', [TopicImportController::class, 'store'])->whereNumber('topic')->name('imports.store');
        });

        // The previous "My courses" pages now live in Topics.
        Route::redirect('/my-courses', '/topics');
        Route::get('/my-courses/{any}', fn () => redirect('/topics'))->where('any', '.*');
    });
});

// Admin endpoints specified in Technical Specification Table 22
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Neptun CSV imports (Tasks #24, #27, and #29)
    Route::post('/import/organizers', [ImportController::class, 'organizers'])
        ->name('import.organizers');
    Route::post('/import/students', [ImportController::class, 'students'])
        ->name('import.students');
    Route::post('/import/enrolments', [ImportController::class, 'enrolments'])
        ->name('import.enrolments');
    Route::get('/topics/{topic}/branch-nodes', [ImportController::class, 'branchNodes'])
        ->name('topics.branch-nodes');
    Route::get('/sample-csv/organizers', [ImportController::class, 'sampleOrganizersCsv'])
        ->name('sample-csv.organizers');
    Route::get('/sample-csv/students', [ImportController::class, 'sampleStudentsCsv'])
        ->name('sample-csv.students');
    Route::get('/sample-csv/enrolments', [ImportController::class, 'sampleEnrolmentsCsv'])
        ->name('sample-csv.enrolments');

    // Topic tag requests from teachers (Task #31, UC-3.4)
    Route::post('/topic-tag-requests/{id}/approve', [AdminTopicTagRequestController::class, 'approve'])
        ->name('topic-tag-requests.approve');
    Route::post('/topic-tag-requests/{id}/reject', [AdminTopicTagRequestController::class, 'reject'])
        ->name('topic-tag-requests.reject');
});
