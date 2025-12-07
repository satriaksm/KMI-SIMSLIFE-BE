<?php


use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;

// Route CSRF cookie untuk Sanctum SPA
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

// Pastikan rute ini berada di luar grup middleware 'auth' atau 'api' yang ketat.
Route::get('/storage/{path}', function ($path) {
    \Illuminate\Support\Facades\Log::info("Akses /storage/ diterima di Laravel: " . $path);
    // Memastikan path hanya berisi karakter yang aman untuk menghindari Directory Traversal
    if (!preg_match('/^[a-zA-Z0-9\/\-\_\.]*$/', $path)) {
        abort(404);
    }

    // Mendapatkan path file di disk (misalnya: storage/app/public/community/posts/...)
    $filePath = storage_path('app/public/' . $path);

    if (file_exists($filePath)) {
        return response()->file($filePath);
    }

    abort(404);
})->where('path', '.*'); // Memastikan path dapat menerima /

Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show']);


// Auth routes (SPA)
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// ✅ Tambahkan route untuk mendapatkan data user yang sedang login
Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
