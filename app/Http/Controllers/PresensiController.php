<?php

namespace App\Http\Controllers;

use App\Models\Presensi;
use App\Models\JadwalKaryawan;
use App\Models\KaryawanProject;
use App\Models\Project;
use App\Models\ShiftProject;
use Illuminate\Http\Request;
use App\Models\PengajuanIzin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PresensiController extends Controller
{
    // Di method cekPresensi(), tambahkan validasi untuk presensi pulang

public function cekPresensi(Request $request)
{
    try {
        $karyawan = $request->user();
        $today = Carbon::today();
        $now = Carbon::now();

        $karyawanProject = $karyawan->activeProject;
        
        if (!$karyawanProject) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum terdaftar di project manapun',
                'error_type' => 'no_project'
            ], 404);
        }

        $jadwal = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
                                ->whereDate('tanggal', $today)
                                ->first();

        if (!$jadwal) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada jadwal untuk hari ini',
                'error_type' => 'no_schedule'
            ], 404);
        }

        $project = $karyawanProject->project;
        $isLibur = strtoupper($jadwal->shift_code) === 'L';

        // ✅ CRITICAL: Jika hari libur, izinkan presensi dengan logic khusus
        if ($isLibur) {
            return $this->handlePresensiHariLibur($jadwal, $karyawan, $project, $now);
        }

        // Logic normal untuk hari kerja
        $shift = ShiftProject::where('project_id', $project->id)
                             ->where('kode', $jadwal->shift_code)
                             ->first();

        if (!$shift) {
            return response()->json([
                'success' => false,
                'message' => 'Data shift tidak ditemukan',
                'error_type' => 'shift_not_found'
            ], 404);
        }

        $isJabatanExcluded = $project->isJabatanExcluded($karyawan->jabatan_id);

        $waktuMulaiShift = Carbon::parse($today->format('Y-m-d') . ' ' . $shift->waktu_mulai);
        $waktuSelesaiShift = Carbon::parse($today->format('Y-m-d') . ' ' . $shift->waktu_selesai);
        
        $waktuToleransi = (int)($project->waktu_toleransi ?? 0);
        $waktuBukaPresensiMasuk = $waktuMulaiShift->copy()->subMinutes($waktuToleransi);
        $waktuTutupPresensiMasuk = $waktuSelesaiShift->copy();

        // Log::info('Cek Presensi Debug', [
        //     'karyawan_id' => $karyawan->id,
        //     'jabatan_id' => $karyawan->jabatan_id,
        //     'jabatan_nama' => $karyawan->jabatan->nama ?? '-',
        //     'is_jabatan_excluded' => $isJabatanExcluded,
        //     'excluded_jabatan_ids' => $project->excluded_jabatan_ids,
        //     'shift_code' => $shift->kode,
        //     'waktu_server' => $now->format('Y-m-d H:i:s'),
        //     'waktu_mulai_shift' => $waktuMulaiShift->format('Y-m-d H:i:s'),
        // ]);

        $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                 ->where('tipe', 'masuk')
                                 ->first();

        $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                  ->where('tipe', 'pulang')
                                  ->first();

        $bisaPresensiMasuk = false;
        $bisaPresensiPulang = false;
        $pesanWaktu = null;
        $errorType = null;

        // Logic presensi MASUK
        if (!$presensiMasuk) {
            // ✅ NEW: Cek apakah shift sudah selesai
            if ($now->greaterThan($waktuTutupPresensiMasuk)) {
                $selisihMenit = (int)$now->diffInMinutes($waktuTutupPresensiMasuk);
                $pesanWaktu = "Shift sudah berakhir " . $this->formatMenit($selisihMenit) . " yang lalu. Anda tidak dapat melakukan presensi masuk.";
                $errorType = 'shift_ended';
            } 
            // Shift belum dimulai
            elseif ($now->lessThan($waktuBukaPresensiMasuk)) {
                $selisihMenit = (int)$now->diffInMinutes($waktuBukaPresensiMasuk);
                $pesanWaktu = "Presensi masuk akan dibuka pada " . 
                              $waktuBukaPresensiMasuk->format('H:i') . 
                              " (" . $this->formatMenit($selisihMenit) . " lagi)";
                $errorType = 'shift_not_started';
            } 
            // Boleh presensi
            else {
                $bisaPresensiMasuk = true;
                $pesanWaktu = "Anda dapat melakukan presensi masuk sekarang";
            }
        } 
        // Logic presensi PULANG
        else {
            if (in_array($presensiMasuk->status, ['alpa', 'izin', 'libur'])) {
                $pesanWaktu = "Anda tidak dapat melakukan presensi pulang (Status: {$presensiMasuk->status})";
                $errorType = 'status_blocked';
            } elseif (!$presensiPulang) {
                // Cek apakah shift sudah dimulai
                if ($now->lessThan($waktuMulaiShift)) {
                    $selisihMenit = (int)$now->diffInMinutes($waktuMulaiShift);
                    $bisaPresensiPulang = false;
                    $pesanWaktu = "Shift belum dimulai. Presensi pulang dapat dilakukan mulai " . 
                                  $waktuMulaiShift->format('H:i') . 
                                  " (" . $this->formatMenit($selisihMenit) . " lagi)";
                    $errorType = 'shift_not_started';
                } else {
                    $bisaPresensiPulang = true;
                    $pesanWaktu = "Anda dapat melakukan presensi pulang sekarang";
                }
            } else {
                $pesanWaktu = "Anda sudah melakukan presensi masuk dan pulang hari ini";
                $errorType = 'already_completed';
            }
        }

        $lokasiData = null;
        if ($project->lokasi) {
            $lokasi = is_string($project->lokasi) ? json_decode($project->lokasi, true) : $project->lokasi;
            $lokasiData = [
                'nama' => $lokasi['nama'] ?? '',
                'latitude' => (float)($lokasi['latitude'] ?? 0),
                'longitude' => (float)($lokasi['longitude'] ?? 0),
            ];
        }

        $enabledCategories = $project->getEnabledKategoriIzin();
        $enabledSubCategories = $project->getEnabledSubKategoriIzin();

        return response()->json([
            'success' => true,
            'data' => [
                'jadwal_id' => $jadwal->id,
                'tanggal' => $today->format('Y-m-d'),
                'shift' => [
                    'kode' => $shift->kode,
                    'waktu_mulai' => $shift->waktu_mulai,
                    'waktu_selesai' => $shift->waktu_selesai
                ],
                'project' => [
                    'id' => $project->id,
                    'nama' => $project->nama,
                    'bagian' => $project->bagian,
                    'lokasi' => $lokasiData,
                    'radius' => (int)$project->radius,
                    'waktu_toleransi' => $waktuToleransi
                ],
                'karyawan' => [
                    'jabatan_id' => $karyawan->jabatan_id,
                    'jabatan_nama' => $karyawan->jabatan->nama ?? '-',
                    'is_jabatan_excluded' => $isJabatanExcluded
                ],
                'waktu_info' => [
                    'waktu_sekarang' => $now->format('H:i:s'),
                    'waktu_buka_masuk' => $waktuBukaPresensiMasuk->format('H:i:s'),
                    'waktu_mulai_shift' => $waktuMulaiShift->format('H:i:s'),
                    'waktu_selesai_shift' => $waktuSelesaiShift->format('H:i:s'), // ✅ NEW
                    'waktu_sekarang_full' => $now->format('Y-m-d H:i:s'),
                    'pesan' => $pesanWaktu,
                    'error_type' => $errorType // ✅ NEW
                ],
                'bisa_presensi_masuk' => $bisaPresensiMasuk,
                'bisa_presensi_pulang' => $bisaPresensiPulang,
                'sudah_presensi_masuk' => !is_null($presensiMasuk),
                'sudah_presensi_pulang' => !is_null($presensiPulang),
                'enabled_izin_categories' => $enabledCategories,
                'enabled_sub_kategori_izin' => $enabledSubCategories
            ]
        ]);

    } catch (\Exception $e) {
        // Log::error('Cek presensi error: ' . $e->getMessage());
        // Log::error($e->getTraceAsString());
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengecek presensi: ' . $e->getMessage(),
            'error_type' => 'server_error'
        ], 500);
    }
}

    private function handlePresensiHariLibur($jadwal, $karyawan, $project, $now)
{
    $today = Carbon::today();
    
    $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                             ->where('tipe', 'masuk')
                             ->first();

    $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                              ->where('tipe', 'pulang')
                              ->first();

    $bisaPresensiMasuk = false;
    $bisaPresensiPulang = false;
    $pesanWaktu = null;

    // Logic presensi MASUK di hari libur
    if (!$presensiMasuk || $presensiMasuk->status === 'libur') {
        $bisaPresensiMasuk = true;
        $pesanWaktu = "Hari libur - Anda dapat melakukan presensi masuk kapan saja";
    } 
    // Logic presensi PULANG di hari libur
    else {
        $tanggalMasuk = Carbon::parse($presensiMasuk->tanggal)->format('Y-m-d');
        $waktuMasuk = Carbon::parse($tanggalMasuk . ' ' . $presensiMasuk->waktu);
        $batasTidakPresensiPulang = $waktuMasuk->copy()->addHours(10);

        // ✅ CRITICAL FIX: Cek apakah waktu pulang sudah terisi atau masih null
        if (!$presensiPulang || $presensiPulang->status === 'libur' || $presensiPulang->waktu === null) {
            $bisaPresensiPulang = true;
            
            $sisaJam = $now->diffInHours($batasTidakPresensiPulang, false);
            if ($sisaJam > 0) {
                $pesanWaktu = "Anda dapat melakukan presensi pulang. Jika tidak presensi dalam {$sisaJam} jam, akan dianggap tidak presensi pulang.";
            } else {
                $pesanWaktu = "Anda dapat melakukan presensi pulang sekarang";
            }
        } else {
            $pesanWaktu = "Anda sudah melakukan presensi masuk dan pulang di hari libur ini. Jangan lupa ajukan lembur dengan upload SKL.";
        }
    }

    $lokasiData = null;
    if ($project->lokasi) {
        $lokasi = is_string($project->lokasi) ? json_decode($project->lokasi, true) : $project->lokasi;
        $lokasiData = [
            'nama' => $lokasi['nama'] ?? '',
            'latitude' => (float)($lokasi['latitude'] ?? 0),
            'longitude' => (float)($lokasi['longitude'] ?? 0),
        ];
    }

    $isJabatanExcluded = $project->isJabatanExcluded($karyawan->jabatan_id);

    return response()->json([
        'success' => true,
        'data' => [
            'jadwal_id' => $jadwal->id,
            'tanggal' => $today->format('Y-m-d'),
            'is_hari_libur' => true,
            'shift' => [
                'kode' => 'L',
                'waktu_mulai' => null,
                'waktu_selesai' => null
            ],
            'project' => [
                'id' => $project->id,
                'nama' => $project->nama,
                'bagian' => $project->bagian,
                'lokasi' => $lokasiData,
                'radius' => (int)$project->radius,
                'waktu_toleransi' => 0
            ],
            'karyawan' => [
                'jabatan_id' => $karyawan->jabatan_id,
                'jabatan_nama' => $karyawan->jabatan->nama ?? '-',
                'is_jabatan_excluded' => $isJabatanExcluded
            ],
            'waktu_info' => [
                'waktu_sekarang' => $now->format('H:i:s'),
                'waktu_sekarang_full' => $now->format('Y-m-d H:i:s'),
                'pesan' => $pesanWaktu
            ],
            'bisa_presensi_masuk' => $bisaPresensiMasuk,
            'bisa_presensi_pulang' => $bisaPresensiPulang,
            // ✅ CRITICAL FIX: Cek juga apakah waktu sudah terisi
            'sudah_presensi_masuk' => ($presensiMasuk && $presensiMasuk->status !== 'libur' && $presensiMasuk->waktu !== null),
            'sudah_presensi_pulang' => ($presensiPulang && $presensiPulang->status !== 'libur' && $presensiPulang->waktu !== null),
            'peringatan' => 'Jika Anda presensi di hari libur, jangan lupa mengajukan lembur dengan upload SKL'
        ]
    ]);
}

    /**
     * Validasi lokasi presensi - skip untuk jabatan yang dikecualikan
     */
    /**
 * Validasi lokasi presensi - skip untuk jabatan yang dikecualikan
 */
