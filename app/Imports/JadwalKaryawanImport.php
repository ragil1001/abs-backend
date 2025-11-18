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
            // Skip empty rows
            if (empty($row[1]) || empty($row[2])) {
                return null;
            }

            $no = trim($row[0]);
            $nik = trim($row[1]);
            $nama = trim($row[2]);
            
            // Find karyawan
            $karyawan = Karyawan::where('nik', $nik)->first();
            if (!$karyawan) {
                $this->errors[] = "NIK {$nik} tidak ditemukan dalam database";
                return null;
            }

            // Find karyawan_project assignment
            $karyawanProject = KaryawanProject::where('karyawan_id', $karyawan->id)
                                              ->where('project_id', $this->projectId)
                                              ->where('status', 'aktif')
                                              ->first();

            if (!$karyawanProject) {
                $this->errors[] = "Karyawan {$karyawan->nama} (NIK: {$nik}) belum di-assign ke project ini atau tidak aktif";
                return null;
            }

            // Extract shift codes
            $shifts = [];
            $totalColumns = count($row);
            
            for ($i = 4; $i < $totalColumns; $i++) {
                $shiftCode = isset($row[$i]) ? strtoupper(trim($row[$i])) : '';
                
                if ($shiftCode === '' || $shiftCode === '-') {
                    $shifts[] = null;
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

            // ✅ Process shifts one by one dengan validasi tanggal
            $today = Carbon::today()->format('Y-m-d');
            
            $insertData = [];
            $jadwalArray = [];
            
            for ($dayIndex = 0; $dayIndex < count($shifts); $dayIndex++) {
                $tanggalString = $this->addDaysToDate($this->periodStart, $dayIndex);
                
                // ✅ SKIP: Jangan update jadwal yang sudah lewat
                if ($tanggalString < $today) {
                    $this->skippedPastCount++;
                    // Log::info("⏭️ Skip jadwal masa lalu: {$tanggalString} untuk {$karyawan->nama}");
                    continue;
                }

                // ✅ HANDLE: Jadwal masa depan
                $newShiftCode = $shifts[$dayIndex];
                
                // ✅ FIX: Tidak kirim parameter $bulan lagi
                $this->handleJadwalUpdate(
                    $karyawanProject,
                    $karyawan,
                    $tanggalString,
                    $newShiftCode,
                    $insertData,
                    $jadwalArray
                );
            }

            // Insert/Update ke database
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

                // Track untuk notifikasi
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
            // Log::error("Error importing jadwal for NIK {$nik}: " . $e->getMessage());
            return null;
        }
    }

    // ✅ FIX: Hapus parameter $bulan, hitung dari $tanggalString
    private function handleJadwalUpdate(
        $karyawanProject,
        $karyawan,
        $tanggalString,
        $newShiftCode,
        &$insertData,
        &$jadwalArray
    ) {
        // Cek jadwal existing
        $existingJadwal = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
            ->where('tanggal', $tanggalString)
            ->first();

        // ✅ CRITICAL FIX: Hitung bulan dari tanggal spesifik
        $bulan = substr($tanggalString, 0, 7);

        if (!$existingJadwal) {
            // Jadwal baru, langsung insert
            $insertData[] = [
                'karyawan_project_id' => $karyawanProject->id,
                'tanggal' => $tanggalString,
                'bulan' => $bulan, // ✅ Bulan sesuai dengan tanggalnya
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

        // ✅ SCENARIO 1: Shift Kerja → Libur (batalkan izin)
        if ($oldShiftCode !== 'L' && $newShiftCodeUpper === 'L') {
            $this->handleShiftToLibur($existingJadwal, $karyawan, $karyawanProject, $tanggalString, $newShiftCode);
        }
        // ✅ SCENARIO 2: Libur → Shift Kerja (reaktivasi izin)
        elseif ($oldShiftCode === 'L' && $newShiftCodeUpper !== 'L') {
            $this->handleLiburToShift($existingJadwal, $karyawan, $karyawanProject, $tanggalString, $newShiftCode);
        }

        // ✅ Prepare data untuk insert/update
        $insertData[] = [
            'karyawan_project_id' => $karyawanProject->id,
            'tanggal' => $tanggalString,
            'bulan' => $bulan, // ✅ Bulan sesuai dengan tanggalnya
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

    /**
     * ✅ SCENARIO 1: Shift Kerja → Libur (batalkan izin)
     */
    private function handleShiftToLibur($existingJadwal, $karyawan, $karyawanProject, $tanggalString, $newShiftCode)
    {
        // Cek presensi izin existing
        $presensiIzin = Presensi::where('jadwal_karyawan_id', $existingJadwal->id)
            ->where('status', 'izin')
            ->first();

        if (!$presensiIzin) {
            return; // Tidak ada izin, skip
        }

        // Log::info("🔄 SHIFT → LIBUR: Batalkan izin", [
        //     'karyawan' => $karyawan->nama,
        //     'tanggal' => $tanggalString,
        //     'old_shift' => $existingJadwal->shift_code,
        //     'new_shift' => $newShiftCode
        // ]);

        // Update presensi dari izin jadi libur
        Presensi::where('jadwal_karyawan_id', $existingJadwal->id)
            ->update([
                'status' => 'libur',
                'kategori_izin' => null,
                'keterangan' => 'Hari libur (jadwal diubah dari shift kerja)'
            ]);

        // Cari pengajuan izin
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

            // Log::info("📊 Izin periode {$pengajuanIzin->tanggal_mulai} - {$pengajuanIzin->tanggal_selesai}", [
            //     'kategori' => $pengajuanIzin->kategori_izin,
            //     'hari_libur' => $jumlahHariLibur,
            //     'durasi_izin' => $pengajuanIzin->durasi_hari
            // ]);

            // Jika semua hari jadi libur, batalkan izin
            if ($jumlahHariLibur >= $pengajuanIzin->durasi_hari) {
                if ($pengajuanIzin->kategori_izin === PengajuanIzin::KATEGORI_CUTI_TAHUNAN) {
                    $karyawan->kembalikanCutiTahunan($pengajuanIzin->durasi_hari);
                    
                    // Log::info("✅ Cuti tahunan dikembalikan (FULL)", [
                    //     'karyawan' => $karyawan->nama,
                    //     'jumlah' => $pengajuanIzin->durasi_hari,
                    //     'sisa_baru' => $karyawan->fresh()->sisa_cuti_tahunan
                    // ]);
                }

                $pengajuanIzin->update([
                    'status' => 'dibatalkan',
                    'catatan_admin' => 'Otomatis dibatalkan: Jadwal diubah menjadi hari libur'
                ]);

                // Log::info("❌ Pengajuan izin dibatalkan otomatis", [
                //     'pengajuan_id' => $pengajuanIzin->id,
                //     'karyawan' => $karyawan->nama
                // ]);
            }
            // Jika sebagian jadi libur
            elseif ($pengajuanIzin->kategori_izin === PengajuanIzin::KATEGORI_CUTI_TAHUNAN && $jumlahHariLibur > 0) {
                $karyawan->kembalikanCutiTahunan($jumlahHariLibur);
                
                // Log::info("✅ Cuti tahunan dikembalikan (PARTIAL)", [
                //     'karyawan' => $karyawan->nama,
                //     'jumlah' => $jumlahHariLibur,
                //     'sisa_baru' => $karyawan->fresh()->sisa_cuti_tahunan
                // ]);
            }
        }
    }

    /**
     * ✅ SCENARIO 2: Libur → Shift Kerja (reaktivasi izin)
     */
    private function handleLiburToShift($existingJadwal, $karyawan, $karyawanProject, $tanggalString, $newShiftCode)
    {
        // Log::info("🔄 LIBUR → SHIFT: Reaktivasi izin", [
        //     'karyawan' => $karyawan->nama,
        //     'tanggal' => $tanggalString,
        //     'old_shift' => $existingJadwal->shift_code,
        //     'new_shift' => $newShiftCode
        // ]);

        // ✅ STEP 1: Cek presensi existing untuk tanggal ini
        $presensiLibur = Presensi::where('jadwal_karyawan_id', $existingJadwal->id)
            ->where('status', 'libur')
            ->first();

        // Cek apakah ada pengajuan izin yang dibatalkan untuk tanggal ini
        $pengajuanIzin = PengajuanIzin::where('status', 'dibatalkan')
            ->where('tanggal_mulai', '<=', $tanggalString)
            ->where('tanggal_selesai', '>=', $tanggalString)
            ->whereHas('jadwalKaryawan', function($q) use ($karyawanProject) {
                $q->where('karyawan_project_id', $karyawanProject->id);
            })
            ->orderBy('updated_at', 'desc')
            ->first();

        if (!$pengajuanIzin) {
            // ✅ Jika tidak ada pengajuan izin, hapus presensi libur
            if ($presensiLibur) {
                $presensiLibur->delete();
                // Log::info("🗑️ Presensi libur dihapus (tidak ada izin terkait)", [
                //     'jadwal_id' => $existingJadwal->id,
                //     'tanggal' => $tanggalString
                // ]);
            }
            return;
        }

        // Cek apakah SEMUA tanggal dalam periode izin sekarang adalah shift kerja
        $jumlahHariLibur = $this->countLiburDaysManual(
            $pengajuanIzin,
            $karyawanProject->id,
            $tanggalString,
            $newShiftCode
        );

        // Log::info("📊 Check reaktivasi izin periode {$pengajuanIzin->tanggal_mulai} - {$pengajuanIzin->tanggal_selesai}", [
        //     'kategori' => $pengajuanIzin->kategori_izin,
        //     'hari_libur' => $jumlahHariLibur,
        //     'durasi_izin' => $pengajuanIzin->durasi_hari
        // ]);

        // ✅ STEP 2: Update presensi untuk tanggal ini jadi izin
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

        // Log::info("✅ Presensi tanggal {$tanggalString} diubah jadi izin", [
        //     'jadwal_id' => $existingJadwal->id,
        //     'kategori' => $pengajuanIzin->kategori_izin
        // ]);

        // ✅ STEP 3: Jika tidak ada lagi hari libur dalam periode izin, reaktivasi izin penuh
        if ($jumlahHariLibur === 0) {
            // Reaktivasi pengajuan izin
            $pengajuanIzin->update([
                'status' => 'disetujui',
                'catatan_admin' => 'Otomatis direaktivasi: Jadwal dikembalikan ke shift kerja'
            ]);

            // Potong cuti tahunan lagi jika cuti tahunan
            if ($pengajuanIzin->kategori_izin === PengajuanIzin::KATEGORI_CUTI_TAHUNAN) {
                $karyawan->kurangiCutiTahunan($pengajuanIzin->durasi_hari);
                
                // Log::info("➖ Cuti tahunan dipotong kembali (REAKTIVASI FULL)", [
                //     'karyawan' => $karyawan->nama,
                //     'jumlah' => $pengajuanIzin->durasi_hari,
                //     'sisa_baru' => $karyawan->fresh()->sisa_cuti_tahunan
                // ]);
            }

            // Update semua presensi lain dalam periode jadi izin juga
            $this->reaktivasiPresensiIzin($pengajuanIzin, $karyawanProject);

            // Log::info("✅ Pengajuan izin direaktivasi penuh", [
            //     'pengajuan_id' => $pengajuanIzin->id,
            //     'karyawan' => $karyawan->nama,
            //     'kategori' => $pengajuanIzin->kategori_izin
            // ]);
        }
        // ✅ STEP 4: Jika masih ada hari libur, kembalikan cuti sebagian
        elseif ($pengajuanIzin->kategori_izin === PengajuanIzin::KATEGORI_CUTI_TAHUNAN) {
            $hariKerja = $pengajuanIzin->durasi_hari - $jumlahHariLibur;
            
            if ($hariKerja > 0) {
                $karyawan->kurangiCutiTahunan($hariKerja);
                
                // Log::info("➖ Cuti tahunan dipotong sebagian (REAKTIVASI PARTIAL)", [
                //     'karyawan' => $karyawan->nama,
                //     'jumlah' => $hariKerja,
                //     'sisa_baru' => $karyawan->fresh()->sisa_cuti_tahunan
                // ]);
            }
        }
    }

    /**
     * ✅ Reaktivasi semua presensi dalam periode izin
     */
    private function reaktivasiPresensiIzin($pengajuanIzin, $karyawanProject)
    {
        // Get semua jadwal dalam periode izin
        $jadwals = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
            ->where('tanggal', '>=', $pengajuanIzin->tanggal_mulai->format('Y-m-d'))
            ->where('tanggal', '<=', $pengajuanIzin->tanggal_selesai->format('Y-m-d'))
            ->whereRaw('UPPER(shift_code) != ?', ['L'])
            ->get();

        // Build keterangan dengan jenis izin lengkap
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

            // Log::info("✅ Presensi direaktivasi jadi izin", [
            //     'jadwal_id' => $jadwal->id,
            //     'tanggal' => $jadwal->tanggal,
            //     'kategori' => $pengajuanIzin->kategori_izin
            // ]);
        }
    }

    /**
     * ✅ Hitung jumlah hari yang jadi libur dalam periode izin (MANUAL)
     */
    private function countLiburDaysManual($pengajuanIzin, $karyawanProjectId, $currentTanggal, $currentShiftCode)
    {
        // Hitung dari database (yang sudah libur sebelumnya)
        $existingLibur = JadwalKaryawan::where('karyawan_project_id', $karyawanProjectId)
            ->whereRaw('UPPER(shift_code) = ?', ['L'])
            ->where('tanggal', '>=', $pengajuanIzin->tanggal_mulai->format('Y-m-d'))
            ->where('tanggal', '<=', $pengajuanIzin->tanggal_selesai->format('Y-m-d'))
            ->where('tanggal', '!=', $currentTanggal)
            ->count();

        // Tambah/kurang berdasarkan shift code yang sedang diproses
        $totalLibur = $existingLibur;
        if (strtoupper($currentShiftCode) === 'L') {
            $totalLibur += 1;
        }

        // Log::info("🔢 Count Libur Manual", [
        //     'existing_libur' => $existingLibur,
        //     'current_tanggal' => $currentTanggal,
        //     'current_shift' => $currentShiftCode,
        //     'total_libur' => $totalLibur
        // ]);

        return $totalLibur;
    }

    /**
     * Add days to a date string without using Carbon
     */
    private function addDaysToDate($dateString, $days)
    {
        if ($days === 0) return $dateString;
        
        $date = new \DateTime($dateString, new \DateTimeZone('UTC'));
        $date->modify("+{$days} days");
        return $date->format('Y-m-d');
    }

    /**
     * Send notifications after import
     */
    public function sendNotifications()
    {
        if ($this->successCount === 0) {
            // Log::info('No jadwal imported, skipping notifications');
            return;
        }

        // Log::info('Sending notifications for ' . count($this->jadwalsByKaryawanProject) . ' karyawan');

        foreach ($this->jadwalsByKaryawanProject as $kpId => $data) {
            try {
                $karyawanProject = $data['karyawan_project'];
                $jadwals = $data['jadwals'];

                if (empty($jadwals)) {
                    continue;
                }

                // Log::info('Sending jadwal baru notification', [
                //     'karyawan_project_id' => $kpId,
                //     'karyawan_id' => $karyawanProject->karyawan_id,
                //     'total_jadwals' => count($jadwals)
                // ]);

                $this->notificationService->notifyKaryawanJadwalBaru(
                    $karyawanProject,
                    $jadwals
                );

            } catch (\Exception $e) {
                Log::error('Error sending jadwal notification for karyawan_project ' . $kpId . ': ' . $e->getMessage());
            }
        }

        // Log::info('Jadwal notifications completed');
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