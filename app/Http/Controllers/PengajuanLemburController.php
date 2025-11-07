<?php

namespace App\Http\Controllers;

use App\Models\PengajuanLembur;
use App\Models\JadwalKaryawan;
use App\Models\Presensi;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PengajuanLemburController extends Controller
{
    // ========== ADMIN WEB METHODS ==========
    
    /**
     * ✅ UPDATED: Get daftar pengajuan lembur (untuk admin)
     */
    public function index(Request $request, $projectId)
    {
        try {
            $query = PengajuanLembur::with([
                'jadwalKaryawan.karyawanProject.karyawan.divisi',
                'jadwalKaryawan.karyawanProject.karyawan.jabatan',
                'admin'
            ])->byProject($projectId);

            // Filter by status
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Filter by tanggal
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->betweenDates($request->start_date, $request->end_date);
            }

            // Search by nama karyawan
            if ($request->has('search')) {
                $search = $request->search;
                $query->whereHas('jadwalKaryawan.karyawanProject.karyawan', function($q) use ($search) {
                    $q->where('nama', 'like', '%' . $search . '%')
                      ->orWhere('nik', 'like', '%' . $search . '%');
                });
            }

            // Sorting
            $sortField = $request->input('sort_field', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);

            $perPage = $request->input('per_page', 10);
            $result = $query->paginate($perPage);

            $data = $result->map(function($pengajuan) {
                $karyawan = $pengajuan->jadwalKaryawan->karyawanProject->karyawan;
                
                return [
                    'id' => $pengajuan->id,
                    'karyawan' => [
                        'id' => $karyawan->id,
                        'nik' => $karyawan->nik,
                        'nama' => $karyawan->nama,
                        'divisi' => $karyawan->divisi ? $karyawan->divisi->nama : '-',
                        'jabatan' => $karyawan->jabatan->nama
                    ],
                    'tanggal' => $pengajuan->tanggal->format('Y-m-d'),
                    'kode_hari' => $pengajuan->kode_hari,
                    'kode_hari_text' => $pengajuan->kode_hari_text,
                    'jam_mulai' => $pengajuan->jam_mulai,
                    'jam_selesai' => $pengajuan->jam_selesai,
                    'file_skl_url' => $pengajuan->file_skl_url,
                    'keterangan_karyawan' => $pengajuan->keterangan_karyawan,
                    'status' => $pengajuan->status,
                    'status_text' => $pengajuan->status_text,
                    'catatan_admin' => $pengajuan->catatan_admin,
                    'diproses_pada' => $pengajuan->diproses_pada?->format('Y-m-d H:i:s'),
                    'diproses_oleh' => $pengajuan->admin?->username,
                    'created_at' => $pengajuan->created_at->format('Y-m-d H:i:s')
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'pagination' => [
                    'current_page' => $result->currentPage(),
                    'per_page' => $result->perPage(),
                    'total' => $result->total(),
                    'last_page' => $result->lastPage(),
                ]
            ]);

        } catch (\Exception $e) {
            // Log::error('Get pengajuan lembur error: ' . $e->getMessage());
            // Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data pengajuan lembur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Proses pengajuan lembur (setujui/tolak) - WITH NOTIFICATION
     */
    public function prosesPengajuan(Request $request, $pengajuanId)
    {
        $request->validate([
            'action' => 'required|in:setujui,tolak',
            'catatan' => 'nullable|string|max:500'
        ], [
            'action.required' => 'Action wajib dipilih',
            'action.in' => 'Action tidak valid'
        ]);

        DB::beginTransaction();
        try {
            $admin = $request->user();
            
            // Load pengajuan dengan semua relasi yang diperlukan
            $pengajuan = PengajuanLembur::with([
                'jadwalKaryawan.karyawanProject.karyawan',
                'jadwalKaryawan.karyawanProject.project'
            ])->findOrFail($pengajuanId);

            // Validasi status
            if ($pengajuan->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengajuan ini sudah diproses sebelumnya dengan status: ' . $pengajuan->status_text
                ], 400);
            }

            $karyawan = $pengajuan->jadwalKaryawan->karyawanProject->karyawan;
            $tanggalLembur = $pengajuan->tanggal->format('d/m/Y');

            // Proses berdasarkan action
            if ($request->action === 'setujui') {
                try {
                    $pengajuan->setujui($admin->id, $request->catatan);
                    
                    $message = 'Pengajuan lembur berhasil disetujui';
                    
                    // Log::info("Admin {$admin->username} menyetujui pengajuan lembur ID {$pengajuanId}");
                    
                    // 📢 KIRIM NOTIFIKASI KE KARYAWAN - DISETUJUI
                    // Notification::createForKaryawan(
                    //     karyawanId: $karyawan->id,
                    //     type: 'lembur_approved',
                    //     title: 'Pengajuan Lembur Disetujui',
                    //     body: "Pengajuan lembur Anda untuk tanggal {$tanggalLembur} telah disetujui.",
                    //     data: [
                    //         'pengajuan_lembur_id' => (string)$pengajuan->id,
                    //         'tanggal' => $pengajuan->tanggal->format('Y-m-d'),
                    //         'catatan_admin' => $request->catatan ?? '',
                    //     ]
                    // );
                    
                } catch (\Exception $e) {
                    // Log::error("Error saat setujui pengajuan lembur ID {$pengajuanId}: " . $e->getMessage());
                    throw $e;
                }
            } else {
                // Validasi: catatan wajib diisi saat menolak
                if (empty(trim($request->catatan))) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Catatan wajib diisi saat menolak pengajuan lembur'
                    ], 422);
                }
                
                $pengajuan->tolak($admin->id, $request->catatan);
                $message = 'Pengajuan lembur berhasil ditolak';
                
                // Log::info("Admin {$admin->username} menolak pengajuan lembur ID {$pengajuanId}");
                
                // 📢 KIRIM NOTIFIKASI KE KARYAWAN - DITOLAK
                // Notification::createForKaryawan(
                //     karyawanId: $karyawan->id,
                //     type: 'lembur_rejected',
                //     title: 'Pengajuan Lembur Ditolak',
                //     body: "Pengajuan lembur Anda untuk tanggal {$tanggalLembur} ditolak. Alasan: {$request->catatan}",
                //     data: [
                //         'pengajuan_lembur_id' => (string)$pengajuan->id,
                //         'tanggal' => $pengajuan->tanggal->format('Y-m-d'),
                //         'catatan_admin' => $request->catatan ?? '',
                //     ]
                // );
            }

            DB::commit();

            // Reload dengan relasi lengkap untuk response
            $pengajuan = PengajuanLembur::with([
                'jadwalKaryawan.karyawanProject.karyawan.divisi',
                'jadwalKaryawan.karyawanProject.karyawan.jabatan',
                'admin'
            ])->find($pengajuanId);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'id' => $pengajuan->id,
                    'status' => $pengajuan->status,
                    'status_text' => $pengajuan->status_text,
                    'catatan_admin' => $pengajuan->catatan_admin,
                    'diproses_pada' => $pengajuan->diproses_pada->format('Y-m-d H:i:s'),
                    'diproses_oleh' => $pengajuan->admin->username
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            
            // Log::error('Proses pengajuan lembur error: ' . $e->getMessage());
            // Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses pengajuan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ UPDATED: Get detail pengajuan lembur
     */
    public function show($pengajuanId)
    {
        try {
            $pengajuan = PengajuanLembur::with([
                'jadwalKaryawan.karyawanProject.karyawan.divisi',
                'jadwalKaryawan.karyawanProject.karyawan.jabatan',
                'admin'
            ])->findOrFail($pengajuanId);

            $karyawan = $pengajuan->jadwalKaryawan->karyawanProject->karyawan;

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $pengajuan->id,
                    'karyawan' => [
                        'id' => $karyawan->id,
                        'nik' => $karyawan->nik,
                        'nama' => $karyawan->nama,
                        'divisi' => $karyawan->divisi ? $karyawan->divisi->nama : '-',
                        'jabatan' => $karyawan->jabatan->nama
                    ],
                    'tanggal' => $pengajuan->tanggal->format('Y-m-d'),
                    'kode_hari' => $pengajuan->kode_hari,
                    'kode_hari_text' => $pengajuan->kode_hari_text,
                    'jam_mulai' => $pengajuan->jam_mulai,
                    'jam_selesai' => $pengajuan->jam_selesai,
                    'file_skl_url' => $pengajuan->file_skl_url,
                    'keterangan_karyawan' => $pengajuan->keterangan_karyawan,
                    'status' => $pengajuan->status,
                    'status_text' => $pengajuan->status_text,
                    'catatan_admin' => $pengajuan->catatan_admin,
                    'diproses_pada' => $pengajuan->diproses_pada?->format('Y-m-d H:i:s'),
                    'diproses_oleh' => $pengajuan->admin?->username,
                    'created_at' => $pengajuan->created_at->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            // Log::error('Get detail pengajuan lembur error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail pengajuan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Hapus pengajuan lembur (hanya untuk admin atau karyawan yang dibatalkan)
     */
    public function hapusPengajuan(Request $request, $pengajuanId)
    {
        DB::beginTransaction();
        try {
            $user = $request->user();
            $pengajuan = PengajuanLembur::with('jadwalKaryawan.karyawanProject')
                                        ->findOrFail($pengajuanId);

            // Cek apakah user adalah admin atau karyawan yang memiliki pengajuan
            $isOwner = isset($pengajuan->jadwalKaryawan->karyawanProject->karyawan_id) && 
                       $pengajuan->jadwalKaryawan->karyawanProject->karyawan_id === $user->id;
            $isAdmin = $user->role === 'admin';

            if (!$isOwner && !$isAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk menghapus pengajuan ini'
                ], 403);
            }

            // Hanya bisa hapus jika status dibatalkan atau ditolak
            if (!in_array($pengajuan->status, ['dibatalkan', 'ditolak'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya pengajuan yang dibatalkan atau ditolak yang dapat dihapus'
                ], 400);
            }

            // Hapus file SKL jika ada
            if ($pengajuan->file_skl && Storage::exists('public/' . $pengajuan->file_skl)) {
                Storage::delete('public/' . $pengajuan->file_skl);
            }

            // Hapus pengajuan
            $pengajuan->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan lembur berhasil dihapus'
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            
            // Log::error('Hapus pengajuan lembur error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus pengajuan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get summary pengajuan lembur untuk dashboard
     */
    public function getSummary($projectId, Request $request)
    {
        try {
            $query = PengajuanLembur::byProject($projectId);

            // Filter by date range if provided
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->betweenDates($request->start_date, $request->end_date);
            }

            $total = $query->count();
            $pending = (clone $query)->pending()->count();
            $disetujui = (clone $query)->disetujui()->count();
            $ditolak = (clone $query)->ditolak()->count();
            $dibatalkan = (clone $query)->dibatalkan()->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'pending' => $pending,
                    'disetujui' => $disetujui,
                    'ditolak' => $ditolak,
                    'dibatalkan' => $dibatalkan
                ]
            ]);

        } catch (\Exception $e) {
            // Log::error('Get summary pengajuan lembur error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat summary: ' . $e->getMessage()
            ], 500);
        }
    }

    // ========== MOBILE APP METHODS ==========

    /**
     * ✅ UPDATED: Get pengajuan lembur karyawan (untuk mobile)
     */
    public function getMyPengajuan(Request $request)
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

            $pengajuans = PengajuanLembur::with(['jadwalKaryawan', 'admin'])
                                         ->whereHas('jadwalKaryawan', function($q) use ($karyawanProject) {
                                             $q->where('karyawan_project_id', $karyawanProject->id);
                                         })
                                         ->orderBy('created_at', 'desc')
                                         ->get();

            $data = $pengajuans->map(function($pengajuan) {
                return [
                    'id' => $pengajuan->id,
                    'tanggal' => $pengajuan->tanggal->format('Y-m-d'),
                    'kode_hari' => $pengajuan->kode_hari,
                    'kode_hari_text' => $pengajuan->kode_hari_text,
                    'jam_mulai' => $pengajuan->jam_mulai,
                    'jam_selesai' => $pengajuan->jam_selesai,
                    'file_skl_url' => $pengajuan->file_skl_url,
                    'keterangan_karyawan' => $pengajuan->keterangan_karyawan,
                    'status' => $pengajuan->status ?? 'pending',
                    'status_text' => $pengajuan->status_text ?? '',
                    'catatan_admin' => $pengajuan->catatan_admin,
                    'diproses_pada' => $pengajuan->diproses_pada?->format('Y-m-d H:i:s'),
                    'diproses_oleh' => $pengajuan->admin?->username,
                    'created_at' => $pengajuan->created_at->format('Y-m-d H:i:s')
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data->values()->all()
            ]);

        } catch (\Exception $e) {
            // Log::error('Get my pengajuan lembur error: ' . $e->getMessage());
            // Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data pengajuan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ CRITICAL FIX: Ajukan lembur dengan validasi presensi hadir yang ketat
     */
    public function ajukanLembur(Request $request)
    {
        // ✅ Dynamic validation berdasarkan kode_hari
        $request->validate([
            'tanggal' => 'required|date',
            'file_skl' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'jam_mulai' => 'nullable|date_format:H:i',
            'jam_selesai' => 'nullable|date_format:H:i|after:jam_mulai',
            'keterangan_karyawan' => 'nullable|string|max:500'
        ], [
            'tanggal.required' => 'Tanggal lembur wajib diisi',
            'file_skl.required' => 'File SKL wajib diupload',
            'file_skl.mimes' => 'Format file harus PDF, JPG, JPEG, atau PNG',
            'file_skl.max' => 'Ukuran file maksimal 10MB',
            'jam_mulai.date_format' => 'Format jam mulai tidak valid (HH:mm)',
            'jam_selesai.date_format' => 'Format jam selesai tidak valid (HH:mm)',
            'jam_selesai.after' => 'Jam selesai harus setelah jam mulai'
        ]);

        DB::beginTransaction();
        try {
            $karyawan = $request->user();
            $karyawanProject = $karyawan->activeProject;
            
            if (!$karyawanProject) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda belum terdaftar di project manapun'
                ], 404);
            }

            $tanggal = $request->tanggal;
            
            // Validasi: Cek apakah tanggal ada di jadwal
            $jadwal = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
                                    ->whereDate('tanggal', $tanggal)
                                    ->first();

            if (!$jadwal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tanggal tidak ditemukan dalam jadwal Anda.'
                ], 404);
            }

            // ✅ CRITICAL: Tentukan kode_hari (K atau L)
            $shiftCode = strtoupper(trim($jadwal->shift_code));
            $kodeHari = ($shiftCode === 'L') ? 'L' : 'K';

            // ✅ CRITICAL FIX: Validasi PRESENSI HADIR untuk SEMUA kode_hari
            $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                     ->where('tipe', 'masuk')
                                     ->first();

            // ✅ VALIDASI UNIVERSAL: Harus ada presensi masuk dengan status HADIR
            if (!$presensiMasuk) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda belum melakukan presensi masuk untuk tanggal ini. Lakukan presensi terlebih dahulu.'
                ], 400);
            }

            // ✅ CRITICAL: Status presensi masuk harus HADIR (bukan libur, alpa, atau izin)
            $allowedStatusMasuk = ['hadir', 'terlambat'];
            
            if (!in_array($presensiMasuk->status, $allowedStatusMasuk)) {
                return response()->json([
                    'success' => false,
                    'message' => "Tidak dapat mengajukan lembur. Status presensi masuk Anda adalah '{$presensiMasuk->status}'. Hanya presensi dengan status 'hadir' atau 'terlambat' yang dapat mengajukan lembur."
                ], 400);
            }

            // ✅ Validasi khusus untuk hari libur (kode L)
            if ($kodeHari === 'L') {
                if (empty($request->jam_mulai) || empty($request->jam_selesai)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Untuk lembur di hari libur, jam mulai dan jam selesai wajib diisi'
                    ], 422);
                }
            } else {
                // ✅ Validasi untuk hari kerja (kode K)
                // Harus ada presensi pulang
                $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                          ->where('tipe', 'pulang')
                                          ->first();

                if (!$presensiPulang) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda belum melakukan presensi pulang untuk tanggal ini'
                    ], 400);
                }

                // ✅ CRITICAL: Validasi status presensi pulang
                // Hanya boleh mengajukan jika status: hadir, tidak_presensi_pulang, atau lembur_pending
                $allowedStatuses = ['hadir', 'tidak_presensi_pulang', 'lembur_pending'];
                
                if (!in_array($presensiPulang->status, $allowedStatuses)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Pengajuan lembur hanya dapat dilakukan jika presensi pulang berstatus hadir, lembur pending, atau tidak presensi pulang. Status saat ini: ' . $presensiPulang->status_text
                    ], 400);
                }
            }

            // Validasi: Cek apakah sudah ada pengajuan lembur untuk jadwal ini
            if (PengajuanLembur::sudahMengajukanLembur($jadwal->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda sudah memiliki pengajuan lembur untuk tanggal ini'
                ], 400);
            }

            // Upload file SKL (WAJIB)
            $storedPath = null;
            try {
                $file = $request->file('file_skl');
                $extension = $file->getClientOriginalExtension();
                $fileName = 'skl_' . $karyawan->id . '_' . time() . '.' . $extension;
                $path = 'lembur/' . date('Y/m');
                $storedPath = $file->storeAs($path, $fileName, 'public');
                // Log::info("File SKL uploaded successfully: " . $storedPath);
            } catch (\Exception $e) {
                // Log::error("File upload error: " . $e->getMessage());
                throw new \Exception("Gagal mengupload file: " . $e->getMessage());
            }

            // ✅ Buat pengajuan lembur dengan kode_hari
            $pengajuan = PengajuanLembur::create([
                'jadwal_karyawan_id' => $jadwal->id,
                'tanggal' => $tanggal,
                'kode_hari' => $kodeHari,
                'jam_mulai' => $request->jam_mulai,
                'jam_selesai' => $request->jam_selesai,
                'file_skl' => $storedPath,
                'keterangan_karyawan' => $request->keterangan_karyawan,
                'status' => 'pending'
            ]);

            // ✅ CRITICAL: Update presensi pulang menjadi lembur_pending (jika belum)
            if ($kodeHari === 'L') {
                // Untuk hari libur, update presensi pulang jika ada
                $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                          ->where('tipe', 'pulang')
                                          ->first();

                if ($presensiPulang && !in_array($presensiPulang->status, ['lembur_pending', 'lembur'])) {
                    $presensiPulang->update([
                        'status' => 'lembur_pending',
                        'keterangan' => "Pulang di hari libur - menunggu konfirmasi lembur (Jam kerja: {$request->jam_mulai} - {$request->jam_selesai})"
                    ]);
                }
            } else {
                // Untuk hari kerja, update jika status hadir/tidak_presensi_pulang
                $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                          ->where('tipe', 'pulang')
                                          ->first();

                if ($presensiPulang && !in_array($presensiPulang->status, ['lembur_pending', 'lembur'])) {
                    $keteranganLama = $presensiPulang->keterangan;
                    
                    $presensiPulang->update([
                        'status' => 'lembur_pending',
                        'keterangan' => 'Lembur pending - menunggu konfirmasi admin' . 
                                       ($keteranganLama ? " (Sebelumnya: {$keteranganLama})" : '')
                    ]);
                }
            }

            DB::commit();

            // Log::info("Pengajuan lembur created", [
            //     'id' => $pengajuan->id,
            //     'karyawan' => $karyawan->nama,
            //     'tanggal' => $tanggal,
            //     'kode_hari' => $kodeHari,
            //     'jam_mulai' => $request->jam_mulai,
            //     'jam_selesai' => $request->jam_selesai
            // ]);

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan lembur berhasil dikirim',
                'data' => [
                    'id' => $pengajuan->id,
                    'tanggal' => $pengajuan->tanggal->format('Y-m-d'),
                    'kode_hari' => $pengajuan->kode_hari,
                    'kode_hari_text' => $pengajuan->kode_hari_text,
                    'jam_mulai' => $pengajuan->jam_mulai,
                    'jam_selesai' => $pengajuan->jam_selesai,
                    'file_skl_url' => $pengajuan->file_skl_url,
                    'keterangan_karyawan' => $pengajuan->keterangan_karyawan,
                    'status' => $pengajuan->status,
                    'created_at' => $pengajuan->created_at->format('Y-m-d H:i:s')
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            
            if (isset($storedPath) && Storage::disk('public')->exists($storedPath)) {
                Storage::disk('public')->delete($storedPath);
            }

            // Log::error('Ajukan lembur error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengajukan lembur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Batalkan pengajuan lembur
     */
    public function batalkanPengajuan(Request $request, $pengajuanId)
    {
        DB::beginTransaction();
        try {
            $karyawan = $request->user();
            $pengajuan = PengajuanLembur::with('jadwalKaryawan.karyawanProject')
                                        ->findOrFail($pengajuanId);

            if ($pengajuan->jadwalKaryawan->karyawanProject->karyawan_id !== $karyawan->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengajuan ini bukan milik Anda'
                ], 403);
            }

            if ($pengajuan->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya pengajuan dengan status pending yang dapat dibatalkan'
                ], 400);
            }

            $pengajuan->batalkan();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan lembur berhasil dibatalkan'
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            
            // Log::error('Batalkan pengajuan lembur error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal membatalkan pengajuan: ' . $e->getMessage()
            ], 500);
        }
    }
}