public function validasiLokasi(Request $request)
{
    $request->validate([
        'latitude' => 'required|numeric|between:-90,90',
        'longitude' => 'required|numeric|between:-180,180'
    ]);

    try {
        $karyawan = $request->user();
        $karyawanProject = $karyawan->activeProject;
        
        if (!$karyawanProject) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum terdaftar di project manapun'
            ], 404);
        }

        $project = $karyawanProject->project;
        
        // ✅ DEBUG: Check raw database values BEFORE accessor
        // Log::info('🗄️ Project RAW Database Values', [
        //     'id' => $project->id,
        //     'nama' => $project->nama,
        //     'lokasi_nama_column' => $project->getAttributes()['lokasi_nama'] ?? 'NULL',
        //     'lokasi_latitude_column' => $project->getAttributes()['lokasi_latitude'] ?? 'NULL',
        //     'lokasi_longitude_column' => $project->getAttributes()['lokasi_longitude'] ?? 'NULL',
        //     'radius_column' => $project->getAttributes()['radius'] ?? 'NULL',
        //     'all_attributes' => $project->getAttributes(),
        // ]);
        
        // ✅ CRITICAL: Check if jabatan excluded
        $isJabatanExcluded = $project->isJabatanExcluded($karyawan->jabatan_id);
        
        // ✅ ENHANCED DEBUG LOGS
        // Log::info('🔍 Validasi Lokasi - FULL DEBUG', [
        //     'karyawan_id' => $karyawan->id,
        //     'karyawan_nik' => $karyawan->nik,
        //     'karyawan_nama' => $karyawan->nama,
        //     'jabatan_id' => $karyawan->jabatan_id,
        //     'jabatan_nama' => $karyawan->jabatan->nama ?? '-',
        //     'project_id' => $project->id,
        //     'project_nama' => $project->nama,
        //     'is_jabatan_excluded' => $isJabatanExcluded,
        //     'excluded_jabatan_ids_raw' => $project->excluded_jabatan_ids,
        //     'excluded_jabatan_ids_type' => gettype($project->excluded_jabatan_ids),
        //     'input_latitude' => $request->latitude,
        //     'input_longitude' => $request->longitude,
        //     'input_latitude_type' => gettype($request->latitude),
        //     'input_longitude_type' => gettype($request->longitude),
        // ]);
        
        // ✅ Parse lokasi JSON dari database
        $projectLocation = is_string($project->lokasi) 
            ? json_decode($project->lokasi, true) 
            : $project->lokasi;
        
        // ✅ CRITICAL: Convert to float explicitly
        $projectLat = (float)($projectLocation['latitude'] ?? 0);
        $projectLon = (float)($projectLocation['longitude'] ?? 0);
        
        // ✅ ENHANCED DEBUG: Log project location FROM DATABASE
        // Log::info('📍 Project Location FROM DATABASE', [
        //     'project_lokasi_raw' => $project->lokasi,
        //     'project_lokasi_type' => gettype($project->lokasi),
        //     'project_lokasi_decoded' => $projectLocation,
        //     'project_lat_extracted' => $projectLocation['latitude'] ?? 'NULL',
        //     'project_lon_extracted' => $projectLocation['longitude'] ?? 'NULL',
        //     'project_lat_converted' => $projectLat,
        //     'project_lon_converted' => $projectLon,
        //     'project_lat_type' => gettype($projectLat),
        //     'project_lon_type' => gettype($projectLon),
        //     'project_radius' => $project->radius,
        // ]);
        
        // ✅ ENHANCED DEBUG: Log calculation inputs
        // Log::info('🧮 Distance Calculation Inputs', [
        //     'user_lat' => $request->latitude,
        //     'user_lon' => $request->longitude,
        //     'project_lat' => $projectLat,
        //     'project_lon' => $projectLon,
        //     'lat_diff' => abs($request->latitude - $projectLat),
        //     'lon_diff' => abs($request->longitude - $projectLon),
        // ]);
        
        // ✅ Calculate distance
        $jarak = $this->hitungJarak(
            $request->latitude,
            $request->longitude,
            $projectLat,
            $projectLon
        );

        // ✅ CRITICAL: If jabatan excluded, ALWAYS return dalam_radius = true
        $dalamRadius = $isJabatanExcluded ? true : ($jarak <= $project->radius);

        $response = [
            'success' => true,
            'data' => [
                'dalam_radius' => $dalamRadius,
                'jarak' => round($jarak, 2),
                'radius' => (int)$project->radius,
                'is_jabatan_excluded' => $isJabatanExcluded,
                'lokasi_project' => [
                    'nama' => $projectLocation['nama'] ?? '',
                    'latitude' => $projectLat,
                    'longitude' => $projectLon
                ]
            ]
        ];

        if ($isJabatanExcluded) {
            $response['data']['keterangan'] = 'Jabatan Anda dikecualikan dari pengecekan radius';
        }

        // Log::info('✅ Validasi Lokasi Result', [
        //     'dalam_radius' => $dalamRadius,
        //     'jarak_meters' => round($jarak, 2),
        //     'radius_meters' => $project->radius,
        //     'is_jabatan_excluded' => $isJabatanExcluded,
        //     'calculation_correct' => ($jarak < 100000), // Flag jika jarak > 100km (kemungkinan error)
        // ]);

        return response()->json($response);

    } catch (\Exception $e) {
        // Log::error('❌ Validasi lokasi error: ' . $e->getMessage());
        // Log::error($e->getTraceAsString());
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal memvalidasi lokasi: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * ✅ HAVERSINE FORMULA - untuk menghitung jarak antara 2 koordinat GPS
 */
private function hitungJarak($lat1, $lon1, $lat2, $lon2)
{
    $earthRadius = 6371000; // meter

    // ✅ Convert to float explicitly
    $lat1 = (float)$lat1;
    $lon1 = (float)$lon1;
    $lat2 = (float)$lat2;
    $lon2 = (float)$lon2;

    // Log::info('🔢 hitungJarak - Input Values', [
    //     'lat1' => $lat1,
    //     'lon1' => $lon1,
    //     'lat2' => $lat2,
    //     'lon2' => $lon2,
    //     'lat1_type' => gettype($lat1),
    //     'lon1_type' => gettype($lon1),
    //     'lat2_type' => gettype($lat2),
    //     'lon2_type' => gettype($lon2),
    // ]);

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    // Log::info('🔢 hitungJarak - Delta Values', [
    //     'dLat_deg' => ($lat2 - $lat1),
    //     'dLon_deg' => ($lon2 - $lon1),
    //     'dLat_rad' => $dLat,
    //     'dLon_rad' => $dLon,
    // ]);

    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    $distance = $earthRadius * $c;

    // Log::info('🔢 hitungJarak - Calculation Steps', [
    //     'a' => $a,
    //     'c' => $c,
    //     'distance_meters' => $distance,
    // ]);

    return $distance;
}

    private function submitPresensiHariLibur($request, $jadwal, $karyawan, $project)
{
    // Cek presensi existing
    $existingMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                             ->where('tipe', 'masuk')
                             ->first();

    $existingPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                              ->where('tipe', 'pulang')
                              ->first();

    // ✅ VALIDASI: Cek apakah sudah presensi (status bukan libur)
    if ($request->tipe === 'masuk' && 
        $existingMasuk && 
        $existingMasuk->status !== 'libur' && 
        $existingMasuk->waktu !== null) {
        return response()->json([
            'success' => false,
            'message' => 'Anda sudah melakukan presensi masuk di hari libur ini'
        ], 400);
    }

    if ($request->tipe === 'pulang' && 
        $existingPulang && 
        $existingPulang->status !== 'libur' && 
        $existingPulang->waktu !== null) {
        return response()->json([
            'success' => false,
            'message' => 'Anda sudah melakukan presensi pulang di hari libur ini'
        ], 400);
    }

    // ✅ VALIDASI: Untuk presensi pulang, harus sudah presensi masuk dulu
    if ($request->tipe === 'pulang') {
        if (!$existingMasuk || 
            $existingMasuk->status === 'libur' || 
            $existingMasuk->waktu === null) {
            return response()->json([
                'success' => false,
                'message' => 'Anda harus presensi masuk terlebih dahulu sebelum presensi pulang'
            ], 400);
        }
    }

    // Validasi radius (kecuali jabatan excluded)
    $isJabatanExcluded = $project->isJabatanExcluded($karyawan->jabatan_id);
    
    $projectLocation = is_string($project->lokasi) 
        ? json_decode($project->lokasi, true) 
        : $project->lokasi;
    
    $projectLat = (float)($projectLocation['latitude'] ?? 0);
    $projectLon = (float)($projectLocation['longitude'] ?? 0);
    
    // ✅ CRITICAL: Hitung jarak dari lokasi yang dikirim request
    $jarak = $this->hitungJarak(
        $request->latitude,
        $request->longitude,
        $projectLat,
        $projectLon
    );

    // ✅ Validasi radius (kecuali jabatan excluded)
    if (!$isJabatanExcluded && $jarak > $project->radius) {
        // Log::warning('Presensi hari libur ditolak - di luar radius', [
        //     'karyawan_id' => $karyawan->id,
        //     'jarak' => $jarak,
        //     'radius' => $project->radius,
        //     'latitude' => $request->latitude,
        //     'longitude' => $request->longitude,
        // ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Anda berada di luar radius lokasi presensi. Jarak Anda: ' . round($jarak, 2) . ' meter',
            'data' => [
                'jarak' => round($jarak, 2),
                'radius' => (int)$project->radius
            ]
        ], 400);
    }

    // ✅ Upload foto
    $fotoPath = $this->uploadDanKompresFoto($request->file('foto'), $karyawan->id);

    // ✅ Waktu dan tanggal saat ini
    $waktuSekarang = Carbon::now();
    $tanggal = $jadwal->tanggal instanceof Carbon 
        ? $jadwal->tanggal 
        : Carbon::parse($jadwal->tanggal);

    // ✅ CRITICAL: Status presensi di hari libur
    $status = 'hadir';
    $keterangan = null;

    if ($request->tipe === 'masuk') {
        // Presensi masuk di hari libur = HADIR (tidak ada terlambat)
        $status = 'hadir';
        $keterangan = 'Presensi masuk di hari libur';
        
        if ($isJabatanExcluded) {
            $keterangan .= ' (Jabatan dikecualikan dari radius)';
        }
    } else {
        // Presensi pulang di hari libur = LEMBUR PENDING
        $status = 'lembur_pending';
        $keterangan = 'Presensi pulang di hari libur - menunggu konfirmasi lembur';
        
        if ($isJabatanExcluded) {
            $keterangan .= ' (Jabatan dikecualikan dari radius)';
        }
    }

    // ✅ CRITICAL: UPDATE atau CREATE presensi
    if ($request->tipe === 'masuk' && $existingMasuk) {
        // Update existing presensi masuk (yang statusnya libur)
        $existingMasuk->update([
            'status' => $status,
            'waktu' => $waktuSekarang->format('H:i:s'),
            'latitude' => (float)$request->latitude,
            'longitude' => (float)$request->longitude,
            'foto' => $fotoPath,
            'keterangan' => $keterangan
        ]);
        
        $presensi = $existingMasuk->fresh();
        
        // Log::info('✅ Presensi masuk hari libur updated', [
        //     'presensi_id' => $presensi->id,
        //     'latitude' => $presensi->latitude,
        //     'longitude' => $presensi->longitude,
        //     'waktu' => $presensi->waktu,
        // ]);
        
    } elseif ($request->tipe === 'pulang' && $existingPulang) {
        // Update existing presensi pulang (yang statusnya libur)
        $existingPulang->update([
            'status' => $status,
            'waktu' => $waktuSekarang->format('H:i:s'),
            'latitude' => (float)$request->latitude,
            'longitude' => (float)$request->longitude,
            'foto' => $fotoPath,
            'keterangan' => $keterangan
        ]);
        
        $presensi = $existingPulang->fresh();
        
        // Log::info('✅ Presensi pulang hari libur updated', [
        //     'presensi_id' => $presensi->id,
        //     'latitude' => $presensi->latitude,
        //     'longitude' => $presensi->longitude,
        //     'waktu' => $presensi->waktu,
        // ]);
        
    } else {
        // Create new presensi (fallback - seharusnya tidak terjadi)
        $presensi = Presensi::create([
            'jadwal_karyawan_id' => $jadwal->id,
            'tanggal' => $tanggal->format('Y-m-d'),
            'tipe' => $request->tipe,
            'status' => $status,
            'waktu' => $waktuSekarang->format('H:i:s'),
            'latitude' => (float)$request->latitude,
            'longitude' => (float)$request->longitude,
            'foto' => $fotoPath,
            'keterangan' => $keterangan
        ]);
        
        // Log::info('✅ Presensi hari libur created (new)', [
        //     'presensi_id' => $presensi->id,
        //     'latitude' => $presensi->latitude,
        //     'longitude' => $presensi->longitude,
        //     'waktu' => $presensi->waktu,
        // ]);
    }

    DB::commit();

    // ✅ Refresh data untuk memastikan semua field tersimpan
    $presensi = Presensi::find($presensi->id);

    // Log::info('✅ Presensi hari libur berhasil disimpan', [
    //     'presensi_id' => $presensi->id,
    //     'karyawan_id' => $karyawan->id,
    //     'tipe' => $presensi->tipe,
    //     'status' => $presensi->status,
    //     'waktu' => $presensi->waktu,
    //     'latitude' => $presensi->latitude,
    //     'longitude' => $presensi->longitude,
    //     'foto' => $presensi->foto ? 'YES' : 'NO',
    //     'is_jabatan_excluded' => $isJabatanExcluded,
    //     'jarak' => round($jarak, 2),
    // ]);

    return response()->json([
        'success' => true,
        'message' => 'Presensi ' . $request->tipe . ' di hari libur berhasil dicatat',
        'data' => [
            'id' => $presensi->id,
            'tipe' => $presensi->tipe,
            'status' => $presensi->status,
            'status_text' => $this->getStatusText($presensi->status),
            'waktu' => $presensi->waktu,
            'tanggal' => $presensi->tanggal,
            'latitude' => $presensi->latitude,
            'longitude' => $presensi->longitude,
            'keterangan' => $presensi->keterangan,
            'foto_url' => $presensi->foto ? Storage::url($presensi->foto) : null,
            'is_jabatan_excluded' => $isJabatanExcluded,
            'jarak' => round($jarak, 2),
            'jadwal_id' => $jadwal->id,
            'peringatan' => $request->tipe === 'pulang' 
                ? 'Jangan lupa mengajukan lembur dengan upload SKL untuk mendapatkan konfirmasi' 
                : 'Jika Anda akan pulang, jangan lupa presensi pulang dan ajukan lembur'
        ]
    ], 201);
}

    /**
     * Submit presensi (masuk atau pulang) - skip validasi radius untuk jabatan excluded
     */
    public function submitPresensi(Request $request)
{
    $request->validate([
        'jadwal_id' => 'required|exists:jadwal_karyawans,id',
        'tipe' => 'required|in:masuk,pulang',
        'latitude' => 'required|numeric|between:-90,90',
        'longitude' => 'required|numeric|between:-180,180',
        'foto' => 'required|image|mimes:jpeg,jpg,png|max:10240'
    ]);

    DB::beginTransaction();
    try {
        $karyawan = $request->user();
        $jadwal = JadwalKaryawan::findOrFail($request->jadwal_id);

        // Validasi karyawan memiliki jadwal
        $jadwalKaryawan = JadwalKaryawan::whereHas('karyawanProject', function($q) use ($karyawan) {
                $q->where('karyawan_id', $karyawan->id);
            })
            ->where('tanggal', $jadwal->tanggal)
            ->where('shift_code', $jadwal->shift_code)
            ->first();

        if (!$jadwalKaryawan) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki jadwal untuk shift ini pada tanggal tersebut'
            ], 403);
        }

        $project = $jadwalKaryawan->karyawanProject->project;
        $isLibur = strtoupper($jadwalKaryawan->shift_code) === 'L';

        // ✅ CRITICAL: Handle presensi di hari libur
        if ($isLibur) {
            return $this->submitPresensiHariLibur($request, $jadwalKaryawan, $karyawan, $project);
        }

        // Cek presensi existing
        $existing = Presensi::whereHas('jadwalKaryawan', function($q) use ($karyawan, $jadwal) {
                $q->whereHas('karyawanProject', function($q2) use ($karyawan) {
                    $q2->where('karyawan_id', $karyawan->id);
                })
                ->where('tanggal', $jadwal->tanggal)
                ->where('shift_code', $jadwal->shift_code);
            })
            ->where('tipe', $request->tipe)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah melakukan presensi ' . $request->tipe . ' untuk shift ini hari ini'
            ], 400);
        }

        $project = $jadwalKaryawan->karyawanProject->project;
        
        // ✅ CRITICAL: Check if jabatan excluded
        $isJabatanExcluded = $project->isJabatanExcluded($karyawan->jabatan_id);
        
        // Log::info('Submit Presensi Debug', [
        //     'karyawan_id' => $karyawan->id,
        //     'jabatan_id' => $karyawan->jabatan_id,
        //     'is_jabatan_excluded' => $isJabatanExcluded,
        //     'tipe' => $request->tipe,
        // ]);
        
        // Parse lokasi JSON
        $projectLocation = is_string($project->lokasi) 
            ? json_decode($project->lokasi, true) 
            : $project->lokasi;
        
        $projectLat = (float)($projectLocation['latitude'] ?? 0);
        $projectLon = (float)($projectLocation['longitude'] ?? 0);
        
        $jarak = $this->hitungJarak(
            $request->latitude,
            $request->longitude,
            $projectLat,
            $projectLon
        );

        // ✅ CRITICAL: Skip radius validation if jabatan excluded
        if (!$isJabatanExcluded && $jarak > $project->radius) {
            // Log::warning('Presensi ditolak - di luar radius', [
            //     'jarak' => $jarak,
            //     'radius' => $project->radius,
            //     'is_jabatan_excluded' => $isJabatanExcluded
            // ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Anda berada di luar radius lokasi presensi. Jarak Anda: ' . round($jarak, 2) . ' meter',
                'data' => [
                    'jarak' => round($jarak, 2),
                    'radius' => (int)$project->radius,
                    'is_jabatan_excluded' => $isJabatanExcluded
                ]
            ], 400);
        }

        // Upload foto
        $fotoPath = $this->uploadDanKompresFoto($request->file('foto'), $karyawan->id);

        $shift = ShiftProject::where('project_id', $project->id)
                             ->where('kode', $jadwalKaryawan->shift_code)
                             ->first();

        $waktuSekarang = Carbon::now();
        
        $tanggal = $jadwalKaryawan->tanggal instanceof Carbon 
            ? $jadwalKaryawan->tanggal 
            : Carbon::parse($jadwalKaryawan->tanggal);

        $status = 'hadir';
        $keterangan = null;

        // Logika presensi masuk
        if ($request->tipe === 'masuk') {
            $waktuMulaiShift = Carbon::parse($tanggal->format('Y-m-d') . ' ' . $shift->waktu_mulai);
            $waktuToleransi = $project->waktu_toleransi ?? 0;
            
            $waktuBukaPresensi = $waktuMulaiShift->copy()->subMinutes($waktuToleransi);
            $batasTepat = $waktuMulaiShift->copy()->addMinutes(30);

            if ($waktuSekarang->lessThanOrEqualTo($batasTepat)) {
                $status = 'hadir';
                $keterangan = 'Presensi tepat waktu';
                
                if ($isJabatanExcluded) {
                    $keterangan .= ' (Jabatan dikecualikan dari radius)';
                }
            } else {
                $menitTerlambat = (int) $waktuSekarang->diffInMinutes($waktuMulaiShift);
                $status = 'terlambat';
                $keterangan = 'Terlambat ' . $this->formatMenit($menitTerlambat);
                
                if ($isJabatanExcluded) {
                    $keterangan .= ' (Jabatan dikecualikan dari radius)';
                }
            }
        } else {
            // Logika presensi pulang
            $waktuSelesaiShift = Carbon::parse($tanggal->format('Y-m-d') . ' ' . $shift->waktu_selesai);
            $batasToleransiPulangCepat = $waktuSelesaiShift->copy()->subMinutes(45);
            $batasTepat = $waktuSelesaiShift->copy()->addMinutes(15);

            if ($waktuSekarang->lessThan($batasToleransiPulangCepat)) {
                $menitCepat = (int) $waktuSelesaiShift->diffInMinutes($waktuSekarang);
                $status = 'pulang_cepat';
                $keterangan = 'Pulang cepat ' . $this->formatMenit($menitCepat);
                
                if ($isJabatanExcluded) {
                    $keterangan .= ' (Jabatan dikecualikan dari radius)';
                }
            } else {
                $status = 'hadir';
                $keterangan = 'Pulang tepat waktu';
                
                if ($isJabatanExcluded) {
                    $keterangan .= ' (Jabatan dikecualikan dari radius)';
                }
            }
        }

        // Simpan presensi
        $presensi = Presensi::create([
            'jadwal_karyawan_id' => $jadwalKaryawan->id,
            'tanggal' => $tanggal->format('Y-m-d'),
            'tipe' => $request->tipe,
            'status' => $status,
            'waktu' => $waktuSekarang->format('H:i:s'),
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'foto' => $fotoPath,
            'keterangan' => $keterangan
        ]);

        DB::commit();

        // Log::info('✅ Presensi berhasil disimpan', [
        //     'presensi_id' => $presensi->id,
        //     'karyawan_id' => $karyawan->id,
        //     'tipe' => $request->tipe,
        //     'status' => $status,
        //     'is_jabatan_excluded' => $isJabatanExcluded,
        //     'jarak' => round($jarak, 2),
        // ]);

        return response()->json([
            'success' => true,
            'message' => 'Presensi ' . $request->tipe . ' berhasil dicatat',
            'data' => [
                'id' => $presensi->id,
                'tipe' => $presensi->tipe,
                'status' => $presensi->status,
                'status_text' => $this->getStatusText($presensi->status),
                'waktu' => $presensi->waktu,
                'keterangan' => $presensi->keterangan,
                'foto_url' => $presensi->foto ? Storage::url($presensi->foto) : null,
                'is_jabatan_excluded' => $isJabatanExcluded,
                'jarak' => round($jarak, 2),
                'jadwal_id' => $jadwalKaryawan->id
            ]
        ], 201);

    } catch (\Exception $e) {
        DB::rollback();
        
        if (isset($fotoPath) && Storage::exists('public/' . $fotoPath)) {
            Storage::delete('public/' . $fotoPath);
        }

        // Log::error('Submit presensi error: ' . $e->getMessage(), [
        //     'karyawan_id' => $karyawan->id ?? null,
        //     'jadwal_id' => $request->jadwal_id ?? null,
        //     'trace' => $e->getTraceAsString()
        // ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal menyimpan presensi: ' . $e->getMessage()
        ], 500);
    }
}

    // ========== HELPER METHODS ==========

    // private function hitungJarak($lat1, $lon1, $lat2, $lon2)
    // {
    //     $earthRadius = 6371000; // meter

    //     $dLat = deg2rad($lat2 - $lat1);
    //     $dLon = deg2rad($lon2 - $lon1);

    //     $a = sin($dLat / 2) * sin($dLat / 2) +
    //          cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
    //          sin($dLon / 2) * sin($dLon / 2);

    //     $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    //     return $earthRadius * $c;
    // }

    private function uploadDanKompresFoto($file, $karyawanId)
    {
        try {
            $fileName = 'presensi_' . $karyawanId . '_' . time() . '_' . uniqid() . '.jpg';
            $relativePath = 'presensi/' . date('Y/m');
            $fullDirectory = storage_path('app/public/' . $relativePath);
            
            if (!is_dir($fullDirectory)) {
                mkdir($fullDirectory, 0755, true);
            }

            $fullPath = $fullDirectory . '/' . $fileName;

            $imageInfo = getimagesize($file->getRealPath());
            $mimeType = $imageInfo['mime'];

            switch ($mimeType) {
                case 'image/jpeg':
                case 'image/jpg':
                    $image = imagecreatefromjpeg($file->getRealPath());
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($file->getRealPath());
                    break;
                default:
                    throw new \Exception('Format image tidak didukung');
            }

            if (!$image) {
                throw new \Exception('Gagal membaca image');
            }

            $originalWidth = imagesx($image);
            $originalHeight = imagesy($image);

            if ($originalWidth > 1920) {
                $newWidth = 1920;
                $newHeight = ($originalHeight / $originalWidth) * $newWidth;
                
                $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
                
                imagecopyresampled(
                    $resizedImage, 
                    $image, 
                    0, 0, 0, 0, 
                    $newWidth, $newHeight, 
                    $originalWidth, $originalHeight
                );
                
                imagedestroy($image);
                $image = $resizedImage;
            }

            $saved = imagejpeg($image, $fullPath, 85);
            
            imagedestroy($image);

            if (!$saved || !file_exists($fullPath)) {
                throw new \Exception('Gagal menyimpan image');
            }

            return $relativePath . '/' . $fileName;
            
        } catch (\Exception $e) {
            // Log::error('Upload foto error: ' . $e->getMessage());
            throw new \Exception('Gagal mengupload foto: ' . $e->getMessage());
        }
    }

    private function formatMenit($menit)
    {
        $menit = (int) round($menit);
        
        if ($menit < 60) {
            return $menit . ' menit';
        }
        
        $jam = floor($menit / 60);
        $sisaMenit = $menit % 60;
        
        if ($sisaMenit == 0) {
            return $jam . ' jam';
        }
        
        return $jam . ' jam ' . $sisaMenit . ' menit';
    }

    private function getStatusText($status)
    {
        $statusMap = [
            'hadir' => 'Hadir',
            'terlambat' => 'Terlambat',
            'lembur_pending' => 'Lembur (Pending)',
            'lembur' => 'Lembur',
            'pulang_cepat' => 'Pulang Cepat',
            'tidak_presensi_pulang' => 'Tidak Presensi Pulang',
            'alpa' => 'Alpa',
            'libur' => 'Libur'
        ];

        return $statusMap[$status] ?? $status;
    }

    /**
 * Get history presensi karyawan
 */
    public function getHistory(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        try {
            $karyawan = $request->user();
            $karyawanProject = $karyawan->activeProject;
            
            if (!$karyawanProject) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda belum terdaftar di project manapun'
                ], 404);
            }

            $startDate = $request->start_date;
            $endDate = $request->end_date;

            // Get jadwal dalam periode
            $jadwals = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
                                     ->where('tanggal', '>=', $startDate)
                                     ->where('tanggal', '<=', $endDate)
                                     ->orderBy('tanggal', 'desc')
                                     ->get();

            $project = $karyawanProject->project;
            $result = [];

            foreach ($jadwals as $jadwal) {
                $tanggal = $jadwal->tanggal;
                $shiftCode = $jadwal->shift_code;
                
                // Get presensi
                $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                         ->where('tipe', 'masuk')
                                         ->first();

                $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                          ->where('tipe', 'pulang')
                                          ->first();

                // ✅ CRITICAL: Tentukan status dan clickability
                $status = 'alpa'; // default
                $isClickable = false;
                
                // ✅ CASE 1: Hari libur
                if (strtoupper($shiftCode) === 'L') {
                    // Cek apakah ada presensi aktual di hari libur
                    if ($presensiMasuk && 
                        $presensiMasuk->status !== 'libur' && 
                        $presensiMasuk->waktu !== null) {
                        // Ada presensi aktual di hari libur
                        $status = $presensiMasuk->status;
                        $isClickable = true; // ✅ CLICKABLE - ada detail presensi
                    } else {
                        // Murni libur, tidak ada presensi
                        $status = 'libur';
                        $isClickable = false; // ❌ NOT CLICKABLE - tidak ada detail
                    }
                }
                // ✅ CASE 2: Ada presensi masuk
                elseif ($presensiMasuk) {
                    $status = $presensiMasuk->status;
                    
                    // Clickable jika ada waktu presensi (bukan alpa/izin/libur)
                    if (!in_array($presensiMasuk->status, ['alpa', 'izin', 'libur'])) {
                        $isClickable = true; // ✅ CLICKABLE - ada detail presensi (hadir/terlambat)
                    } else {
                        $isClickable = false; // ❌ NOT CLICKABLE - alpa/izin tidak ada detail
                    }
                }
                // ✅ CASE 3: Tidak ada presensi sama sekali
                else {
                    $status = 'alpa';
                    $isClickable = false; // ❌ NOT CLICKABLE - tidak ada detail
                }

                // Get shift detail
                $shift = ShiftProject::where('project_id', $project->id)
                                     ->where('kode', $shiftCode)
                                     ->first();

                // ✅ Build result item - ALWAYS include in result
                $result[] = [
                    'id' => $jadwal->id,
                    'tanggal' => $tanggal,
                    'hari' => $this->getHari($tanggal),
                    'status' => $status,
                    'waktu_masuk' => ($presensiMasuk && $presensiMasuk->waktu) 
                        ? $presensiMasuk->waktu 
                        : null,
                    'waktu_pulang' => ($presensiPulang && $presensiPulang->waktu) 
                        ? $presensiPulang->waktu 
                        : null,
                    'shift' => $shift ? [
                        'kode' => $shift->kode,
                        'waktu_mulai' => $shift->waktu_mulai,
                        'waktu_selesai' => $shift->waktu_selesai
                    ] : ($shiftCode === 'L' ? [
                        'kode' => 'L',
                        'waktu_mulai' => null,
                        'waktu_selesai' => null
                    ] : null),
                    'karyawan' => [
                        'nama' => $karyawan->nama,
                        'nik' => $karyawan->nik,
                        'jabatan' => [
                            'id' => $karyawan->jabatan->id,
                            'nama' => $karyawan->jabatan->nama
                        ],
                        'divisi' => $karyawan->divisi ? [
                            'id' => (int)$karyawan->divisi->id,
                            'nama' => (string)$karyawan->divisi->nama
                        ] : [
                            'id' => null,
                            'nama' => '-'
                        ]
                    ],
                    'project' => [
                        'nama' => $project->nama,
                        'lokasi' => $this->parseLokasiProject($project)
                    ],
                    'presensi_masuk' => $presensiMasuk ? [
                        'id' => $presensiMasuk->id,
                        'waktu' => $presensiMasuk->waktu,
                        'status' => $presensiMasuk->status,
                        'keterangan' => $presensiMasuk->keterangan,
                        'latitude' => $presensiMasuk->latitude,
                        'longitude' => $presensiMasuk->longitude,
                        'foto_url' => $presensiMasuk->foto 
                            ? (str_starts_with($presensiMasuk->foto, 'http') 
                                ? $presensiMasuk->foto 
                                : url('storage/' . $presensiMasuk->foto))
                            : null
                    ] : null,
                    'presensi_pulang' => $presensiPulang ? [
                        'id' => $presensiPulang->id,
                        'waktu' => $presensiPulang->waktu,
                        'status' => $presensiPulang->status,
                        'keterangan' => $presensiPulang->keterangan,
                        'latitude' => $presensiPulang->latitude,
                        'longitude' => $presensiPulang->longitude,
                        'foto_url' => $presensiPulang->foto 
                            ? (str_starts_with($presensiPulang->foto, 'http') 
                                ? $presensiPulang->foto 
                                : url('storage/' . $presensiPulang->foto))
                            : null
                    ] : null,
                    'is_clickable' => $isClickable // ✅ NEW FIELD
                ];
            }

            // Log::info('✅ History loaded', [
            //     'total_items' => count($result),
            //     'clickable' => collect($result)->where('is_clickable', true)->count(),
            //     'not_clickable' => collect($result)->where('is_clickable', false)->count()
            // ]);

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            // Log::error('Get history error: ' . $e->getMessage());
            // Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat history: ' . $e->getMessage()
            ], 500);
        }
    }

