<?php


use Illuminate\Support\Facades\Route;
use App\Http\Controllers\JasaController1; // ⬅️ ganti ini

Route::get('/', function () {
    return redirect()->route('jasa.index');
});

// Route resource pakai JasaController1
Route::resource('jasa', JasaController1::class);
