<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote')->hourly();

// ===== SCHEDULED TASKS =====

// Cek presensi otomatis setiap 30 menit
Schedule::command('presensi:cek-otomatis')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Cleanup foto presensi lama setiap hari jam 00:00
Schedule::command('files:cleanup-old')
    ->dailyAt('00:00')
    ->withoutOverlapping()
    ->runInBackground();

// Cleanup notifikasi lama setiap hari jam 00:30
Schedule::command('notifications:cleanup-old')
    ->dailyAt('00:30')
    ->withoutOverlapping()
    ->runInBackground();

// Kirim reminder notification setiap 15 menit
Schedule::command('presensi:reminder-notification')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Reset cuti tahunan setiap hari jam 01:00
Schedule::command('cuti:reset-tahunan')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->runInBackground();