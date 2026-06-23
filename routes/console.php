<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto-cancel expired product orders — runs every minute
Schedule::command('orders:auto-cancel-expired')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Auto-cancel expired jasa orders — runs every minute
Schedule::command('jasa:auto-cancel-expired')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// SLA: Auto-expire merchant response (24h) — runs every hour
Schedule::command('orders:auto-expire-merchant-response')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// SLA: Auto-complete waiting service orders (24h) — runs every hour
Schedule::command('orders:auto-complete-service')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