private function getHari($tanggal)
    {
        $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $date = \DateTime::createFromFormat('Y-m-d', $tanggal);
        return $date ? $days[$date->format('w')] : '-';
    }

    private function parseLokasiProject($project)
    {
        if (!$project->lokasi) return null;
        
        $lokasi = is_string($project->lokasi) 
            ? json_decode($project->lokasi, true) 
            : $project->lokasi;
        
        return [
            'nama' => $lokasi['nama'] ?? '',
            'latitude' => (float)($lokasi['latitude'] ?? 0),
            'longitude' => (float)($lokasi['longitude'] ?? 0)
        ];
    }

    /**
     * Get rekap presensi harian by project and date
     */
    public function getRekapHarian(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'tanggal' => 'required|date'
        ]);

        try {
            $projectId = $request->project_id;
            $tanggal = $request->tanggal;

            // Get project info with shifts
            $project = Project::with('shiftProjects')->findOrFail($projectId);

            // ✅ CREATE SHIFT MAP for quick case-insensitive lookup
            $shiftMap = [];
            foreach ($project->shiftProjects as $shift) {
                $shiftMap[strtoupper($shift->kode)] = [
                    'kode' => $shift->kode,
                    'waktu_mulai' => substr($shift->waktu_mulai, 0, 5),
                    'waktu_selesai' => substr($shift->waktu_selesai, 0, 5)
                ];
            }

            // Log::info('🔍 Rekap Harian Shift Map', [
            //     'available_shifts' => array_keys($shiftMap),
            //     'tanggal' => $tanggal
            // ]);

            // Get all jadwal for this project on this date
            $jadwals = JadwalKaryawan::with([
                'karyawanProject.karyawan.divisi',
                'karyawanProject.karyawan.jabatan',
                'karyawanProject.project'
            ])
            ->byProject($projectId)
            ->where('tanggal', $tanggal)
            ->get();

            $result = [];
            $statistik = [
                'total' => 0,
                'masuk' => [
                    'hadir' => 0,
                    'terlambat' => 0,
                    'izin' => 0,
                    'alpa' => 0,
                    'libur' => 0
                ],
                'pulang' => [
                    'hadir' => 0,
                    'lembur' => 0,
                    'lembur_pending' => 0,
                    'tidak_presensi_pulang' => 0,
                    'pulang_cepat' => 0,
                    'izin' => 0,
                    'alpa' => 0,
                    'libur' => 0
                ]
            ];

            foreach ($jadwals as $jadwal) {
                $karyawan = $jadwal->karyawanProject->karyawan;
                $shiftCode = $jadwal->shift_code;

                // ✅ Get shift detail using case-insensitive lookup
                $shiftCodeUpper = strtoupper($shiftCode);
                $shift = null;
                $shiftDisplay = 'Libur';
                
                if ($shiftCodeUpper !== 'L' && isset($shiftMap[$shiftCodeUpper])) {
                    $shift = $shiftMap[$shiftCodeUpper];
                    $shiftDisplay = "{$shift['kode']} ({$shift['waktu_mulai']} - {$shift['waktu_selesai']})";
                } elseif ($shiftCodeUpper !== 'L') {
                    // Log::warning('⚠️ Shift not found in map', [
                    //     'shift_code' => $shiftCode,
                    //     'shift_code_upper' => $shiftCodeUpper,
                    //     'available_shifts' => array_keys($shiftMap)
                    // ]);
                }

                // Get presensi masuk
                $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                         ->where('tipe', 'masuk')
                                         ->first();

                // Get presensi pulang
                $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                          ->where('tipe', 'pulang')
                                          ->first();

                // Build data
                $item = [
                    'id' => $jadwal->id,
                    'nik' => $karyawan->nik,
                    'nama' => $karyawan->nama,
                    'divisi' => $karyawan->divisi ? $karyawan->divisi->nama : '-',
                    'jabatan' => $karyawan->jabatan->nama ?? '-',
                    'shift' => $shiftDisplay, // ✅ Use formatted shift display
                    'shift_code' => $shiftCode,
                    'presensi_masuk' => $presensiMasuk ? [
                        'id' => $presensiMasuk->id,
                        'waktu' => Carbon::parse($presensiMasuk->waktu)->format('H:i'),
                        'status' => $presensiMasuk->status,
                        'keterangan' => $presensiMasuk->keterangan,
                        'latitude' => $presensiMasuk->latitude,
                        'longitude' => $presensiMasuk->longitude,
                        'lokasi_nama' => $this->parseLokasiNama($project),
                        'foto' => $presensiMasuk->foto ? url('storage/' . $presensiMasuk->foto) : null,
                        'google_maps_url' => $presensiMasuk->latitude && $presensiMasuk->longitude 
                            ? "https://www.google.com/maps?q={$presensiMasuk->latitude},{$presensiMasuk->longitude}" 
                            : null
                    ] : null,
                    'presensi_pulang' => $presensiPulang ? [
                        'id' => $presensiPulang->id,
                        'waktu' => Carbon::parse($presensiPulang->waktu)->format('H:i'),
                        'status' => $presensiPulang->status,
                        'keterangan' => $presensiPulang->keterangan,
                        'latitude' => $presensiPulang->latitude,
                        'longitude' => $presensiPulang->longitude,
                        'lokasi_nama' => $this->parseLokasiNama($project),
                        'foto' => $presensiPulang->foto ? url('storage/' . $presensiPulang->foto) : null,
                        'google_maps_url' => $presensiPulang->latitude && $presensiPulang->longitude 
                            ? "https://www.google.com/maps?q={$presensiPulang->latitude},{$presensiPulang->longitude}" 
                            : null
                    ] : null
                ];

                // Statistik masuk
                $statusMasuk = $presensiMasuk ? $presensiMasuk->status : (strtoupper($shiftCode) === 'L' ? 'libur' : 'alpa');
                if (isset($statistik['masuk'][$statusMasuk])) {
                    $statistik['masuk'][$statusMasuk]++;
                }

                // Statistik pulang
                $statusPulang = $presensiPulang ? $presensiPulang->status : (strtoupper($shiftCode) === 'L' ? 'libur' : 'alpa');
                // Handle lembur_pending as separate counter
                if ($statusPulang === 'lembur_pending') {
                    $statistik['pulang']['lembur_pending']++;
                } elseif (isset($statistik['pulang'][$statusPulang])) {
                    $statistik['pulang'][$statusPulang]++;
                }

                $statistik['total']++;
                $result[] = $item;
            }

            return response()->json([
                'success' => true,
                'data' => $result,
                'statistik' => $statistik,
                'project' => [
                    'id' => $project->id,
                    'nama' => $project->nama,
                    'lokasi' => $this->parseLokasiProject($project),
                    'total_karyawan' => $jadwals->count()
                ],
                'tanggal' => $tanggal
            ]);

        } catch (\Exception $e) {
            // Log::error('Get rekap harian error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update status presensi
     */
    public function updateStatus(Request $request, $presensiId)
{
    $request->validate([
        'status' => 'required|in:hadir,terlambat,izin,alpa,libur,lembur,lembur_pending,tidak_presensi_pulang,pulang_cepat',
        'keterangan' => 'nullable|string'
    ]);

    try {
        $presensi = Presensi::findOrFail($presensiId);
        
        $oldStatus = $presensi->status;
        $newStatus = $request->status;
        $keterangan = $request->keterangan ?? $this->generateKeterangan($newStatus, $presensi->tipe);

        $presensi->update([
            'status' => $newStatus,
            'keterangan' => $keterangan
        ]);

        $notificationService = app(\App\Services\NotificationService::class);

        if ($newStatus === 'lembur') {
            // Lembur dikonfirmasi
            $notificationService->notifyKaryawanLemburDikonfirmasi($presensi);
        } elseif ($oldStatus === 'lembur_pending' && $newStatus !== 'lembur') {
            // Lembur ditolak
            $notificationService->notifyKaryawanLemburDitolak($presensi, $keterangan);
        } else {
            // Status presensi normal diupdate
            $notificationService->notifyKaryawanPresensiDiupdate($presensi, $newStatus, $oldStatus, $keterangan);
        }

        return response()->json([
            'success' => true,
            'message' => 'Status presensi berhasil diupdate',
            'data' => $presensi->fresh()
        ]);

    } catch (\Exception $e) {
        // Log::error('Update status error: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengupdate status: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Konfirmasi lembur
     */
    public function konfirmasiLembur($presensiId)
    {
        try {
            $presensi = Presensi::findOrFail($presensiId);
            
            if ($presensi->status !== 'lembur_pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Status presensi bukan lembur pending'
                ], 400);
            }

            $presensi->update([
                'status' => 'lembur',
                'keterangan' => 'Lembur - dikonfirmasi admin'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Lembur berhasil dikonfirmasi',
                'data' => $presensi->fresh()
            ]);

        } catch (\Exception $e) {
            // Log::error('Konfirmasi lembur error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengkonfirmasi lembur: ' . $e->getMessage()
            ], 500);
        }
    }

    // Helper methods
    private function generateKeterangan($status, $tipe)
    {
        $keteranganMap = [
            'hadir' => $tipe === 'masuk' ? 'Presensi tepat waktu' : 'Pulang tepat waktu',
            'terlambat' => 'Terlambat - diubah oleh admin',
            'izin' => 'Izin - diubah oleh admin',
            'alpa' => 'Alpa - diubah oleh admin',
            'libur' => 'Libur - diubah oleh admin',
            'lembur' => 'Lembur - dikonfirmasi admin',
            'lembur_pending' => 'Lembur pending konfirmasi',
            'tidak_presensi_pulang' => 'Tidak presensi pulang - diubah oleh admin',
            'pulang_cepat' => 'Pulang cepat - diubah oleh admin'
        ];

        return $keteranganMap[$status] ?? 'Diubah oleh admin';
    }

    private function parseLokasiNama($project)
    {
        if (!$project->lokasi) return null;
        
        $lokasi = is_string($project->lokasi) 
            ? json_decode($project->lokasi, true) 
            : $project->lokasi;
        
        return $lokasi['nama'] ?? '';
    }

