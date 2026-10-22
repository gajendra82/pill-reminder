<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DemoActivityController;
use App\Http\Controllers\Api\DeviceStatusController;
use App\Http\Controllers\Api\DoseEventController;
use App\Http\Controllers\Api\MedicineController;
use App\Http\Controllers\Api\MedicineScheduleController;
use App\Http\Controllers\Api\ScheduleSlotController;
use App\Http\Controllers\Api\ScheduleSyncDryRunController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:5,1');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    // Authentication
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Medicine management
    Route::get('/medicines', [MedicineController::class, 'index']);
    Route::post('/medicines', [MedicineController::class, 'store']);
    Route::get('/medicines/{medicine}', [MedicineController::class, 'show']);
    Route::delete('/medicines/{medicine}', [MedicineController::class, 'destroy']);
    Route::patch('/medicine-schedules/{schedule}', [MedicineScheduleController::class, 'update'])
        ->whereNumber('schedule');

    Route::get('/dose-events', [DoseEventController::class, 'index']);
    Route::post('/dose-events', [DoseEventController::class, 'store']);
    Route::get('/dose-events/{doseEvent}', [DoseEventController::class, 'show']);

    Route::post('/demo-activity/taken', [DemoActivityController::class, 'storeTaken']);
    Route::get('/demo-activity/{demoActivityEvent}', [DemoActivityController::class, 'show'])
        ->whereNumber('demoActivityEvent');

    Route::get('/analytics', AnalyticsController::class);

    // Temporary single-device Blynk read. Not per-user device ownership.
    Route::get('/device/status', DeviceStatusController::class);

    // Dry-run schedule proposal. Does not write Blynk datastreams or slot rows.
    Route::get('/device/schedule-sync/dry-run', ScheduleSyncDryRunController::class);

    // Explicit physical slot assignment. Does not write Blynk datastreams.
    Route::get('/device/schedule-slots', [ScheduleSlotController::class, 'index']);
    Route::post('/device/schedule-slots', [ScheduleSlotController::class, 'store']);
    Route::post('/device/schedule-slots/{slot}/release', [ScheduleSlotController::class, 'release'])
        ->whereNumber('slot');
    Route::post('/device/schedule-slots/{slot}/replace', [ScheduleSlotController::class, 'replace'])
        ->whereNumber('slot');
    Route::post('/device/schedule-slots/{slot}/sync', [ScheduleSlotController::class, 'sync'])
        ->whereNumber('slot');
});
