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
                
                
                if ($tanggalJadwal->isFuture()) {
                    $skippedFuture++;
                    continue;
                }
                
                
                if ($shiftCode === 'L' || empty($shiftCode)) {
                    $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                             ->where('tipe', 'masuk')
                                             ->first();
                    
                    if (!$presensiMasuk) {
                        
                        Presensi::buatPresensiLibur($jadwal->id, $jadwal->tanggal);
                        $processedLibur++;
                        $this->info("✓ Libur dibuat untuk jadwal ID: {$jadwal->id} tanggal {$jadwal->tanggal}");
                    } 
                    
                    elseif ($presensiMasuk->status !== 'libur') {
                        $this->prosesHariLiburDenganPresensi($jadwal, $presensiMasuk, $now, $processedHariLiburTidakPulang, $notificationsSent);
                    }
                    
                    continue; 
                }

                
                $project = $jadwal->karyawanProject->project;
                
                $shift = $project->shiftProjects()
                    ->whereRaw('UPPER(kode) = ?', [$shiftCode])
                    ->first();

                if (!$shift) {                    
                    $shiftNotFoundCount++;
                    continue;
                }

                
                $waktuMulaiShift = Carbon::parse($tanggalJadwal->format('Y-m-d') . ' ' . $shift->waktu_mulai);
                $waktuSelesaiShift = Carbon::parse($tanggalJadwal->format('Y-m-d') . ' ' . $shift->waktu_selesai);
                
                
                if ($waktuSelesaiShift->lessThanOrEqualTo($waktuMulaiShift)) {
                    $waktuSelesaiShift->addDay();
                }

                
                
                $batasAlpa = $waktuSelesaiShift->copy();
                
                if ($now->greaterThan($batasAlpa)) {
                    $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                             ->where('tipe', 'masuk')
                                             ->first();

                    if (!$presensiMasuk) {
                        
                        $presensi = Presensi::buatPresensiAlpa($jadwal->id, $jadwal->tanggal);
                        $processedAlpa++;
                        $this->info("✓ Alpa dibuat untuk jadwal ID: {$jadwal->id} tanggal {$jadwal->tanggal}");
                        
                        
                        try {
                            if ($presensi) {
                                $this->notificationService->notifyKaryawanAlpa($presensi);
                                $notificationsSent++;
                            }
                        } catch (\Exception $e) {
                            throw $e;
                        }
                        
                        continue; 
                    }
                }

                
                
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

    
    private function prosesHariLiburDenganPresensi($jadwal, $presensiMasuk, $now, &$processedHariLiburTidakPulang, &$notificationsSent)
    {
        
        $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                  ->where('tipe', 'pulang')
                                  ->first();

        
        if ($presensiPulang && $presensiPulang->status !== 'libur') {
            return;
        }

        
        $waktuMasuk = Carbon::parse($presensiMasuk->tanggal . ' ' . $presensiMasuk->waktu);
        $batasTidakPresensiPulang = $waktuMasuk->copy()->addHours(10);

        
        if ($now->greaterThan($batasTidakPresensiPulang)) {
            if (!$presensiPulang) {
                
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
                
                $presensiPulang->update([
                    'status' => 'tidak_presensi_pulang',
                    'keterangan' => 'Tidak presensi pulang di hari libur (lebih dari 10 jam sejak presensi masuk)'
                ]);
            }

            $processedHariLiburTidakPulang++;

            
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