public function getPresensiData(Request $request)
{
    try {
        $karyawan = $request->user();
        $karyawanProject = $karyawan->activeProject;
        
        if (!$karyawanProject) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum terdaftar di project manapun'
            ], 404);
        }

        $today = Carbon::today()->format('Y-m-d');
        
        $project = $karyawanProject->project;
        $tanggalMulaiProject = $project->tanggal_mulai;
        
        if ($tanggalMulaiProject instanceof Carbon) {
            $projectStartDate = $tanggalMulaiProject->format('Y-m-d');
        } elseif ($tanggalMulaiProject instanceof \DateTime) {
            $projectStartDate = $tanggalMulaiProject->format('Y-m-d');
        } else {
            $projectStartDate = Carbon::parse($tanggalMulaiProject)->format('Y-m-d');
        }
        
        // ✅ MONTHLY STATS: From start of current month to today
        $currentMonth = Carbon::now();
        $startOfMonth = $currentMonth->copy()->startOfMonth()->format('Y-m-d');
        $endDate = $today;
        
        // If project started after month start, use project start date
        if ($projectStartDate > $startOfMonth) {
            $startDate = $projectStartDate;
        } else {
            $startDate = $startOfMonth;
        }
        
        // Log::info('📅 Homepage Monthly Stats', [
        //     'current_month' => $currentMonth->format('Y-m'),
        //     'start_of_month' => $startOfMonth,
        //     'project_start' => $projectStartDate,
        //     'stats_start_date' => $startDate,
        //     'stats_end_date' => $endDate,
        // ]);
        
        $statistik = $this->getStatistikMonthly($karyawanProject->id, $startDate, $endDate);

        $jadwalHariIni = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
                                      ->where('tanggal', $today)
                                      ->first();

        $jadwalData = null;
        $presensiData = null;
        $isAlpa = false;

        if ($jadwalHariIni) {
            $shiftCodeUpper = strtoupper($jadwalHariIni->shift_code);
            
            $isLibur = $shiftCodeUpper === 'L';
            
            $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwalHariIni->id)
                                     ->where('tipe', 'masuk')
                                     ->first();

            $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwalHariIni->id)
                                      ->where('tipe', 'pulang')
                                      ->first();

            $sudahPresensiDiLibur = $isLibur && 
                                    $presensiMasuk && 
                                    $presensiMasuk->status !== 'libur';

            if ($isLibur && !$sudahPresensiDiLibur) {
                $jadwalData = [
                    'shift_code' => 'L',
                    'waktu_mulai' => null,
                    'waktu_selesai' => null,
                    'is_libur' => true
                ];
                
                $presensiData = null;
                
            } elseif ($sudahPresensiDiLibur) {
                $jadwalData = [
                    'shift_code' => 'L',
                    'waktu_mulai' => null,
                    'waktu_selesai' => null,
                    'is_libur' => true
                ];
                
                $presensiData = [
                    'waktu_masuk' => ($presensiMasuk && $presensiMasuk->waktu && $presensiMasuk->waktu !== null) 
                        ? Carbon::parse($presensiMasuk->waktu)->format('H:i') 
                        : null,
                    'waktu_pulang' => ($presensiPulang && $presensiPulang->waktu && $presensiPulang->waktu !== null) 
                        ? Carbon::parse($presensiPulang->waktu)->format('H:i') 
                        : null,
                    'status_masuk' => $presensiMasuk ? $presensiMasuk->status : null,
                    'status_pulang' => $presensiPulang ? $presensiPulang->status : null,
                    'is_alpa' => false,
                ];
            } else {
                $project->load('shiftProjects');
    
                $shift = $project->shiftProjects->first(function($s) use ($shiftCodeUpper) {
                    return strtoupper($s->kode) === $shiftCodeUpper;
                });

                $jadwalData = [
                    'shift_code' => $jadwalHariIni->shift_code,
                    'waktu_mulai' => $shift ? substr($shift->waktu_mulai, 0, 5) : null,
                    'waktu_selesai' => $shift ? substr($shift->waktu_selesai, 0, 5) : null,
                    'is_libur' => false
                ];

                if ($presensiMasuk && $presensiMasuk->status === 'alpa') {
                    $isAlpa = true;
                }

                $presensiData = [
                    'waktu_masuk' => ($presensiMasuk && $presensiMasuk->waktu && $presensiMasuk->waktu !== null) 
                        ? Carbon::parse($presensiMasuk->waktu)->format('H:i') 
                        : null,
                    'waktu_pulang' => ($presensiPulang && $presensiPulang->waktu && $presensiPulang->waktu !== null) 
                        ? Carbon::parse($presensiPulang->waktu)->format('H:i') 
                        : null,
                    'status_masuk' => $presensiMasuk ? $presensiMasuk->status : null,
                    'status_pulang' => $presensiPulang ? $presensiPulang->status : null,
                    'is_alpa' => $isAlpa,
                ];
            }
        }

        $enabledCategories = $project->getEnabledKategoriIzin();
        $enabledSubCategories = $project->getEnabledSubKategoriIzin();

        return response()->json([
            'success' => true,
            'data' => [
                'statistik' => $statistik,
                'jadwal_hari_ini' => $jadwalData,
                'presensi_hari_ini' => $presensiData,
                'project_info' => [
                    'id' => $project->id,
                    'nama' => $project->nama,
                    'tanggal_mulai' => $projectStartDate
                ],
                'month_info' => [
                    'bulan' => $currentMonth->format('Y-m'),
                    'bulan_display' => $this->getIndonesianMonth($currentMonth->month) . ' ' . $currentMonth->year,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'enabled_izin_categories' => $enabledCategories,
                'enabled_sub_kategori_izin' => $enabledSubCategories
            ]
        ]);

    } catch (\Exception $e) {
        // Log::error('Get presensi data error: ' . $e->getMessage());
        // Log::error($e->getTraceAsString());
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal memuat data: ' . $e->getMessage()
        ], 500);
    }
}

