<?php

namespace App\Observers;

use App\Models\Presensi;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class PresensiObserver
{
    /**
     * Handle the Presensi "deleting" event.
     * Hapus foto dari storage saat record dihapus
     */
    public function deleting(Presensi $presensi)
    {
        if ($presensi->foto) {
            try {
                // Cek apakah file ada
                if (Storage::exists('public/' . $presensi->foto)) {
                    Storage::delete('public/' . $presensi->foto);
                    Log::info("Foto presensi dihapus: {$presensi->foto}");
                }
            } catch (\Exception $e) {
                throw $e;
            }
        }
    }

    /**
     * Handle the Presensi "force deleted" event.
     * Untuk soft delete yang di-force delete
     */
    public function forceDeleted(Presensi $presensi)
    {
        if ($presensi->foto) {
            try {
                if (Storage::exists('public/' . $presensi->foto)) {
                    Storage::delete('public/' . $presensi->foto);
                    Log::info("Foto presensi force deleted: {$presensi->foto}");
                }
            } catch (\Exception $e) {
                throw $e;
            }
        }
    }
}