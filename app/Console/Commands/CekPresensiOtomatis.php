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
        $now = Carbon::now();
        $today = Carbon::today();
        
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
            return 0;
        }

        $processedLibur = 0;
        $processedAlpa = 0;
        $processedTidakPresensiPulang = 0;
        $processedHariLiburTidakPulang = 0;
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
                
                // PROSES HARI LIBUR
                if ($shiftCode === 'L' || empty($shiftCode)) {
                    $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                             ->where('tipe', 'masuk')
                                             ->first();
                    
                    if (!$presensiMasuk) {
                        // Tidak ada presensi sama sekali, buat presensi libur otomatis
                        Presensi::buatPresensiLibur($jadwal->id, $jadwal->tanggal);
                        $processedLibur++;
                        $this->info("✓ Libur dibuat untuk jadwal ID: {$jadwal->id} tanggal {$jadwal->tanggal}");
                    } 
                    // Jika ada presensi masuk (bukan libur), cek presensi pulang
                    elseif ($presensiMasuk->status !== 'libur') {
                        $this->prosesHariLiburDenganPresensi($jadwal, $presensiMasuk, $now, $processedHariLiburTidakPulang, $notificationsSent);
                    }
                    
                    continue; // Skip ke jadwal berikutnya
                }

                // Ambil info shift untuk hari kerja
                $project = $jadwal->karyawanProject->project;
                
                $shift = $project->shiftProjects()
                    ->whereRaw('UPPER(kode) = ?', [$shiftCode])
                    ->first();

                if (!$shift) {                    
                    $shiftNotFoundCount++;
                    continue;
                }

                // Hitung waktu shift berdasarkan tanggal jadwal (bukan hari ini)
                $waktuMulaiShift = Carbon::parse($tanggalJadwal->format('Y-m-d') . ' ' . $shift->waktu_mulai);
                $waktuSelesaiShift = Carbon::parse($tanggalJadwal->format('Y-m-d') . ' ' . $shift->waktu_selesai);
                
                // Jika shift melewati tengah malam, tambah 1 hari ke waktu selesai
                if ($waktuSelesaiShift->lessThanOrEqualTo($waktuMulaiShift)) {
                    $waktuSelesaiShift->addDay();
                }

                // PROSES ALPA (Tidak Presensi Masuk)
                // Batas alpa setelah shift berakhir
                $batasAlpa = $waktuSelesaiShift->copy();
                
                if ($now->greaterThan($batasAlpa)) {
                    $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                             ->where('tipe', 'masuk')
                                             ->first();

                    if (!$presensiMasuk) {
                        // Buat presensi alpa untuk masuk DAN pulang
                        $presensi = Presensi::buatPresensiAlpa($jadwal->id, $jadwal->tanggal);
                        $processedAlpa++;
                        $this->info("✓ Alpa dibuat untuk jadwal ID: {$jadwal->id} tanggal {$jadwal->tanggal}");
                        
                        // SEND NOTIFICATION to karyawan
                        try {
                            if ($presensi) {
                                $this->notificationService->notifyKaryawanAlpa($presensi);
                                $notificationsSent++;
                            }
                        } catch (\Exception $e) {
                            throw $e;
                        }
                        
                        continue; // Skip ke jadwal berikutnya
                    }
                }

                // PROSES TIDAK PRESENSI PULANG
                // Batas 5 jam setelah shift berakhir
                $batasTidakPresensiPulang = $waktuSelesaiShift->copy()->addHours(5);
                
                if ($now->greaterThan($batasTidakPresensiPulang)) {
                    $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                             ->where('tipe', 'masuk')
                                             ->whereIn('status', ['hadir', 'terlambat'])
                                             ->first();
                    
                    $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                              ->where('tipe', 'pulang')
                                              ->first();

                    if ($presensiMasuk && !$presensiPulang) {
                        $presensi = Presensi::cekTidakPresensiPulang($jadwal->id, $jadwal->tanggal);
                        $processedTidakPresensiPulang++;
                        $this->info("✓ Tidak presensi pulang dibuat untuk jadwal ID: {$jadwal->id} tanggal {$jadwal->tanggal}");
                        
                        try {
                            if ($presensi) {
                                $this->notificationService->notifyKaryawanTidakPresensiPulang($presensi);
                                $notificationsSent++;
                            }
                        } catch (\Exception $e) {
                            throw $e;
                        }
                    }
                }

            } catch (\Exception $e) {
                throw $e;
            }
        }

        return 0;
    }

    /**
     * Proses hari libur yang ada presensi masuk
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

        // Hitung batas waktu 10 jam setelah presensi masuk
        $waktuMasuk = Carbon::parse($presensiMasuk->tanggal . ' ' . $presensiMasuk->waktu);
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

            // SEND NOTIFICATION to karyawan
            try {
                $presensiPulangFinal = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                               ->where('tipe', 'pulang')
                                               ->first();
                
                if ($presensiPulangFinal) {
                    $this->notificationService->notifyKaryawanTidakPresensiPulang($presensiPulangFinal);
                    $notificationsSent++;
                }
            } catch (\Exception $e) {
                throw $e;
            }
        }
    }
}