private function getStatistikMonthly($karyawanProjectId, $startDate, $endDate)
{
    $jadwalIds = JadwalKaryawan::where('karyawan_project_id', $karyawanProjectId)
                               ->where('tanggal', '>=', $startDate)
                               ->where('tanggal', '<=', $endDate)
                               ->pluck('id');

    if ($jadwalIds->isEmpty()) {
        return [
            'hadir' => 0,
            'izin' => 0,
            'alpa' => 0
        ];
    }

    $alpa = Presensi::whereIn('jadwal_karyawan_id', $jadwalIds)
                    ->where('tipe', 'masuk')
                    ->where('status', 'alpa')
                    ->count();

    $izin = Presensi::whereIn('jadwal_karyawan_id', $jadwalIds)
                    ->where('tipe', 'masuk')
                    ->where('status', 'izin')
                    ->count();

    $hadir = Presensi::whereIn('jadwal_karyawan_id', $jadwalIds)
                     ->where('tipe', 'masuk')
                     ->whereNotIn('status', ['alpa', 'izin', 'libur'])
                     ->count();

    // Log::info('✅ Monthly Statistik', [
    //     'karyawan_project_id' => $karyawanProjectId,
    //     'start_date' => $startDate,
    //     'end_date' => $endDate,
    //     'hadir' => $hadir,
    //     'izin' => $izin,
    //     'alpa' => $alpa
    // ]);

    return [
        'hadir' => $hadir,
        'izin' => $izin,
        'alpa' => $alpa
    ];
}

