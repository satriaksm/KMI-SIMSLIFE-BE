<?php


use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;

// Route CSRF cookie untuk Sanctum SPA
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;


Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show']);


// Auth routes (SPA)
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');

// ✅ Tambahkan route untuk mendapatkan data user yang sedang login

