<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Gunakan grup 'api' (agar statefulApi/cookie terbaca) 
        // dan 'auth:sanctum' untuk otentikasi SPA
        Broadcast::routes([
            'middleware' => ['api', 'auth:sanctum'],
        ]);

        require base_path('routes/channels.php');
    }
}