/**
 * FIXED: Hitung statistik total dengan query yang benar
 * Mengikuti logika SQL yang diberikan user
 */
private function getStatistikTotal($karyawanProjectId, $startDate, $endDate)
{
    // Get all jadwal IDs untuk periode ini
    $jadwalIds = JadwalKaryawan::where('karyawan_project_id', $karyawanProjectId)
                               ->where('tanggal', '>=', $startDate)
                               ->where('tanggal', '<=', $endDate)
                               ->pluck('id');

    if ($jadwalIds->isEmpty()) {
        return [
            'hadir' => 0,
            'izin' => 0,
            'alpa' => 0
        ];
    }

    // CRITICAL: Hitung berdasarkan tipe=masuk saja (sesuai SQL user)
    
    // ALPA: status = 'alpa' AND tipe = 'masuk'
    $alpa = Presensi::whereIn('jadwal_karyawan_id', $jadwalIds)
                    ->where('tipe', 'masuk')
                    ->where('status', 'alpa')
                    ->count();

    // IZIN: status = 'izin' AND tipe = 'masuk'
    $izin = Presensi::whereIn('jadwal_karyawan_id', $jadwalIds)
                    ->where('tipe', 'masuk')
                    ->where('status', 'izin')
                    ->count();

    // HADIR: status NOT IN ('alpa', 'izin', 'libur') AND tipe = 'masuk'
    // Ini mencakup: hadir, terlambat, dll
    $hadir = Presensi::whereIn('jadwal_karyawan_id', $jadwalIds)
                     ->where('tipe', 'masuk')
                     ->whereNotIn('status', ['alpa', 'izin', 'libur'])
                     ->count();

    // Log::info('Statistik Presensi Debug', [
    //     'karyawan_project_id' => $karyawanProjectId,
    //     'start_date' => $startDate,
    //     'end_date' => $endDate,
    //     'total_jadwal' => $jadwalIds->count(),
    //     'hadir' => $hadir,
    //     'izin' => $izin,
    //     'alpa' => $alpa
    // ]);

    return [
        'hadir' => $hadir,
        'izin' => $izin,
        'alpa' => $alpa
    ];
}

