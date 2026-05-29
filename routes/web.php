<?php


use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;

// Route CSRF cookie untuk Sanctum SPA
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;


Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show']);

Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');

// Auth routes (SPA)
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');

// ✅ Tambahkan route untuk mendapatkan data user yang sedang login

