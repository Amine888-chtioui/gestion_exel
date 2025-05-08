<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Import routes
Route::post('/import', [ImportController::class, 'import']);

// Dashboard data
Route::get('/dashboard/statistics', [DashboardController::class, 'getStatistics']);
Route::get('/dashboard/filters', [DashboardController::class, 'getFiltersData']);
Route::get('/dashboard/detailed-data', [DashboardController::class, 'getDetailedData']);

// API route for exporting data
Route::get('/dashboard/export', [DashboardController::class, 'exportData']);

// API route for saving dashboard settings
Route::post('/dashboard/settings', [DashboardController::class, 'saveSettings']);
Route::get('/dashboard/settings', [DashboardController::class, 'getSettings']);

// User preferences
Route::post('/user/preferences', [DashboardController::class, 'saveUserPreferences']);
Route::get('/user/preferences', [DashboardController::class, 'getUserPreferences']);

// Machine-specific statistics
Route::get('/dashboard/machine/{machine}', [DashboardController::class, 'getMachineStatistics']);

// Historical comparison data
Route::get('/dashboard/comparison', [DashboardController::class, 'getComparisonData']);

// Top issues and recurring problems
Route::get('/dashboard/top-issues', [DashboardController::class, 'getTopIssues']);
Route::get('/dashboard/recurring-issues', [DashboardController::class, 'getRecurringIssues']);

// Efficiency metrics
Route::get('/dashboard/efficiency', [DashboardController::class, 'getEfficiencyMetrics']);

// Machine status (current state, if applicable)
Route::get('/dashboard/machine-status', [DashboardController::class, 'getMachineStatus']);

// Notification system endpoints
Route::get('/notifications', [DashboardController::class, 'getNotifications']);
Route::post('/notifications/read', [DashboardController::class, 'markNotificationsAsRead']);
Route::post('/notifications/settings', [DashboardController::class, 'updateNotificationSettings']);