/**
 * Get rekap presensi bulanan by project
 * Shows all calendar days in period, with strips for days without presensi
 */
/**
 * Get rekap presensi bulanan by project
 * Shows all calendar days in period, with strips for days without presensi
 * Izin dikelompokkan berdasarkan kategori_izin
 */
public function getRekapBulanan(Request $request)
{
    $request->validate([
        'project_id' => 'required|exists:projects,id',
        'bulan' => 'required|date_format:Y-m'
    ]);

    try {
        $projectId = $request->project_id;
        $bulan = $request->bulan;

        // Get project info
        $project = Project::with('shiftProjects')->findOrFail($projectId);

        // Calculate EXACT period based on project start
        $projectStart = Carbon::parse($project->tanggal_mulai);
        $requestedDate = Carbon::parse($bulan . '-01');
        
        // Calculate how many complete months from project start to requested month
        $yearsDiff = $requestedDate->year - $projectStart->year;
        $monthsDiff = $requestedDate->month - $projectStart->month;
        $totalMonthsDiff = ($yearsDiff * 12) + $monthsDiff;
        
        // Period always starts on same day as project start
        $periodStart = $projectStart->copy()->addMonths($totalMonthsDiff);
        
        // Period end = 1 day before next period starts
        $periodEnd = $periodStart->copy()->addMonth()->subDay();
        
        $startDate = $periodStart->format('Y-m-d');
        $endDate = $periodEnd->format('Y-m-d');

        // Log::info('Rekap Bulanan - Period Calculation', [
        //     'project_start' => $projectStart->format('Y-m-d'),
        //     'requested_month' => $bulan,
        //     'total_months_diff' => $totalMonthsDiff,
        //     'period_start' => $startDate,
        //     'period_end' => $endDate
        // ]);

        // Generate ALL days in period (for calendar display)
        $daysInPeriod = [];
        $currentDate = clone $periodStart;
        while ($currentDate->lessThanOrEqualTo($periodEnd)) {
            $dayOfWeek = $currentDate->dayOfWeek;
            $daysInPeriod[] = [
                'day' => (int)$currentDate->format('d'),
                'date' => $currentDate->format('Y-m-d'),
                'day_name' => $this->getDayName($dayOfWeek),
                'is_weekend' => ($dayOfWeek == 0 || $dayOfWeek == 6)
            ];
            $currentDate->addDay();
        }

        // Get karyawan yang aktif di project
        $karyawanProjects = KaryawanProject::with(['karyawan.divisi', 'karyawan.jabatan'])
            ->where('project_id', $projectId)
            ->where('status', 'aktif')
            ->get();

        if ($karyawanProjects->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'project' => [
                    'id' => $project->id,
                    'nama' => $project->nama,
                    'lokasi' => $this->parseLokasiProject($project),
                    'total_karyawan' => 0
                ],
                'days_in_month' => $daysInPeriod,
                'period_info' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'bulan' => $bulan
            ]);
        }

        $result = [];

        foreach ($karyawanProjects as $kp) {
            $karyawan = $kp->karyawan;

            // Get presensi yang ada untuk periode ini
            $presensis = Presensi::whereHas('jadwalKaryawan', function($q) use ($kp) {
                    $q->where('karyawan_project_id', $kp->id);
                })
                ->where('tanggal', '>=', $startDate)
                ->where('tanggal', '<=', $endDate)
                ->get();

            // Group presensi by date untuk lookup cepat
            $presensisByDate = $presensis->groupBy(function($item) {
                return Carbon::parse($item->tanggal)->format('Y-m-d');
            });

            $dailyData = [];
            
            // UPDATED: Rekap dengan kategori izin yang detail
            $rekap = [
                'hadir' => 0,
                'sakit' => 0,      // Izin sakit
                'izin' => 0,       // Izin biasa
                'cuti' => 0,       // Akumulasi cuti tahunan + cuti khusus
                'alpa' => 0,
                'libur' => 0
            ];

            // Loop semua hari dalam periode (calendar view)
            foreach ($daysInPeriod as $dayInfo) {
                $tanggal = $dayInfo['date'];
                $day = $dayInfo['day'];

                // Cek apakah ada presensi untuk tanggal ini
                if (!isset($presensisByDate[$tanggal])) {
                    // Tidak ada presensi = strip
                    $dailyData[$day] = ['-'];
                    continue;
                }

                // Ada presensi untuk tanggal ini
                $dailyPresensis = $presensisByDate[$tanggal];
                $presensiMasuk = $dailyPresensis->firstWhere('tipe', 'masuk');
                $presensiPulang = $dailyPresensis->firstWhere('tipe', 'pulang');

                $statusList = [];

                // Cek libur
                if ($presensiMasuk && $presensiMasuk->status === 'libur') {
                    $statusList[] = 'L';
                    $rekap['libur']++;
                }
                // UPDATED: Cek izin dengan kategori
                elseif ($presensiMasuk && $presensiMasuk->status === 'izin') {
                    $kategoriIzin = $presensiMasuk->kategori_izin;
                    
                    switch ($kategoriIzin) {
                        case 'sakit':
                            $statusList[] = 'S';
                            $rekap['sakit']++;
                            break;
                        case 'izin':
                            $statusList[] = 'I';
                            $rekap['izin']++;
                            break;
                        case 'cuti_tahunan':
                            $statusList[] = 'CT';
                            $rekap['cuti']++;
                            break;
                        case 'cuti_khusus':
                            $statusList[] = 'IK';
                            $rekap['cuti']++;
                            break;
                        default:
                            // Fallback jika kategori tidak dikenali
                            $statusList[] = 'I';
                            $rekap['izin']++;
                            break;
                    }
                }
                // Cek alpa
                elseif ($presensiMasuk && $presensiMasuk->status === 'alpa') {
                    $statusList[] = 'A';
                    $rekap['alpa']++;
                }
                // Status normal (hadir/terlambat)
                else {
                    if ($presensiMasuk) {
                        if ($presensiMasuk->status === 'terlambat') {
                            $statusList[] = 'T';
                        } else {
                            $statusList[] = 'H';
                        }
                        $rekap['hadir']++;
                    }

                    // Cek status pulang
                    if ($presensiPulang) {
                        if ($presensiPulang->status === 'lembur' || $presensiPulang->status === 'lembur_pending') {
                            $statusList[] = 'LB';
                        } elseif ($presensiPulang->status === 'tidak_presensi_pulang') {
                            $statusList[] = 'TPP';
                        } elseif ($presensiPulang->status === 'pulang_cepat') {
                            $statusList[] = 'PC';
                        }
                    }
                }

                // Jika tidak ada status sama sekali, set strip
                if (empty($statusList)) {
                    $statusList[] = '-';
                }

                $dailyData[$day] = $statusList;
            }

            $result[] = [
                'nik' => $karyawan->nik,
                'nama' => $karyawan->nama,
                'divisi' => $karyawan->divisi ? $karyawan->divisi->nama : '-',
                'jabatan' => $karyawan->jabatan->nama ?? '-',
                'rekap' => $rekap,
                'daily_data' => $dailyData
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $result,
            'project' => [
                'id' => $project->id,
                'nama' => $project->nama,
                'lokasi' => $this->parseLokasiProject($project),
                'total_karyawan' => count($result)
            ],
            'days_in_month' => $daysInPeriod,
            'period_info' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'bulan' => $bulan,
            'legend' => [
                'H' => 'Hadir',
                'T' => 'Terlambat',
                'S' => 'Sakit',
                'I' => 'Izin',
                'CT' => 'Cuti Tahunan',
                'IK' => 'Izin Khusus',
                'A' => 'Alpa',
                'L' => 'Libur',
                'LB' => 'Lembur',
                'PC' => 'Pulang Cepat',
                'TPP' => 'Tidak Presensi Pulang',
                '-' => 'Tidak Ada Data'
            ]
        ]);

    } catch (\Exception $e) {
        // Log::error('Get rekap bulanan error: ' . $e->getMessage());
        // Log::error($e->getTraceAsString());
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal memuat data: ' . $e->getMessage()
        ], 500);
    }
}

private function getDayName($dayOfWeek)
{
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    return $days[$dayOfWeek] ?? '-';
}

