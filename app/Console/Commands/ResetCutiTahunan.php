<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Karyawan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ResetCutiTahunan extends Command
{
    protected $signature = 'cuti:reset-tahunan';
    protected $description = 'Reset cuti tahunan karyawan setiap ulang tahun bergabung';

    public function handle()
    {
        $this->info('🔄 Memulai pengecekan reset cuti tahunan...');
        
        $today = Carbon::today();
        $resetCount = 0;
        
        // Get semua karyawan aktif
        $karyawans = Karyawan::where('status', 'aktif')
                            ->whereNotNull('tanggal_bergabung')
                            ->get();
        
        foreach ($karyawans as $karyawan) {
            try {
                $tanggalBergabung = Carbon::parse($karyawan->tanggal_bergabung);
                
                // Anniversary tahun ini
                $anniversaryThisYear = $tanggalBergabung->copy()->year($today->year);
                
                // Jika hari ini adalah anniversary
                if ($today->isSameDay($anniversaryThisYear)) {
                    $karyawan->resetCutiTahunan();
                    $resetCount++;
                    
                    $this->info("✅ Cuti tahunan direset untuk: {$karyawan->nama} (NIK: {$karyawan->nik})");
                    
                    // Log::info("Cuti tahunan direset otomatis", [
                    //     'karyawan_id' => $karyawan->id,
                    //     'nama' => $karyawan->nama,
                    //     'nik' => $karyawan->nik,
                    //     'tanggal_bergabung' => $tanggalBergabung->format('Y-m-d'),
                    //     'anniversary_ke' => $today->year - $tanggalBergabung->year
                    // ]);
                }
                
            } catch (\Exception $e) {
                $this->error("❌ Error processing karyawan {$karyawan->nama}: " . $e->getMessage());
                // Log::error("Error reset cuti tahunan", [
                //     'karyawan_id' => $karyawan->id,
                //     'error' => $e->getMessage()
                // ]);
            }
        }
        
        $this->newLine();
        $this->info("========================================");
        $this->info("✅ Selesai!");
        $this->info("========================================");
        $this->info("📊 Total karyawan dicek: {$karyawans->count()}");
        $this->info("🔄 Total cuti direset: {$resetCount}");
        $this->info("========================================");
        
        // Log::info("Reset cuti tahunan selesai", [
        //     'total_karyawan' => $karyawans->count(),
        //     'reset_count' => $resetCount
        // ]);
        
        return 0;
    }
}