<?php

namespace App\Observers;

use App\Models\JadwalKaryawan;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class JadwalKaryawanObserver
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle when multiple jadwals are created (batch insert)
     * Dipicu ketika import selesai
     */
    public function created(JadwalKaryawan $jadwal)
    {
        // Note: Untuk batch insert via DB::table(), observer ini tidak dipicu
        // Gunakan custom method di JadwalKaryawanImport sebagai gantinya
    }
}