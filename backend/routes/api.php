<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\InternController;
use App\Http\Controllers\AttendanceController;

// ==========================================
// 1. ROUTE PUBLIC / TEST
// ==========================================
Route::get('/ping', function () {
    return response()->json(['message' => 'Server Laravel Siap!']);
});
Route::post('/login', [AuthController::class, 'login']);

// ==========================================
// 2. ROUTE MANAJEMEN USER & INTERN
// ==========================================
Route::get('/interns', [InternController::class, 'index']);
Route::post('/interns', [InternController::class, 'store']);
Route::post('/face-register', [InternController::class, 'registerFace']);

Route::post('/users', [InternController::class, 'store']);
Route::get('/users/{role}', [InternController::class, 'getAllUsers']);
Route::put('/users/{id}', [InternController::class, 'updateUser']);
Route::delete('/users/{id}', [InternController::class, 'deleteUser']);

// ==========================================
// 3. ROUTE AUTH & OTP
// ==========================================
// Perbaikan: Hapus '/api' di depan karena file ini sudah otomatis pakai prefix /api
Route::post('/hr/generate-otp/{id}', [InternController::class, 'generateOTP']);
Route::post('/send-otp-email', [InternController::class, 'sendOtpEmail']);
Route::post('/reset-password', [InternController::class, 'resetPassword']);

// ==========================================
// 4. ROUTE HR DASHBOARD & SETTINGS
// ==========================================
Route::get('/hr/daily-rekap', [AttendanceController::class, 'getDailyRekap']);
Route::get('/hr/dashboard-summary', [AttendanceController::class, 'getHRDashboardSummary']); // Cukup 1 saja
Route::get('/hr/realtime-status', [InternController::class, 'getRealtimeStatus']);

// PERBAIKAN: Rute GET untuk settings yang tadi hilang!
Route::get('/hr/settings', [InternController::class, 'getSettings']);
Route::post('/hr/settings', [InternController::class, 'updateSettings']);
Route::post('/hr/holidays', [InternController::class, 'storeHoliday']);
Route::delete('/hr/holidays/{id}', [InternController::class, 'destroyHoliday']);

// ==========================================
// 5. ROUTE ABSENSI (ATTENDANCE)
// ==========================================
Route::get('/attendances/today/{user_id}', [AttendanceController::class, 'checkToday']);
Route::post('/attendances/clock-in', [AttendanceController::class, 'clockIn']);
Route::post('/attendances/clock-out', [AttendanceController::class, 'clockOut']);
Route::post('/attendances/permit', [AttendanceController::class, 'permit']);
Route::post('/attendances/toggle-status', [AttendanceController::class, 'toggleStatus']);
Route::post('/attendances/logbook', [AttendanceController::class, 'saveLogbook']);
Route::get('/attendances/history/{user_id}', [AttendanceController::class, 'history']);
Route::get('/attendances/download/{user_id}', [AttendanceController::class, 'downloadReport']);
Route::get('/hr/all-history', [AttendanceController::class, 'getAllHistory']);
