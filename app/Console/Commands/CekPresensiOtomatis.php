<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\JadwalKaryawan;
use App\Models\Presensi;
use App\Models\ShiftProject;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CekPresensiOtomatis extends Command
{
    protected $signature = 'presensi:cek-otomatis';
    protected $description = 'Cek dan buat presensi otomatis untuk libur, alpa, dan tidak presensi pulang';

    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }

    public function handle()
    {
        $this->info('🔄 Memulai pengecekan presensi otomatis...');
        
        $now = Carbon::now();
        $today = Carbon::today();
        
        $this->info("⏰ Waktu server: {$now->format('Y-m-d H:i:s')}");
        
        // ✅ IMPROVEMENT: Process historical missed dates (up to 7 days back)
        $sevenDaysAgo = Carbon::today()->subDays(7);
        
        // Get jadwal from 7 days ago until today WITH EAGER LOADING
        $jadwals = JadwalKaryawan::with([
                'karyawanProject.project.shiftProjects',
                'karyawanProject.karyawan.divisi',
                'karyawanProject.karyawan.jabatan'
            ])
            ->where('tanggal', '>=', $sevenDaysAgo->format('Y-m-d'))
            ->where('tanggal', '<=', $today->format('Y-m-d'))
            ->orderBy('tanggal', 'asc')
            ->get();

        if ($jadwals->isEmpty()) {
            $this->info('ℹ️ Tidak ada jadwal untuk diproses');
            return 0;
        }

        $processedLibur = 0;
        $processedAlpa = 0;
        $processedTidakPresensiPulang = 0;
        $processedHariLiburTidakPulang = 0; // ✅ NEW
        $skippedFuture = 0;
        $notificationsSent = 0;
        $shiftNotFoundCount = 0;

        foreach ($jadwals as $jadwal) {
            try {
                $tanggalJadwal = Carbon::parse($jadwal->tanggal);
                $shiftCode = strtoupper(trim($jadwal->shift_code ?? ''));
                
                // Skip if date is in the future
                if ($tanggalJadwal->isFuture()) {
                    $skippedFuture++;
                    continue;
                }
                
                // ========== 1. PROSES HARI LIBUR ==========
                if ($shiftCode === 'L' || empty($shiftCode)) {
                    // ✅ CRITICAL: Check apakah ada presensi masuk di hari libur
                    $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                             ->where('tipe', 'masuk')
                                             ->first();
                    
                    if (!$presensiMasuk) {
                        // Tidak ada presensi sama sekali, buat presensi libur otomatis
                        Presensi::buatPresensiLibur($jadwal->id, $jadwal->tanggal);
                        $processedLibur++;
                        $this->info("✓ Libur dibuat untuk jadwal ID: {$jadwal->id} tanggal {$jadwal->tanggal}");
                    } 
                    // ✅ NEW: Jika ada presensi masuk (bukan libur), cek presensi pulang
                    elseif ($presensiMasuk->status !== 'libur') {
                        $this->prosesHariLiburDenganPresensi($jadwal, $presensiMasuk, $now, $processedHariLiburTidakPulang, $notificationsSent);
                    }
                    
                    continue; // Skip ke jadwal berikutnya
                }

                // Ambil info shift untuk hari kerja
                $project = $jadwal->karyawanProject->project;
                
                // ✅ FIX: Use case-insensitive query for shift lookup
                $shift = $project->shiftProjects()
                    ->whereRaw('UPPER(kode) = ?', [$shiftCode])
                    ->first();

                if (!$shift) {
                    // ✅ Enhanced logging for debugging
                    // Log::warning("⚠️ Shift tidak ditemukan untuk jadwal ID: {$jadwal->id}", [
                    //     'jadwal_id' => $jadwal->id,
                    //     'tanggal' => $jadwal->tanggal,
                    //     'shift_code_requested' => $shiftCode,
                    //     'shift_code_original' => $jadwal->shift_code,
                    //     'project_id' => $project->id,
                    //     'project_nama' => $project->nama,
                    //     'available_shifts' => $project->shiftProjects->pluck('kode')->toArray(),
                    //     'karyawan' => $jadwal->karyawanProject->karyawan->nama ?? 'N/A'
                    // ]);
                    
                    $shiftNotFoundCount++;
                    continue;
                }

                // ✅ FIX: Hitung waktu shift berdasarkan tanggal jadwal (bukan hari ini)
                $waktuMulaiShift = Carbon::parse($tanggalJadwal->format('Y-m-d') . ' ' . $shift->waktu_mulai);
                $waktuSelesaiShift = Carbon::parse($tanggalJadwal->format('Y-m-d') . ' ' . $shift->waktu_selesai);
                
                // ✅ CRITICAL: Jika shift melewati tengah malam, tambah 1 hari ke waktu selesai
                if ($waktuSelesaiShift->lessThanOrEqualTo($waktuMulaiShift)) {
                    $waktuSelesaiShift->addDay();
                }

                // ========== 2. PROSES ALPA (Tidak Presensi Masuk) ==========
                // Batas alpa: setelah shift berakhir
                $batasAlpa = $waktuSelesaiShift->copy();
                
                if ($now->greaterThan($batasAlpa)) {
                    $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                             ->where('tipe', 'masuk')
                                             ->first();

                    if (!$presensiMasuk) {
                        // ✅ Buat presensi alpa untuk masuk DAN pulang
                        $presensi = Presensi::buatPresensiAlpa($jadwal->id, $jadwal->tanggal);
                        $processedAlpa++;
                        $this->info("✓ Alpa dibuat untuk jadwal ID: {$jadwal->id} tanggal {$jadwal->tanggal}");
                        
                        // 📢 SEND NOTIFICATION to karyawan
                        try {
                            if ($presensi) {
                                $this->notificationService->notifyKaryawanAlpa($presensi);
                                $notificationsSent++;
                                $this->info("  📱 Notifikasi alpa dikirim ke karyawan: {$jadwal->karyawanProject->karyawan->nama}");
                            }
                        } catch (\Exception $e) {
                            Log::error("Failed to send alpa notification for jadwal {$jadwal->id}: " . $e->getMessage());
                        }
                        
                        continue; // Skip ke jadwal berikutnya
                    }
                }

                // ========== 3. PROSES TIDAK PRESENSI PULANG ==========
                // Batas: 5 jam setelah shift berakhir
                $batasTidakPresensiPulang = $waktuSelesaiShift->copy()->addHours(5);
                
                if ($now->greaterThan($batasTidakPresensiPulang)) {
                    $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                             ->where('tipe', 'masuk')
                                             ->whereIn('status', ['hadir', 'terlambat']) // ✅ Hanya jika masuk hadir/terlambat
                                             ->first();
                    
                    $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                              ->where('tipe', 'pulang')
                                              ->first();

                    // ✅ Hanya proses jika: ada presensi masuk normal TAPI tidak ada presensi pulang
                    if ($presensiMasuk && !$presensiPulang) {
                        $presensi = Presensi::cekTidakPresensiPulang($jadwal->id, $jadwal->tanggal);
                        $processedTidakPresensiPulang++;
                        $this->info("✓ Tidak presensi pulang dibuat untuk jadwal ID: {$jadwal->id} tanggal {$jadwal->tanggal}");
                        
                        // 📢 SEND NOTIFICATION to karyawan
                        try {
                            if ($presensi) {
                                $this->notificationService->notifyKaryawanTidakPresensiPulang($presensi);
                                $notificationsSent++;
                                $this->info("  📱 Notifikasi tidak presensi pulang dikirim ke: {$jadwal->karyawanProject->karyawan->nama}");
                            }
                        } catch (\Exception $e) {
                            Log::error("Failed to send tidak presensi pulang notification for jadwal {$jadwal->id}: " . $e->getMessage());
                        }
                    }
                }

            } catch (\Exception $e) {
                Log::error("❌ Error processing jadwal ID {$jadwal->id}: " . $e->getMessage());
                $this->error("❌ Error processing jadwal ID {$jadwal->id}: " . $e->getMessage());
            }
        }

        // ========== SUMMARY ==========
        $this->newLine();
        $this->info("========================================");
        $this->info("✅ Selesai! Ringkasan:");
        $this->info("========================================");
        $this->info("📅 Libur diproses: {$processedLibur}");
        $this->info("❌ Alpa diproses: {$processedAlpa}");
        $this->info("🏠 Tidak presensi pulang diproses: {$processedTidakPresensiPulang}");
        $this->info("🏖️ Hari libur tidak pulang: {$processedHariLiburTidakPulang}"); // ✅ NEW
        $this->info("📱 Notifikasi dikirim: {$notificationsSent}");
        $this->info("⏭ Jadwal masa depan dilewati: {$skippedFuture}");
        
        if ($shiftNotFoundCount > 0) {
            $this->warn("⚠️  Shift tidak ditemukan: {$shiftNotFoundCount}");
            $this->warn("    Lihat log untuk detail lebih lanjut");
        }
        
        $this->info("========================================");
        
        // Log::info("✅ Presensi otomatis selesai", [
        //     'libur' => $processedLibur,
        //     'alpa' => $processedAlpa,
        //     'tidak_presensi_pulang' => $processedTidakPresensiPulang,
        //     'hari_libur_tidak_pulang' => $processedHariLiburTidakPulang,
        //     'notifications_sent' => $notificationsSent,
        //     'skipped_future' => $skippedFuture,
        //     'shift_not_found' => $shiftNotFoundCount,
        // ]);

        return 0;
    }

    /**
     * ✅ NEW: Proses hari libur yang ada presensi masuk
     * Cek apakah sudah 10 jam dari presensi masuk untuk set "tidak presensi pulang"
     */
    private function prosesHariLiburDenganPresensi($jadwal, $presensiMasuk, $now, &$processedHariLiburTidakPulang, &$notificationsSent)
    {
        // Cek apakah sudah ada presensi pulang
        $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                  ->where('tipe', 'pulang')
                                  ->first();

        // Jika sudah ada presensi pulang dan bukan status libur, skip
        if ($presensiPulang && $presensiPulang->status !== 'libur') {
            return;
        }

        // Hitung batas waktu: 10 jam setelah presensi masuk
        $tanggalMasuk = Carbon::parse($presensiMasuk->tanggal)->format('Y-m-d');
        $waktuMasuk = Carbon::parse($tanggalMasuk . ' ' . $presensiMasuk->waktu);
        $batasTidakPresensiPulang = $waktuMasuk->copy()->addHours(10);

        // Jika sudah lewat 10 jam dan belum presensi pulang
        if ($now->greaterThan($batasTidakPresensiPulang)) {
            if (!$presensiPulang) {
                // Buat presensi pulang dengan status tidak_presensi_pulang
                Presensi::create([
                    'jadwal_karyawan_id' => $jadwal->id,
                    'tanggal' => $jadwal->tanggal,
                    'tipe' => 'pulang',
                    'status' => 'tidak_presensi_pulang',
                    'waktu' => null,
                    'latitude' => null,
                    'longitude' => null,
                    'foto' => null,
                    'keterangan' => 'Tidak presensi pulang di hari libur (lebih dari 10 jam sejak presensi masuk)'
                ]);
            } else {
                // Update presensi pulang yang masih libur
                $presensiPulang->update([
                    'status' => 'tidak_presensi_pulang',
                    'keterangan' => 'Tidak presensi pulang di hari libur (lebih dari 10 jam sejak presensi masuk)'
                ]);
            }

            $processedHariLiburTidakPulang++;
            $this->info("✓ Tidak presensi pulang (hari libur) untuk jadwal ID: {$jadwal->id} tanggal {$jadwal->tanggal}");

            // 📢 SEND NOTIFICATION to karyawan
            try {
                $presensiPulangFinal = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                               ->where('tipe', 'pulang')
                                               ->first();
                
                if ($presensiPulangFinal) {
                    $this->notificationService->notifyKaryawanTidakPresensiPulang($presensiPulangFinal);
                    $notificationsSent++;
                    $this->info("  📱 Notifikasi tidak presensi pulang (hari libur) dikirim ke: {$jadwal->karyawanProject->karyawan->nama}");
                }
            } catch (\Exception $e) {
                Log::error("Failed to send tidak presensi pulang notification for jadwal {$jadwal->id}: " . $e->getMessage());
            }
        }
    }
}