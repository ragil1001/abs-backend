<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     * ⚠️  TESTING MODE: Cleanup setiap 1 menit
     */
    protected function schedule(Schedule $schedule): void
    {
        // Cek presensi otomatis setiap 30 menit
        $schedule->command('presensi:cek-otomatis')
                 ->everyThirtyMinutes()
                 ->withoutOverlapping()
                 ->runInBackground();

        // ⚠️  TESTING: Cleanup file setiap 1 menit (production: daily)
        $schedule->command('files:cleanup-old')
                 ->everyMinute()
                 ->withoutOverlapping()
                 ->runInBackground();

        // ⚠️  TESTING: Cleanup notifikasi setiap 1 menit (production: daily)
        $schedule->command('notifications:cleanup-old')
                 ->everyMinute()
                 ->withoutOverlapping()
                 ->runInBackground();

        $schedule->command('presensi:reminder-notification')
                 ->everyMinute()
                 ->withoutOverlapping()
                 ->runInBackground();

        $schedule->command('cuti:reset-tahunan')
                 ->dailyAt('01:00')
                 ->withoutOverlapping()
                 ->runInBackground();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}