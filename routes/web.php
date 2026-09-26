<?php

use App\Http\Controllers\Admin\OrganizerController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/organizers', [OrganizerController::class, 'index'])->name('organizers.index');
    Route::post('/organizers', [OrganizerController::class, 'store'])->name('organizers.store');
});
