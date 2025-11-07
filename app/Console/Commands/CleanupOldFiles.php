<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Presensi;
use App\Models\PengajuanIzin;
use App\Models\PengajuanLembur;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CleanupOldFiles extends Command
{
    protected $signature = 'files:cleanup-old';
    protected $description = 'Cleanup foto presensi, file dokumen izin, dan file SKL lembur yang lebih dari 1 bulan 3 hari';

    public function handle()
    {        
        $cutoffDate = Carbon::now()->subDays(40);
        
        // Cleanup foto presensi
        $presensiCleaned = $this->cleanupPresensi($cutoffDate);
        
        // Cleanup file dokumen izin
        $izinCleaned = $this->cleanupPengajuanIzin($cutoffDate);
        
        // Cleanup file SKL lembur
        $lemburCleaned = $this->cleanupPengajuanLembur($cutoffDate);
        
        Log::info("File cleanup - Foto: {$presensiCleaned}, Dokumen Izin: {$izinCleaned}, SKL Lembur: {$lemburCleaned}");

        return 0;
    }

    private function cleanupPresensi($cutoffDate)
    {
        $cleaned = 0;
        
        $oldPresensis = Presensi::where('created_at', '<', $cutoffDate)
                                ->whereNotNull('foto')
                                ->get();

        foreach ($oldPresensis as $presensi) {
            if ($presensi->foto && \Storage::exists('public/' . $presensi->foto)) {
                \Storage::delete('public/' . $presensi->foto);
                $presensi->update(['foto' => null]);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    private function cleanupPengajuanIzin($cutoffDate)
    {
        $cleaned = 0;
        
        $oldPengajuans = PengajuanIzin::where('created_at', '<', $cutoffDate)
                                      ->whereNotNull('file_dokumen')
                                      ->get();

        foreach ($oldPengajuans as $pengajuan) {
            if ($pengajuan->file_dokumen && \Storage::exists('public/' . $pengajuan->file_dokumen)) {
                \Storage::delete('public/' . $pengajuan->file_dokumen);
                $pengajuan->update(['file_dokumen' => null]);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    private function cleanupPengajuanLembur($cutoffDate)
    {
        $cleaned = 0;
        
        $oldLemburs = PengajuanLembur::where('created_at', '<', $cutoffDate)
                                     ->whereNotNull('file_skl')
                                     ->get();

        foreach ($oldLemburs as $lembur) {
            if ($lembur->file_skl && \Storage::exists('public/' . $lembur->file_skl)) {
                \Storage::delete('public/' . $lembur->file_skl);
                $lembur->update(['file_skl' => null]);
                $cleaned++;
            }
        }

        return $cleaned;
    }
}