public function getStatistikPeriode(Request $request)
{
    $request->validate([
        'bulan' => 'required|date_format:Y-m'
    ]);

    try {
        $karyawan = $request->user();
        $karyawanProject = $karyawan->activeProject;
        
        if (!$karyawanProject) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum terdaftar di project manapun'
            ], 404);
        }

        $bulan = $request->bulan; // Format: yyyy-MM
        
        // ✅ Get project start date from database
        $projectId = $karyawanProject->project_id;
        $rawTanggalMulai = \DB::selectOne("SELECT tanggal_mulai FROM projects WHERE id = ?", [$projectId])->tanggal_mulai;
        
        $projectStart = Carbon::createFromFormat('Y-m-d', $rawTanggalMulai);
        
        // Parse requested month (format: yyyy-MM)
        $requestedYear = (int)substr($bulan, 0, 4);
        $requestedMonth = (int)substr($bulan, 5, 2);
        
        // Log::info('📅 Period Calculation Start', [
        //     'project_start' => $projectStart->format('Y-m-d'),
        //     'requested_bulan' => $bulan,
        //     'project_start_day' => $projectStart->day,
        // ]);
        
        // ✅ SIMPLE CALCULATION: Period starts on project start day
        // For yyyy-MM request, period is: yyyy-MM-DD to yyyy-(MM+1)-(DD-1)
        // where DD is project start day
        
        $periodStart = Carbon::create(
            $requestedYear,
            $requestedMonth,
            $projectStart->day,
            0, 0, 0
        );
        
        // Period end = 1 day before next period starts
        $periodEnd = $periodStart->copy()->addMonth()->subDay();
        
        $startDate = $periodStart->format('Y-m-d');
        $endDate = $periodEnd->format('Y-m-d');

        // Log::info('✅ Calculated Period', [
        //     'requested_month' => $bulan,
        //     'period_start' => $startDate,
        //     'period_end' => $endDate,
        //     'days_in_period' => $periodStart->diffInDays($periodEnd) + 1
        // ]);

        // Get jadwal IDs untuk periode ini
        $jadwalIds = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
                                   ->where('tanggal', '>=', $startDate)
                                   ->where('tanggal', '<=', $endDate)
                                   ->pluck('id');

        if ($jadwalIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'hadir' => 0,
                    'izin' => 0,
                    'alpa' => 0,
                    'sakit' => 0,
                    'cuti' => 0,
                    'lembur' => 0,
                    'terlambat' => 0,
                    'pulang_cepat' => 0,
                    'tidak_presensi_pulang' => 0
                ],
                'period_info' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'bulan' => $bulan
                ]
            ]);
        }

        // Get all presensi untuk periode ini
        $presensis = Presensi::whereIn('jadwal_karyawan_id', $jadwalIds)->get();

        // Hitung statistik berdasarkan tipe presensi masuk
        $presensiMasuk = $presensis->where('tipe', 'masuk');
        
        $hadir = $presensiMasuk->whereNotIn('status', ['alpa', 'izin', 'libur'])->count();
        $izinTotal = $presensiMasuk->where('status', 'izin')->count();
        $alpa = $presensiMasuk->where('status', 'alpa')->count();

        // Hitung SAKIT dan CUTI dari pengajuan_izins yang disetujui
        $sakit = 0;
        $cuti = 0;
        
        $pengajuanIzins = PengajuanIzin::whereHas('jadwalKaryawan', function($q) use ($karyawanProject) {
                $q->where('karyawan_project_id', $karyawanProject->id);
            })
            ->where('status', 'disetujui')
            ->where('tanggal_mulai', '<=', $endDate)
            ->where('tanggal_selesai', '>=', $startDate)
            ->get();

        foreach ($pengajuanIzins as $pengajuan) {
            $pengajuanStart = max($pengajuan->tanggal_mulai, Carbon::parse($startDate));
            $pengajuanEnd = min($pengajuan->tanggal_selesai, Carbon::parse($endDate));
            
            $currentDate = $pengajuanStart->copy();
            $jumlahHari = 0;
            
            while ($currentDate->lessThanOrEqualTo($pengajuanEnd)) {
                $jadwal = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
                    ->whereDate('tanggal', $currentDate->format('Y-m-d'))
                    ->first();
                
                if ($jadwal && strtoupper($jadwal->shift_code) !== 'L') {
                    $jumlahHari++;
                }
                
                $currentDate->addDay();
            }
            
            $kategoriIzin = strtolower($pengajuan->kategori_izin);
            
            if (str_contains($kategoriIzin, 'sakit')) {
                $sakit += $jumlahHari;
            } elseif (str_contains($kategoriIzin, 'cuti')) {
                $cuti += $jumlahHari;
            }
        }

        // Hitung dari presensi pulang
        $presensiPulang = $presensis->where('tipe', 'pulang');
        
        $lembur = $presensiPulang->whereIn('status', ['lembur', 'lembur_pending'])->count();
        $terlambat = $presensiMasuk->where('status', 'terlambat')->count();
        $pulangCepat = $presensiPulang->where('status', 'pulang_cepat')->count();
        $tidakPresensiPulang = $presensiPulang->where('status', 'tidak_presensi_pulang')->count();

        // Log::info('✅ Statistik Result', [
        //     'period' => "$startDate to $endDate",
        //     'hadir' => $hadir,
        //     'izin' => $izinTotal,
        //     'alpa' => $alpa,
        //     'sakit' => $sakit,
        //     'cuti' => $cuti,
        // ]);

        return response()->json([
            'success' => true,
            'data' => [
                'hadir' => $hadir,
                'izin' => $izinTotal,
                'alpa' => $alpa,
                'sakit' => $sakit,
                'cuti' => $cuti,
                'lembur' => $lembur,
                'terlambat' => $terlambat,
                'pulang_cepat' => $pulangCepat,
                'tidak_presensi_pulang' => $tidakPresensiPulang
            ],
            'period_info' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'bulan' => $bulan
            ]
        ]);

    } catch (\Exception $e) {
        // Log::error('Get statistik periode error: ' . $e->getMessage());
        // Log::error($e->getTraceAsString());
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal memuat statistik: ' . $e->getMessage()
        ], 500);
    }
}

public function getJadwalBulan(Request $request)
{
    $request->validate([
        'bulan' => 'required|date_format:Y-m'
    ]);

    try {
        $user = $request->user();
        
        // Get karyawan project aktif
        $karyawanProject = KaryawanProject::with(['project.shiftProjects', 'karyawan'])
            ->where('karyawan_id', $user->id)
            ->where('status', 'aktif')
            ->whereHas('project', function($q) {
                $q->where('status', 'aktif');
            })
            ->first();

        if (!$karyawanProject) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum terdaftar di project aktif manapun'
            ], 404);
        }

        $project = $karyawanProject->project;
        $karyawanId = $karyawanProject->karyawan_id;
        
        // Parse bulan parameter
        $bulanParam = $request->bulan;
        $year = substr($bulanParam, 0, 4);
        $month = substr($bulanParam, 5, 2);
        
        $startOfMonth = "$year-$month-01";
        $lastDay = date('t', strtotime($startOfMonth));
        $endOfMonth = "$year-$month-$lastDay";

        // Log::info('🔍 getJadwalBulan Debug', [
        //     'bulan' => $bulanParam,
        //     'start' => $startOfMonth,
        //     'end' => $endOfMonth,
        //     'project_id' => $project->id,
        //     'karyawan_project_id' => $karyawanProject->id
        // ]);

        // ✅ Load shifts explicitly
        $project->load('shiftProjects');
        
        // Create shift map for quick lookup
        $shiftMap = [];
        foreach ($project->shiftProjects as $shift) {
            $shiftMap[strtoupper($shift->kode)] = [
                'waktu_mulai' => substr($shift->waktu_mulai, 0, 5), // HH:mm format
                'waktu_selesai' => substr($shift->waktu_selesai, 0, 5)
            ];
        }

        // Log::info('📋 Shift Map', [
        //     'shifts' => $shiftMap,
        //     'total_shifts' => count($shiftMap),
        //     'raw_shifts' => $project->shiftProjects->pluck('kode')->toArray()
        // ]);

        // Query dengan filter bulan di database level
        $jadwals = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
            ->whereDate('tanggal', '>=', $startOfMonth)
            ->whereDate('tanggal', '<=', $endOfMonth)
            ->orderBy('tanggal', 'asc')
            ->get()
            ->map(function($jadwal) use ($shiftMap, $karyawanId) {
                // Parse tanggal untuk formatting
                $date = new \DateTime($jadwal->tanggal);
                $dayOfWeek = (int) $date->format('w');
                $isWeekend = in_array($dayOfWeek, [0, 6]);
                
                // Get shift code (uppercase untuk matching)
                $shiftCodeUpper = strtoupper($jadwal->shift_code);
                
                // Determine waktu_mulai dan waktu_selesai
                $waktuMulai = null;
                $waktuSelesai = null;
                
                if ($shiftCodeUpper !== 'L') {
                    // Cari di shift map
                    if (isset($shiftMap[$shiftCodeUpper])) {
                        $waktuMulai = $shiftMap[$shiftCodeUpper]['waktu_mulai'];
                        $waktuSelesai = $shiftMap[$shiftCodeUpper]['waktu_selesai'];
                    } 
                    // else {
                    //     Log::warning('⚠️ Shift not found in map', [
                    //         'shift_code' => $jadwal->shift_code,
                    //         'shift_code_upper' => $shiftCodeUpper,
                    //         'available_shifts' => array_keys($shiftMap)
                    //     ]);
                    // }
                }
                
                // Check tukar shift info
                $tukarShiftInfo = null;
                $isDitukar = $jadwal->isDitukar();
                
                if ($isDitukar) {
                    $tukarShift = $jadwal->getTukarShiftInfo();
                    if ($tukarShift) {
                        $isPeminta = $tukarShift->isPeminta($karyawanId);
                        $tukarShiftInfo = [
                            'id' => $tukarShift->id,
                            'dengan' => $isPeminta 
                                ? $tukarShift->target->nama 
                                : $tukarShift->peminta->nama,
                        ];
                    }
                }
                
                $result = [
                    'id' => $jadwal->id,
                    'tanggal' => $jadwal->tanggal,
                    'hari' => $this->getIndonesianDay($dayOfWeek),
                    'tanggal_format' => $date->format('d'),
                    'bulan_format' => $this->getIndonesianMonth((int) $date->format('m')),
                    'tahun' => $date->format('Y'),
                    'shift_code' => $jadwal->shift_code,
                    'waktu_mulai' => $waktuMulai,
                    'waktu_selesai' => $waktuSelesai,
                    'is_libur' => $shiftCodeUpper === 'L',
                    'is_weekend' => $isWeekend,
                    'is_ditukar' => $isDitukar,
                    'tukar_shift_info' => $tukarShiftInfo,
                ];
                
                // Debug log untuk item pertama
                // static $firstLog = true;
                // if ($firstLog) {
                //     Log::info('📅 First Jadwal Item', $result);
                //     $firstLog = false;
                // }
                
                return $result;
            });

        // Log::info('✅ Total jadwals', ['count' => $jadwals->count()]);

        return response()->json([
            'success' => true,
            'data' => $jadwals->values(), // Reset array keys
            'period_info' => [
                'start_date' => $startOfMonth,
                'end_date' => $endOfMonth,
                'bulan' => $bulanParam,
                'bulan_display' => $this->getIndonesianMonth((int) $month) . ' ' . $year,
            ],
            'project_info' => [
                'id' => $project->id,
                'nama' => $project->nama,
                'tanggal_mulai' => $project->tanggal_mulai instanceof \DateTime 
                    ? $project->tanggal_mulai->format('Y-m-d')
                    : $project->tanggal_mulai,
            ],
        ]);

    } catch (\Exception $e) {
        // Log::error('❌ Get jadwal bulan error: ' . $e->getMessage());
        // Log::error($e->getTraceAsString());
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal memuat jadwal: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Helper functions untuk formatting
 */
private function getIndonesianDay($dayOfWeek)
{
    $days = [
        0 => 'Minggu',
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu'
    ];
    return $days[$dayOfWeek] ?? '';
}

private function getIndonesianMonth($month)
{
    $months = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agt',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];
    return $months[$month] ?? '';
}

/**
 * Check apakah jadwal ini hasil tukar shift yang disetujui
 */
public function isDitukar()
{
    // Cek apakah jadwal ini terlibat dalam tukar shift yang disetujui
    $tukarShift = TukarShift::where('status', 'disetujui')
        ->where(function($q) {
            $q->where('jadwal_peminta_id', $this->id)
              ->orWhere('jadwal_target_id', $this->id);
        })
        ->first();
    
    return !is_null($tukarShift);
}

/**
 * Get info tukar shift jika ada
 */
public function getTukarShiftInfo()
{
    return TukarShift::where('status', 'disetujui')
        ->where(function($q) {
            $q->where('jadwal_peminta_id', $this->id)
              ->orWhere('jadwal_target_id', $this->id);
        })
        ->with(['peminta', 'target'])
        ->first();
}
}