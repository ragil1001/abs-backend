<?php

namespace App\Http\Controllers;

use App\Models\Presensi;
use App\Models\JadwalKaryawan;
use App\Models\Karyawan;
use App\Models\KaryawanProject;
use App\Models\Project;
use App\Models\ShiftProject;
use Illuminate\Http\Request;
use App\Models\PengajuanIzin;
use App\Models\TukarShift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PresensiController extends Controller
{


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


            if ($isLibur) {
                return $this->handlePresensiHariLibur($jadwal, $karyawan, $project, $now);
            }


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


            if (!$presensiMasuk) {

                if ($now->greaterThan($waktuTutupPresensiMasuk)) {
                    $selisihMenit = (int)$now->diffInMinutes($waktuTutupPresensiMasuk);
                    $pesanWaktu = "Shift sudah berakhir " . $this->formatMenit($selisihMenit) . " yang lalu. Anda tidak dapat melakukan presensi masuk.";
                    $errorType = 'shift_ended';
                } elseif ($now->lessThan($waktuBukaPresensiMasuk)) {
                    $selisihMenit = (int)$now->diffInMinutes($waktuBukaPresensiMasuk);
                    $pesanWaktu = "Presensi masuk akan dibuka pada " .
                        $waktuBukaPresensiMasuk->format('H:i') .
                        " (" . $this->formatMenit($selisihMenit) . " lagi)";
                    $errorType = 'shift_not_started';
                } else {
                    $bisaPresensiMasuk = true;
                    $pesanWaktu = "Anda dapat melakukan presensi masuk sekarang";
                }
            } else {
                if (in_array($presensiMasuk->status, ['alpa', 'izin', 'libur'])) {
                    $pesanWaktu = "Anda tidak dapat melakukan presensi pulang (Status: {$presensiMasuk->status})";
                    $errorType = 'status_blocked';
                } elseif (!$presensiPulang) {

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
                        'waktu_selesai_shift' => $waktuSelesaiShift->format('H:i:s'),
                        'waktu_sekarang_full' => $now->format('Y-m-d H:i:s'),
                        'pesan' => $pesanWaktu,
                        'error_type' => $errorType
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


        if (!$presensiMasuk || $presensiMasuk->status === 'libur') {
            $bisaPresensiMasuk = true;
            $pesanWaktu = "Hari libur - Anda dapat melakukan presensi masuk kapan saja";
        } else {
            $tanggalMasuk = Carbon::parse($presensiMasuk->tanggal)->format('Y-m-d');
            $waktuMasuk = Carbon::parse($tanggalMasuk . ' ' . $presensiMasuk->waktu);
            $batasTidakPresensiPulang = $waktuMasuk->copy()->addHours(10);


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

                'sudah_presensi_masuk' => ($presensiMasuk && $presensiMasuk->status !== 'libur' && $presensiMasuk->waktu !== null),
                'sudah_presensi_pulang' => ($presensiPulang && $presensiPulang->status !== 'libur' && $presensiPulang->waktu !== null),
                'peringatan' => 'Jika Anda presensi di hari libur, jangan lupa mengajukan lembur dengan upload SKL'
            ]
        ]);
    }



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













            $isJabatanExcluded = $project->isJabatanExcluded($karyawan->jabatan_id);




















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









            return response()->json($response);
        } catch (\Exception $e) {



            return response()->json([
                'success' => false,
                'message' => 'Gagal memvalidasi lokasi: ' . $e->getMessage()
            ], 500);
        }
    }


    private function hitungJarak($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000;


        $lat1 = (float)$lat1;
        $lon1 = (float)$lon1;
        $lat2 = (float)$lat2;
        $lon2 = (float)$lon2;












        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);








        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        $distance = $earthRadius * $c;







        return $distance;
    }

    private function submitPresensiHariLibur($request, $jadwal, $karyawan, $project)
    {

        $existingMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
            ->where('tipe', 'masuk')
            ->first();

        $existingPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
            ->where('tipe', 'pulang')
            ->first();


        if (
            $request->tipe === 'masuk' &&
            $existingMasuk &&
            $existingMasuk->status !== 'libur' &&
            $existingMasuk->waktu !== null
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah melakukan presensi masuk di hari libur ini'
            ], 400);
        }

        if (
            $request->tipe === 'pulang' &&
            $existingPulang &&
            $existingPulang->status !== 'libur' &&
            $existingPulang->waktu !== null
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah melakukan presensi pulang di hari libur ini'
            ], 400);
        }


        if ($request->tipe === 'pulang') {
            if (
                !$existingMasuk ||
                $existingMasuk->status === 'libur' ||
                $existingMasuk->waktu === null
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda harus presensi masuk terlebih dahulu sebelum presensi pulang'
                ], 400);
            }
        }


        $isJabatanExcluded = $project->isJabatanExcluded($karyawan->jabatan_id);

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


        if (!$isJabatanExcluded && $jarak > $project->radius) {








            return response()->json([
                'success' => false,
                'message' => 'Anda berada di luar radius lokasi presensi. Jarak Anda: ' . round($jarak, 2) . ' meter',
                'data' => [
                    'jarak' => round($jarak, 2),
                    'radius' => (int)$project->radius
                ]
            ], 400);
        }


        $fotoPath = $this->uploadDanKompresFoto($request->file('foto'), $karyawan->id);


        $waktuSekarang = Carbon::now();
        $tanggal = $jadwal->tanggal instanceof Carbon
            ? $jadwal->tanggal
            : Carbon::parse($jadwal->tanggal);


        $status = 'hadir';
        $keterangan = null;

        if ($request->tipe === 'masuk') {

            $status = 'hadir';
            $keterangan = 'Presensi masuk di hari libur';

            if ($isJabatanExcluded) {
                $keterangan .= ' (Jabatan dikecualikan dari radius)';
            }
        } else {

            $status = 'lembur_pending';
            $keterangan = 'Presensi pulang di hari libur - menunggu konfirmasi lembur';

            if ($isJabatanExcluded) {
                $keterangan .= ' (Jabatan dikecualikan dari radius)';
            }
        }


        if ($request->tipe === 'masuk' && $existingMasuk) {

            $existingMasuk->update([
                'status' => $status,
                'waktu' => $waktuSekarang->format('H:i:s'),
                'latitude' => (float)$request->latitude,
                'longitude' => (float)$request->longitude,
                'foto' => $fotoPath,
                'keterangan' => $keterangan
            ]);

            $presensi = $existingMasuk->fresh();
        } elseif ($request->tipe === 'pulang' && $existingPulang) {

            $existingPulang->update([
                'status' => $status,
                'waktu' => $waktuSekarang->format('H:i:s'),
                'latitude' => (float)$request->latitude,
                'longitude' => (float)$request->longitude,
                'foto' => $fotoPath,
                'keterangan' => $keterangan
            ]);

            $presensi = $existingPulang->fresh();
        } else {

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
        }

        DB::commit();


        $presensi = Presensi::find($presensi->id);














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


            $jadwalKaryawan = JadwalKaryawan::whereHas('karyawanProject', function ($q) use ($karyawan) {
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


            if ($isLibur) {
                return $this->submitPresensiHariLibur($request, $jadwalKaryawan, $karyawan, $project);
            }


            $existing = Presensi::whereHas('jadwalKaryawan', function ($q) use ($karyawan, $jadwal) {
                $q->whereHas('karyawanProject', function ($q2) use ($karyawan) {
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


            $isJabatanExcluded = $project->isJabatanExcluded($karyawan->jabatan_id);









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


            if (!$isJabatanExcluded && $jarak > $project->radius) {






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







            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan presensi: ' . $e->getMessage()
            ], 500);
        }
    }



















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
                    0,
                    0,
                    0,
                    0,
                    $newWidth,
                    $newHeight,
                    $originalWidth,
                    $originalHeight
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


                $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                    ->where('tipe', 'masuk')
                    ->first();

                $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                    ->where('tipe', 'pulang')
                    ->first();


                $status = 'alpa';
                $isClickable = false;


                if (strtoupper($shiftCode) === 'L') {

                    if (
                        $presensiMasuk &&
                        $presensiMasuk->status !== 'libur' &&
                        $presensiMasuk->waktu !== null
                    ) {

                        $status = $presensiMasuk->status;
                        $isClickable = true;
                    } else {

                        $status = 'libur';
                        $isClickable = false;
                    }
                } elseif ($presensiMasuk) {
                    $status = $presensiMasuk->status;


                    if (!in_array($presensiMasuk->status, ['alpa', 'izin', 'libur'])) {
                        $isClickable = true;
                    } else {
                        $isClickable = false;
                    }
                } else {
                    $status = 'alpa';
                    $isClickable = false;
                }


                $shift = ShiftProject::where('project_id', $project->id)
                    ->where('kode', $shiftCode)
                    ->first();


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
                    'is_clickable' => $isClickable
                ];
            }







            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {



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


    public function getRekapHarian(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'tanggal' => 'required|date'
        ]);

        try {
            $projectId = $request->project_id;
            $tanggal = $request->tanggal;


            $project = Project::with('shiftProjects')->findOrFail($projectId);


            $shiftMap = [];
            foreach ($project->shiftProjects as $shift) {
                $shiftMap[strtoupper($shift->kode)] = [
                    'kode' => $shift->kode,
                    'waktu_mulai' => substr($shift->waktu_mulai, 0, 5),
                    'waktu_selesai' => substr($shift->waktu_selesai, 0, 5)
                ];
            }







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


                $shiftCodeUpper = strtoupper($shiftCode);
                $shift = null;
                $shiftDisplay = 'Libur';

                if ($shiftCodeUpper !== 'L' && isset($shiftMap[$shiftCodeUpper])) {
                    $shift = $shiftMap[$shiftCodeUpper];
                    $shiftDisplay = "{$shift['kode']} ({$shift['waktu_mulai']} - {$shift['waktu_selesai']})";
                } elseif ($shiftCodeUpper !== 'L') {
                }


                $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                    ->where('tipe', 'masuk')
                    ->first();


                $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                    ->where('tipe', 'pulang')
                    ->first();

                $pengajuanLembur = null;
                if ($presensiPulang && in_array($presensiPulang->status, ['lembur', 'lembur_pending'])) {
                    $pengajuanLembur = \App\Models\PengajuanLembur::where('jadwal_karyawan_id', $jadwal->id)
                        ->whereIn('status', ['disetujui'])
                        ->first();
                }

                $item = [
                    'id' => $jadwal->id,
                    'nik' => $karyawan->nik,
                    'nama' => $karyawan->nama,
                    'divisi' => $karyawan->divisi ? $karyawan->divisi->nama : '-',
                    'jabatan' => $karyawan->jabatan->nama ?? '-',
                    'shift' => $shiftDisplay,
                    'shift_code' => $shiftCode,
                    'presensi_masuk' => $presensiMasuk ? [
                        'id' => $presensiMasuk->id,
                        'waktu' => $presensiMasuk->waktu
                            ? Carbon::parse($presensiMasuk->waktu)->format('H:i')
                            : null,
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
                        'waktu' => $presensiPulang->waktu
                            ? Carbon::parse($presensiPulang->waktu)->format('H:i')
                            : null,
                        'status' => $presensiPulang->status,
                        'keterangan' => $presensiPulang->keterangan,
                        'latitude' => $presensiPulang->latitude,
                        'longitude' => $presensiPulang->longitude,
                        'lokasi_nama' => $this->parseLokasiNama($project),
                        'foto' => $presensiPulang->foto ? url('storage/' . $presensiPulang->foto) : null,
                        'google_maps_url' => $presensiPulang->latitude && $presensiPulang->longitude
                            ? "https://www.google.com/maps?q={$presensiPulang->latitude},{$presensiPulang->longitude}"
                            : null
                    ] : null,
                    'pengajuan_lembur' => $pengajuanLembur ? [
                        'jam_mulai' => $pengajuanLembur->jam_mulai,
                        'jam_selesai' => $pengajuanLembur->jam_selesai,
                        'status' => $pengajuanLembur->status
                    ] : null
                ];


                $statusMasuk = $presensiMasuk ? $presensiMasuk->status : (strtoupper($shiftCode) === 'L' ? 'libur' : 'alpa');
                if (isset($statistik['masuk'][$statusMasuk])) {
                    $statistik['masuk'][$statusMasuk]++;
                }


                $statusPulang = $presensiPulang ? $presensiPulang->status : (strtoupper($shiftCode) === 'L' ? 'libur' : 'alpa');

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


            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data: ' . $e->getMessage()
            ], 500);
        }
    }


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

                $notificationService->notifyKaryawanLemburDikonfirmasi($presensi);
            } elseif ($oldStatus === 'lembur_pending' && $newStatus !== 'lembur') {

                $notificationService->notifyKaryawanLemburDitolak($presensi, $keterangan);
            } else {

                $notificationService->notifyKaryawanPresensiDiupdate($presensi, $newStatus, $oldStatus, $keterangan);
            }

            return response()->json([
                'success' => true,
                'message' => 'Status presensi berhasil diupdate',
                'data' => $presensi->fresh()
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate status: ' . $e->getMessage()
            ], 500);
        }
    }


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


            return response()->json([
                'success' => false,
                'message' => 'Gagal mengkonfirmasi lembur: ' . $e->getMessage()
            ], 500);
        }
    }


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


            $currentMonth = Carbon::now();
            $startOfMonth = $currentMonth->copy()->startOfMonth()->format('Y-m-d');
            $endDate = $today;


            if ($projectStartDate > $startOfMonth) {
                $startDate = $projectStartDate;
            } else {
                $startDate = $startOfMonth;
            }









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

                    $shift = $project->shiftProjects->first(function ($s) use ($shiftCodeUpper) {
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










        return [
            'hadir' => $hadir,
            'izin' => $izin,
            'alpa' => $alpa
        ];
    }


    private function getStatistikTotal($karyawanProjectId, $startDate, $endDate)
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











        return [
            'hadir' => $hadir,
            'izin' => $izin,
            'alpa' => $alpa
        ];
    }



    public function getRekapBulanan(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'bulan' => 'required|date_format:Y-m'
        ]);

        try {
            $projectId = $request->project_id;
            $bulan = $request->bulan;


            $project = Project::with('shiftProjects')->findOrFail($projectId);


            $projectStart = Carbon::parse($project->tanggal_mulai);
            $requestedDate = Carbon::parse($bulan . '-01');


            $yearsDiff = $requestedDate->year - $projectStart->year;
            $monthsDiff = $requestedDate->month - $projectStart->month;
            $totalMonthsDiff = ($yearsDiff * 12) + $monthsDiff;


            $periodStart = $projectStart->copy()->addMonths($totalMonthsDiff);


            $periodEnd = $periodStart->copy()->addMonth()->subDay();

            $startDate = $periodStart->format('Y-m-d');
            $endDate = $periodEnd->format('Y-m-d');










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


                $presensis = Presensi::whereHas('jadwalKaryawan', function ($q) use ($kp) {
                    $q->where('karyawan_project_id', $kp->id);
                })
                    ->where('tanggal', '>=', $startDate)
                    ->where('tanggal', '<=', $endDate)
                    ->get();


                $presensisByDate = $presensis->groupBy(function ($item) {
                    return Carbon::parse($item->tanggal)->format('Y-m-d');
                });

                $dailyData = [];


                $rekap = [
                    'hadir' => 0,
                    'sakit' => 0,
                    'izin' => 0,
                    'cuti' => 0,
                    'alpa' => 0,
                    'libur' => 0
                ];


                foreach ($daysInPeriod as $dayInfo) {
                    $tanggal = $dayInfo['date'];
                    $day = $dayInfo['day'];


                    if (!isset($presensisByDate[$tanggal])) {

                        $dailyData[$day] = ['-'];
                        continue;
                    }


                    $dailyPresensis = $presensisByDate[$tanggal];
                    $presensiMasuk = $dailyPresensis->firstWhere('tipe', 'masuk');
                    $presensiPulang = $dailyPresensis->firstWhere('tipe', 'pulang');

                    $statusList = [];


                    if ($presensiMasuk && $presensiMasuk->status === 'libur') {
                        $statusList[] = 'L';
                        $rekap['libur']++;
                    } elseif ($presensiMasuk && $presensiMasuk->status === 'izin') {
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

                                $statusList[] = 'I';
                                $rekap['izin']++;
                                break;
                        }
                    } elseif ($presensiMasuk && $presensiMasuk->status === 'alpa') {
                        $statusList[] = 'A';
                        $rekap['alpa']++;
                    } else {
                        if ($presensiMasuk) {
                            if ($presensiMasuk->status === 'terlambat') {
                                $statusList[] = 'T';
                            } else {
                                $statusList[] = 'H';
                            }
                            $rekap['hadir']++;
                        }


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

            $bulan = $request->bulan;


            $projectId = $karyawanProject->project_id;
            $rawTanggalMulai = DB::selectOne("SELECT tanggal_mulai FROM projects WHERE id = ?", [$projectId])->tanggal_mulai;

            $projectStart = Carbon::createFromFormat('Y-m-d', $rawTanggalMulai);


            $requestedYear = (int)substr($bulan, 0, 4);
            $requestedMonth = (int)substr($bulan, 5, 2);











            $periodStart = Carbon::create(
                $requestedYear,
                $requestedMonth,
                $projectStart->day,
                0,
                0,
                0
            );


            $periodEnd = $periodStart->copy()->addMonth()->subDay();

            $startDate = $periodStart->format('Y-m-d');
            $endDate = $periodEnd->format('Y-m-d');









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


            $presensis = Presensi::whereIn('jadwal_karyawan_id', $jadwalIds)->get();


            $presensiMasuk = $presensis->where('tipe', 'masuk');

            $hadir = $presensiMasuk->whereNotIn('status', ['alpa', 'izin', 'libur'])->count();
            $izinTotal = $presensiMasuk->where('status', 'izin')->count();
            $alpa = $presensiMasuk->where('status', 'alpa')->count();


            $sakit = 0;
            $cuti = 0;

            $pengajuanIzins = PengajuanIzin::whereHas('jadwalKaryawan', function ($q) use ($karyawanProject) {
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


            $presensiPulang = $presensis->where('tipe', 'pulang');

            $lembur = $presensiPulang->whereIn('status', ['lembur', 'lembur_pending'])->count();
            $terlambat = $presensiMasuk->where('status', 'terlambat')->count();
            $pulangCepat = $presensiPulang->where('status', 'pulang_cepat')->count();
            $tidakPresensiPulang = $presensiPulang->where('status', 'tidak_presensi_pulang')->count();










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


            $karyawanProject = KaryawanProject::with(['project.shiftProjects', 'karyawan'])
                ->where('karyawan_id', $user->id)
                ->where('status', 'aktif')
                ->whereHas('project', function ($q) {
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


            $bulanParam = $request->bulan;
            $year = substr($bulanParam, 0, 4);
            $month = substr($bulanParam, 5, 2);

            $startOfMonth = "$year-$month-01";
            $lastDay = date('t', strtotime($startOfMonth));
            $endOfMonth = "$year-$month-$lastDay";










            $project->load('shiftProjects');


            $shiftMap = [];
            foreach ($project->shiftProjects as $shift) {
                $shiftMap[strtoupper($shift->kode)] = [
                    'waktu_mulai' => substr($shift->waktu_mulai, 0, 5),
                    'waktu_selesai' => substr($shift->waktu_selesai, 0, 5)
                ];
            }








            $jadwals = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
                ->whereDate('tanggal', '>=', $startOfMonth)
                ->whereDate('tanggal', '<=', $endOfMonth)
                ->orderBy('tanggal', 'asc')
                ->get()
                ->map(function ($jadwal) use ($shiftMap, $karyawanId) {

                    $date = new \DateTime($jadwal->tanggal);
                    $dayOfWeek = (int) $date->format('w');
                    $isWeekend = in_array($dayOfWeek, [0, 6]);


                    $shiftCodeUpper = strtoupper($jadwal->shift_code);


                    $waktuMulai = null;
                    $waktuSelesai = null;

                    if ($shiftCodeUpper !== 'L') {

                        if (isset($shiftMap[$shiftCodeUpper])) {
                            $waktuMulai = $shiftMap[$shiftCodeUpper]['waktu_mulai'];
                            $waktuSelesai = $shiftMap[$shiftCodeUpper]['waktu_selesai'];
                        }
                    }


                    $tukarShiftInfo = null;
                    $isDitukar = $this->isDitukar($jadwal);

                    if ($isDitukar) {
                        $tukarShift = $this->getTukarShiftInfo($jadwal);
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








                    return $result;
                });



            return response()->json([
                'success' => true,
                'data' => $jadwals->values(),
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



            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat jadwal: ' . $e->getMessage()
            ], 500);
        }
    }


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
            1 => 'Jan',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Apr',
            5 => 'Mei',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Agt',
            9 => 'Sep',
            10 => 'Okt',
            11 => 'Nov',
            12 => 'Des'
        ];
        return $months[$month] ?? '';
    }


    private function isDitukar($jadwal)
    {

        $tukarShift = TukarShift::where('status', 'disetujui')
            ->where(function ($q) use ($jadwal) {
                $q->where('jadwal_peminta_id', $jadwal->id)
                    ->orWhere('jadwal_target_id', $jadwal->id);
            })
            ->first();

        return !is_null($tukarShift);
    }


    private function getTukarShiftInfo($jadwal)
    {
        return TukarShift::where('status', 'disetujui')
            ->where(function ($q) use ($jadwal) {
                $q->where('jadwal_peminta_id', $jadwal->id)
                    ->orWhere('jadwal_target_id', $jadwal->id);
            })
            ->with(['peminta', 'target'])
            ->first();
    }

    public function getRekapPerKaryawan(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'karyawan_niks' => 'required|array',
            'karyawan_niks.*' => 'string',
            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after_or_equal:tanggal_mulai'
        ]);

        try {
            $projectId = $request->project_id;
            $karyawanNiks = $request->karyawan_niks;
            $tanggalMulai = $request->tanggal_mulai;
            $tanggalSelesai = $request->tanggal_selesai;

            $project = Project::with('shiftProjects')->findOrFail($projectId);

            // Build shift map
            $shiftMap = [];
            foreach ($project->shiftProjects as $shift) {
                $shiftMap[strtoupper($shift->kode)] = [
                    'kode' => $shift->kode,
                    'waktu_mulai' => substr($shift->waktu_mulai, 0, 5),
                    'waktu_selesai' => substr($shift->waktu_selesai, 0, 5)
                ];
            }

            // Get karyawan IDs from NIKs
            $karyawans = Karyawan::whereIn('nik', $karyawanNiks)->get();

            if ($karyawans->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Karyawan tidak ditemukan'
                ], 404);
            }

            $result = [];

            foreach ($karyawans as $karyawan) {
                $karyawanProject = KaryawanProject::with(['karyawan.divisi', 'karyawan.jabatan'])
                    ->where('project_id', $projectId)
                    ->where('karyawan_id', $karyawan->id)
                    ->first();

                if (!$karyawanProject) {
                    continue;
                }

                // Get jadwal dalam periode
                $jadwals = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
                    ->whereBetween('tanggal', [$tanggalMulai, $tanggalSelesai])
                    ->orderBy('tanggal', 'asc')
                    ->get();

                $presensiData = [];
                $statistik = [
                    'hadir' => 0,
                    'terlambat' => 0,
                    'izin' => 0,
                    'sakit' => 0,
                    'cuti' => 0,
                    'alpa' => 0,
                    'libur' => 0
                ];

                foreach ($jadwals as $jadwal) {
                    $tanggal = $jadwal->tanggal;
                    $shiftCode = $jadwal->shift_code;
                    $shiftCodeUpper = strtoupper($shiftCode);

                    $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                        ->where('tipe', 'masuk')
                        ->first();

                    $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                        ->where('tipe', 'pulang')
                        ->first();

                    $pengajuanLembur = null;
                    if ($presensiPulang && in_array($presensiPulang->status, ['lembur', 'lembur_pending'])) {
                        $pengajuanLembur = \App\Models\PengajuanLembur::where('jadwal_karyawan_id', $jadwal->id)
                            ->whereIn('status', ['disetujui'])
                            ->first();
                    }

                    // Hitung statistik
                    if ($shiftCodeUpper === 'L') {
                        if ($presensiMasuk && $presensiMasuk->status !== 'libur') {
                            $statistik['hadir']++;
                        } else {
                            $statistik['libur']++;
                        }
                    } else {
                        if ($presensiMasuk) {
                            if ($presensiMasuk->status === 'hadir') {
                                $statistik['hadir']++;
                            } elseif ($presensiMasuk->status === 'terlambat') {
                                $statistik['terlambat']++;
                            } elseif ($presensiMasuk->status === 'alpa') {
                                $statistik['alpa']++;
                            } elseif ($presensiMasuk->status === 'izin') {
                                $kategori = strtolower($presensiMasuk->kategori_izin ?? '');
                                if (str_contains($kategori, 'sakit')) {
                                    $statistik['sakit']++;
                                } elseif (str_contains($kategori, 'cuti')) {
                                    $statistik['cuti']++;
                                } else {
                                    $statistik['izin']++;
                                }
                            }
                        } else {
                            $statistik['alpa']++;
                        }
                    }

                    // Get shift info
                    $shift = null;
                    $shiftDisplay = '-';
                    if ($shiftCodeUpper !== 'L' && isset($shiftMap[$shiftCodeUpper])) {
                        $shift = $shiftMap[$shiftCodeUpper];
                        $shiftDisplay = "{$shift['kode']} ({$shift['waktu_mulai']}-{$shift['waktu_selesai']})";
                    } elseif ($shiftCodeUpper === 'L') {
                        $shiftDisplay = 'Libur';
                    }

                    $pengajuanLembur = null;
                    if ($presensiPulang && in_array($presensiPulang->status, ['lembur', 'lembur_pending'])) {
                        $pengajuanLembur = \App\Models\PengajuanLembur::where('jadwal_karyawan_id', $jadwal->id)
                            ->where('status', 'disetujui')
                            ->first();
                    }

                    $presensiData[] = [
                        'tanggal' => Carbon::parse($tanggal)->format('Y-m-d'),
                        'tanggal_formatted' => Carbon::parse($tanggal)->locale('id')->isoFormat('dddd, D MMMM YYYY'),
                        'shift' => $shiftDisplay,
                        'waktu_masuk' => ($presensiMasuk && $presensiMasuk->waktu)
                            ? Carbon::parse($presensiMasuk->waktu)->format('H:i')
                            : '-',
                        'waktu_pulang' => ($presensiPulang && $presensiPulang->waktu)
                            ? Carbon::parse($presensiPulang->waktu)->format('H:i')
                            : '-',
                        'status_masuk' => $presensiMasuk ? $this->getStatusText($presensiMasuk->status) : ($shiftCodeUpper === 'L' ? 'Libur' : 'Alpa'),
                        'status_pulang' => $presensiPulang ? $this->getStatusText($presensiPulang->status) : ($shiftCodeUpper === 'L' ? 'Libur' : 'Alpa'),
                        'keterangan_masuk' => $presensiMasuk->keterangan ?? '-',
                        'keterangan_pulang' => $presensiPulang->keterangan ?? '-',
                        'pengajuan_lembur' => $pengajuanLembur ? [
                            'jam_mulai' => Carbon::parse($pengajuanLembur->jam_mulai)->format('H:i'),
                            'jam_selesai' => Carbon::parse($pengajuanLembur->jam_selesai)->format('H:i')
                        ] : null
                    ];
                }

                $result[] = [
                    'karyawan' => [
                        'id' => $karyawan->id,
                        'nik' => $karyawan->nik,
                        'nama' => $karyawan->nama,
                        'divisi' => $karyawan->divisi ? $karyawan->divisi->nama : '-',
                        'jabatan' => $karyawan->jabatan->nama ?? '-'
                    ],
                    'statistik' => $statistik,
                    'presensi_data' => $presensiData
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $result,
                'project' => [
                    'id' => $project->id,
                    'nama' => $project->nama,
                    'lokasi' => $this->parseLokasiProject($project)
                ],
                'periode' => [
                    'tanggal_mulai' => $tanggalMulai,
                    'tanggal_selesai' => $tanggalSelesai
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data: ' . $e->getMessage()
            ], 500);
        }
    }
}
