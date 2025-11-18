<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    
    protected function schedule(Schedule $schedule): void
    {
        
        $schedule->command('presensi:cek-otomatis')
                 ->everyThirtyMinutes()
                 ->withoutOverlapping()
                 ->runInBackground();

        
        $schedule->command('files:cleanup-old')
                 ->dailyAt('00:00')
                 ->withoutOverlapping()
                 ->runInBackground();

        
        $schedule->command('notifications:cleanup-old')
                 ->dailyAt('00:30')
                 ->withoutOverlapping()
                 ->runInBackground();

        $schedule->command('presensi:reminder-notification')
                 ->everyTenMinutes()
                 ->withoutOverlapping()
                 ->runInBackground();

        $schedule->command('cuti:reset-tahunan')
                 ->dailyAt('01:00')
                 ->withoutOverlapping()
                 ->runInBackground();
    }

    
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}