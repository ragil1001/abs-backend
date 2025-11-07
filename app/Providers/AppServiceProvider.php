<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Presensi;
use App\Models\PengajuanIzin;
use App\Models\JadwalKaryawan;
use App\Observers\PresensiObserver;
use App\Models\PengajuanLembur;
use App\Observers\PengajuanLemburObserver;
use App\Observers\PengajuanIzinObserver;
use App\Observers\JadwalKaryawanObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Services\FirebaseService::class, function ($app) {
            return new \App\Services\FirebaseService();
        });

        $this->app->singleton(\App\Services\NotificationService::class, function ($app) {
            return new \App\Services\NotificationService(
                $app->make(\App\Services\FirebaseService::class)
            );
        });
    }

    public function boot(): void
    {
        Presensi::observe(PresensiObserver::class);
        PengajuanIzin::observe(PengajuanIzinObserver::class);
        JadwalKaryawan::observe(JadwalKaryawanObserver::class);
        PengajuanLembur::observe(PengajuanLemburObserver::class);
    }
}