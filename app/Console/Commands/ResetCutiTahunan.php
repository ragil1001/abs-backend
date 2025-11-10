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
        
        
        $karyawans = Karyawan::where('status', 'aktif')
                            ->whereNotNull('tanggal_bergabung')
                            ->get();
        
        foreach ($karyawans as $karyawan) {
            try {
                $tanggalBergabung = Carbon::parse($karyawan->tanggal_bergabung);
                
                
                $anniversaryThisYear = $tanggalBergabung->copy()->year($today->year);
                
                
                if ($today->isSameDay($anniversaryThisYear)) {
                    $karyawan->resetCutiTahunan();
                    $resetCount++;
                    
                    $this->info("✅ Cuti tahunan direset untuk: {$karyawan->nama} (NIK: {$karyawan->nik})");
                    
                    
                    
                    
                    
                    
                    
                    
                }
                
            } catch (\Exception $e) {
                $this->error("❌ Error processing karyawan {$karyawan->nama}: " . $e->getMessage());
                
                
                
                
            }
        }
        
        $this->newLine();
        $this->info("========================================");
        $this->info("✅ Selesai!");
        $this->info("========================================");
        $this->info("📊 Total karyawan dicek: {$karyawans->count()}");
        $this->info("🔄 Total cuti direset: {$resetCount}");
        $this->info("========================================");
        
        
        
        
        
        
        return 0;
    }
}