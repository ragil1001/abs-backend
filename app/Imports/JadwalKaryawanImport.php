<?php

namespace App\Imports;

use App\Models\JadwalKaryawan;
use App\Models\KaryawanProject;
use App\Models\Karyawan;
use App\Models\Presensi;
use App\Models\PengajuanIzin;
use App\Services\NotificationService;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JadwalKaryawanImport implements ToModel, WithStartRow
{
    protected $projectId;
    protected $periodStart;
    protected $validShiftCodes;
    protected $successCount = 0;
    protected $errors = [];
    protected $processedKaryawan = [];
    protected $jadwalsByKaryawanProject = [];
    protected $notificationService;
    protected $skippedPastCount = 0;

    public function __construct($projectId, $periodStart, $validShiftCodes = [])
    {
        $this->projectId = $projectId;
        $this->periodStart = $periodStart;
        $this->validShiftCodes = array_map('strtoupper', $validShiftCodes);
        $this->notificationService = app(NotificationService::class);
    }

    public function model(array $row)
    {
        try {
            
            if (empty($row[1]) || empty($row[2])) {
                return null;
            }

            $no = trim($row[0]);
            $nik = trim($row[1]);
            $nama = trim($row[2]);
            
            
            $karyawan = Karyawan::where('nik', $nik)->first();
            if (!$karyawan) {
                $this->errors[] = "NIK {$nik} tidak ditemukan dalam database";
                return null;
            }

            
            $karyawanProject = KaryawanProject::where('karyawan_id', $karyawan->id)
                                              ->where('project_id', $this->projectId)
                                              ->where('status', 'aktif')
                                              ->first();

            if (!$karyawanProject) {
                $this->errors[] = "Karyawan {$karyawan->nama} (NIK: {$nik}) belum di-assign ke project ini atau tidak aktif";
                return null;
            }

            
            $shifts = [];
            $totalColumns = count($row);
            
            for ($i = 4; $i < $totalColumns; $i++) {
                $shiftCode = isset($row[$i]) ? strtoupper(trim($row[$i])) : '';
                
                if ($shiftCode === '' || $shiftCode === '-') {
                    $shifts[] = 'L';
                    continue;
                }
                
                if (!in_array($shiftCode, $this->validShiftCodes)) {
                    $this->errors[] = "NIK {$nik} baris {$no}: Kode shift '{$shiftCode}' tidak valid (kolom " . ($i + 1) . ")";
                    return null;
                }
                
                $shifts[] = $shiftCode;
            }

            if (empty($shifts)) {
                $this->errors[] = "NIK {$nik}: Tidak ada data shift";
                return null;
            }

            
            $today = Carbon::today()->format('Y-m-d');
            
            $insertData = [];
            $jadwalArray = [];
            
            for ($dayIndex = 0; $dayIndex < count($shifts); $dayIndex++) {
                $tanggalString = $this->addDaysToDate($this->periodStart, $dayIndex);
                
                
                if ($tanggalString < $today) {
                    $this->skippedPastCount++;
                    
                    continue;
                }

                
                $newShiftCode = $shifts[$dayIndex];
                
                
                $this->handleJadwalUpdate(
                    $karyawanProject,
                    $karyawan,
                    $tanggalString,
                    $newShiftCode,
                    $insertData,
                    $jadwalArray
                );
            }

            
            if (!empty($insertData)) {
                foreach ($insertData as $data) {
                    DB::table('jadwal_karyawans')->updateOrInsert(
                        [
                            'karyawan_project_id' => $data['karyawan_project_id'],
                            'tanggal' => $data['tanggal']
                        ],
                        $data
                    );
                }

                
                if (!isset($this->jadwalsByKaryawanProject[$karyawanProject->id])) {
                    $this->jadwalsByKaryawanProject[$karyawanProject->id] = [
                        'karyawan_project' => $karyawanProject,
                        'jadwals' => []
                    ];
                }
                
                $this->jadwalsByKaryawanProject[$karyawanProject->id]['jadwals'] = array_merge(
                    $this->jadwalsByKaryawanProject[$karyawanProject->id]['jadwals'],
                    $jadwalArray
                );

                $this->successCount++;
                $this->processedKaryawan[] = $nik;
            }

            return null;

        } catch (\Exception $e) {
            $nik = isset($row[1]) ? $row[1] : 'unknown';
            $this->errors[] = "NIK {$nik}: " . $e->getMessage();
            
            return null;
        }
    }

    
    private function handleJadwalUpdate(
        $karyawanProject,
        $karyawan,
        $tanggalString,
        $newShiftCode,
        &$insertData,
        &$jadwalArray
    ) {
        
        $existingJadwal = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
            ->where('tanggal', $tanggalString)
            ->first();

        
        $bulan = substr($tanggalString, 0, 7);

        if (!$existingJadwal) {
            
            $insertData[] = [
                'karyawan_project_id' => $karyawanProject->id,
                'tanggal' => $tanggalString,
                'bulan' => $bulan, 
                'shift_code' => $newShiftCode,
                'status' => 'scheduled',
                'keterangan' => null,
                'created_at' => DB::raw('CURRENT_TIMESTAMP'),
                'updated_at' => DB::raw('CURRENT_TIMESTAMP')
            ];
            
            $jadwalArray[] = [
                'tanggal' => $tanggalString,
                'shift_code' => $newShiftCode
            ];
            return;
        }

        $oldShiftCode = strtoupper(trim($existingJadwal->shift_code));
        $newShiftCodeUpper = strtoupper(trim($newShiftCode));

        
        if ($oldShiftCode !== 'L' && $newShiftCodeUpper === 'L') {
            $this->handleShiftToLibur($existingJadwal, $karyawan, $karyawanProject, $tanggalString, $newShiftCode);
        }
        
        elseif ($oldShiftCode === 'L' && $newShiftCodeUpper !== 'L') {
            $this->handleLiburToShift($existingJadwal, $karyawan, $karyawanProject, $tanggalString, $newShiftCode);
        }

        
        $insertData[] = [
            'karyawan_project_id' => $karyawanProject->id,
            'tanggal' => $tanggalString,
            'bulan' => $bulan, 
            'shift_code' => $newShiftCode,
            'status' => 'scheduled',
            'keterangan' => null,
            'created_at' => DB::raw('CURRENT_TIMESTAMP'),
            'updated_at' => DB::raw('CURRENT_TIMESTAMP')
        ];
        
        $jadwalArray[] = [
            'tanggal' => $tanggalString,
            'shift_code' => $newShiftCode
        ];
    }

    
    private function handleShiftToLibur($existingJadwal, $karyawan, $karyawanProject, $tanggalString, $newShiftCode)
    {
        
        $presensiIzin = Presensi::where('jadwal_karyawan_id', $existingJadwal->id)
            ->where('status', 'izin')
            ->first();

        if (!$presensiIzin) {
            return; 
        }

        
        
        
        
        
        

        
        Presensi::where('jadwal_karyawan_id', $existingJadwal->id)
            ->update([
                'status' => 'libur',
                'kategori_izin' => null,
                'keterangan' => 'Hari libur (jadwal diubah dari shift kerja)'
            ]);

        
        $pengajuanIzin = PengajuanIzin::where('status', 'disetujui')
            ->where('tanggal_mulai', '<=', $tanggalString)
            ->where('tanggal_selesai', '>=', $tanggalString)
            ->whereHas('jadwalKaryawan', function($q) use ($karyawanProject) {
                $q->where('karyawan_project_id', $karyawanProject->id);
            })
            ->first();

        if ($pengajuanIzin) {
            $jumlahHariLibur = $this->countLiburDaysManual(
                $pengajuanIzin,
                $karyawanProject->id,
                $tanggalString,
                $newShiftCode
            );

            
            
            
            
            

            
            if ($jumlahHariLibur >= $pengajuanIzin->durasi_hari) {
                if ($pengajuanIzin->kategori_izin === PengajuanIzin::KATEGORI_CUTI_TAHUNAN) {
                    $karyawan->kembalikanCutiTahunan($pengajuanIzin->durasi_hari);
                    
                    
                    
                    
                    
                    
                }

                $pengajuanIzin->update([
                    'status' => 'dibatalkan',
                    'catatan_admin' => 'Otomatis dibatalkan: Jadwal diubah menjadi hari libur'
                ]);

                
                
                
                
            }
            
            elseif ($pengajuanIzin->kategori_izin === PengajuanIzin::KATEGORI_CUTI_TAHUNAN && $jumlahHariLibur > 0) {
                $karyawan->kembalikanCutiTahunan($jumlahHariLibur);
                
                
                
                
                
                
            }
        }
    }

    
    private function handleLiburToShift($existingJadwal, $karyawan, $karyawanProject, $tanggalString, $newShiftCode)
    {
        
        
        
        
        
        

        
        $presensiLibur = Presensi::where('jadwal_karyawan_id', $existingJadwal->id)
            ->where('status', 'libur')
            ->first();

        
        $pengajuanIzin = PengajuanIzin::where('status', 'dibatalkan')
            ->where('tanggal_mulai', '<=', $tanggalString)
            ->where('tanggal_selesai', '>=', $tanggalString)
            ->whereHas('jadwalKaryawan', function($q) use ($karyawanProject) {
                $q->where('karyawan_project_id', $karyawanProject->id);
            })
            ->orderBy('updated_at', 'desc')
            ->first();

        if (!$pengajuanIzin) {
            
            if ($presensiLibur) {
                $presensiLibur->delete();
                
                
                
                
            }
            return;
        }

        
        $jumlahHariLibur = $this->countLiburDaysManual(
            $pengajuanIzin,
            $karyawanProject->id,
            $tanggalString,
            $newShiftCode
        );

        
        
        
        
        

        
        $keterangan = $pengajuanIzin->jenis_izin_lengkap;
        if ($pengajuanIzin->keterangan) {
            $keterangan .= ": " . $pengajuanIzin->keterangan;
        }

        foreach (['masuk', 'pulang'] as $tipe) {
            Presensi::updateOrCreate(
                [
                    'jadwal_karyawan_id' => $existingJadwal->id,
                    'tipe' => $tipe
                ],
                [
                    'tanggal' => $tanggalString,
                    'status' => 'izin',
                    'kategori_izin' => $pengajuanIzin->kategori_izin,
                    'keterangan' => $keterangan,
                    'waktu' => null,
                    'latitude' => null,
                    'longitude' => null,
                    'foto' => null
                ]
            );
        }

        
        
        
        

        
        if ($jumlahHariLibur === 0) {
            
            $pengajuanIzin->update([
                'status' => 'disetujui',
                'catatan_admin' => 'Otomatis direaktivasi: Jadwal dikembalikan ke shift kerja'
            ]);

            
            if ($pengajuanIzin->kategori_izin === PengajuanIzin::KATEGORI_CUTI_TAHUNAN) {
                $karyawan->kurangiCutiTahunan($pengajuanIzin->durasi_hari);
                
                
                
                
                
                
            }

            
            $this->reaktivasiPresensiIzin($pengajuanIzin, $karyawanProject);

            
            
            
            
            
        }
        
        elseif ($pengajuanIzin->kategori_izin === PengajuanIzin::KATEGORI_CUTI_TAHUNAN) {
            $hariKerja = $pengajuanIzin->durasi_hari - $jumlahHariLibur;
            
            if ($hariKerja > 0) {
                $karyawan->kurangiCutiTahunan($hariKerja);
                
                
                
                
                
                
            }
        }
    }

    
    private function reaktivasiPresensiIzin($pengajuanIzin, $karyawanProject)
    {
        
        $jadwals = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
            ->where('tanggal', '>=', $pengajuanIzin->tanggal_mulai->format('Y-m-d'))
            ->where('tanggal', '<=', $pengajuanIzin->tanggal_selesai->format('Y-m-d'))
            ->whereRaw('UPPER(shift_code) != ?', ['L'])
            ->get();

        
        $keterangan = $pengajuanIzin->jenis_izin_lengkap;
        if ($pengajuanIzin->keterangan) {
            $keterangan .= ": " . $pengajuanIzin->keterangan;
        }

        foreach ($jadwals as $jadwal) {
            foreach (['masuk', 'pulang'] as $tipe) {
                Presensi::updateOrCreate(
                    [
                        'jadwal_karyawan_id' => $jadwal->id,
                        'tipe' => $tipe
                    ],
                    [
                        'tanggal' => $jadwal->tanggal,
                        'status' => 'izin',
                        'kategori_izin' => $pengajuanIzin->kategori_izin,
                        'keterangan' => $keterangan,
                        'waktu' => null,
                        'latitude' => null,
                        'longitude' => null,
                        'foto' => null
                    ]
                );
            }

            
            
            
            
            
        }
    }

    
    private function countLiburDaysManual($pengajuanIzin, $karyawanProjectId, $currentTanggal, $currentShiftCode)
    {
        
        $existingLibur = JadwalKaryawan::where('karyawan_project_id', $karyawanProjectId)
            ->whereRaw('UPPER(shift_code) = ?', ['L'])
            ->where('tanggal', '>=', $pengajuanIzin->tanggal_mulai->format('Y-m-d'))
            ->where('tanggal', '<=', $pengajuanIzin->tanggal_selesai->format('Y-m-d'))
            ->where('tanggal', '!=', $currentTanggal)
            ->count();

        
        $totalLibur = $existingLibur;
        if (strtoupper($currentShiftCode) === 'L') {
            $totalLibur += 1;
        }

        
        
        
        
        
        

        return $totalLibur;
    }

    
    private function addDaysToDate($dateString, $days)
    {
        if ($days === 0) return $dateString;
        
        $date = new \DateTime($dateString, new \DateTimeZone('UTC'));
        $date->modify("+{$days} days");
        return $date->format('Y-m-d');
    }

    
    public function sendNotifications()
    {
        if ($this->successCount === 0) {
            
            return;
        }

        

        foreach ($this->jadwalsByKaryawanProject as $kpId => $data) {
            try {
                $karyawanProject = $data['karyawan_project'];
                $jadwals = $data['jadwals'];

                if (empty($jadwals)) {
                    continue;
                }

                
                
                
                
                

                $this->notificationService->notifyKaryawanJadwalBaru(
                    $karyawanProject,
                    $jadwals
                );

            } catch (\Exception $e) {
                throw $e;
            }
        }

        
    }

    public function startRow(): int
    {
        return 9;
    }

    public function getSuccessCount()
    {
        return $this->successCount;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function getProcessedKaryawan()
    {
        return $this->processedKaryawan;
    }

    public function getSkippedPastCount()
    {
        return $this->skippedPastCount;
    }
}