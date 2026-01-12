<?php


use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;

// Route CSRF cookie untuk Sanctum SPA
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;


Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show']);


// Auth routes (SPA)
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum')->name('me');

// Profile routes (SPA)
Route::middleware(['auth:sanctum'])->prefix('profile')->controller(\App\Http\Controllers\ProfileController::class)->group(function () {
    Route::get('/', 'show')->name('profile.show');
    Route::post('/update', 'update')->name('profile.update');
    Route::post('/change-password', 'changePassword')->name('profile.change-password');
});
