<?php

namespace App\Http\Controllers;

use App\Models\PengajuanIzin;
use App\Models\JadwalKaryawan;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PengajuanIzinController extends Controller
{
    
    public function index(Request $request, $projectId)
    {
        try {
            $query = PengajuanIzin::with([
                'jadwalKaryawan.karyawanProject.karyawan.divisi',
                'jadwalKaryawan.karyawanProject.karyawan.jabatan',
                'admin'
            ])->byProject($projectId);

            
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            
            if ($request->has('kategori_izin') && $request->kategori_izin !== 'all') {
                $query->bykategori_izin($request->kategori_izin);
            }

            
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->betweenDates($request->start_date, $request->end_date);
            }

            
            if ($request->has('search')) {
                $search = $request->search;
                $query->whereHas('jadwalKaryawan.karyawanProject.karyawan', function($q) use ($search) {
                    $q->where('nama', 'like', '%' . $search . '%')
                      ->orWhere('nik', 'like', '%' . $search . '%');
                });
            }

            
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
                    'kategori_izin' => $pengajuan->kategori_izin,
                    'tanggal_mulai' => $pengajuan->tanggal_mulai->format('Y-m-d'),
                    'tanggal_selesai' => $pengajuan->tanggal_selesai->format('Y-m-d'),
                    'durasi_hari' => $pengajuan->durasi_hari,
                    'keterangan' => $pengajuan->keterangan,
                    'file_url' => $pengajuan->file_dokumen_url,
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
            
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data pengajuan izin: ' . $e->getMessage()
            ], 500);
        }
    }

    
    
public function prosesPengajuan(Request $request, $pengajuanId)
{
    $request->validate([
        'action' => 'required|in:setujui,tolak',
        'catatan' => 'nullable|string|max:500'
    ], [
        'action.required' => 'Action wajib dipilih',
        'action.in' => 'Action tidak valid'
    ]);
    
    try {
        $admin = $request->user();

        
        $pengajuan = PengajuanIzin::with([
            'jadwalKaryawan.karyawanProject.karyawan',
            'jadwalKaryawan.karyawanProject.project'
        ])->findOrFail($pengajuanId);

        
        if ($pengajuan->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Pengajuan ini sudah diproses sebelumnya dengan status: ' . $pengajuan->status_text
            ], 400);
        }

        $karyawan = $pengajuan->jadwalKaryawan->karyawanProject->karyawan;

        
        if ($request->action === 'setujui') {
            $result = $pengajuan->setujui($admin->id, $request->catatan);
            
            $message = "Pengajuan izin oleh {$karyawan->nama} telah disetujui.";
            
            
            $karyawan->refresh();
            
            
            if ($pengajuan->kategori_izin === PengajuanIzin::KATEGORI_CUTI_TAHUNAN) {
                $message .= " Sisa cuti tahunan: {$karyawan->sisa_cuti_tahunan} hari.";
            }
            
        } else {
            
            $pengajuan->tolak($admin->id, $request->catatan);
            
            $message = "Pengajuan izin oleh {$karyawan->nama} telah ditolak.";
        }

        
        $pengajuan = PengajuanIzin::with([
            'jadwalKaryawan.karyawanProject.karyawan.divisi',
            'jadwalKaryawan.karyawanProject.karyawan.jabatan',
            'admin'
        ])->find($pengajuanId);

        $responseData = [
            'id' => $pengajuan->id,
            'status' => $pengajuan->status,
            'status_text' => $pengajuan->status_text,
            'catatan_admin' => $pengajuan->catatan_admin,
            'diproses_pada' => $pengajuan->diproses_pada->format('Y-m-d H:i:s'),
            'diproses_oleh' => $pengajuan->admin->username
        ];

        
        if ($request->action === 'setujui' && $pengajuan->kategori_izin === PengajuanIzin::KATEGORI_CUTI_TAHUNAN) {
            $responseData['sisa_cuti_tahunan'] = $karyawan->sisa_cuti_tahunan;
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $responseData
        ]);
        
    } catch (\Exception $e) {
        
        

        
        

        return response()->json([
            'success' => false,
            'message' => 'Gagal memproses pengajuan: ' . $e->getMessage()
        ], 500);
    }
}


    
    public function show($pengajuanId)
    {
        try {
            $pengajuan = PengajuanIzin::with([
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
                    'kategori_izin' => $pengajuan->kategori_izin,
                    'tanggal_mulai' => $pengajuan->tanggal_mulai->format('Y-m-d'),
                    'tanggal_selesai' => $pengajuan->tanggal_selesai->format('Y-m-d'),
                    'durasi_hari' => $pengajuan->durasi_hari,
                    'keterangan' => $pengajuan->keterangan,
                    'file_url' => $pengajuan->file_dokumen_url,
                    'status' => $pengajuan->status,
                    'status_text' => $pengajuan->status_text,
                    'catatan_admin' => $pengajuan->catatan_admin,
                    'diproses_pada' => $pengajuan->diproses_pada?->format('Y-m-d H:i:s'),
                    'diproses_oleh' => $pengajuan->admin?->username,
                    'created_at' => $pengajuan->created_at->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail pengajuan: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function hapusPengajuan(Request $request, $pengajuanId)
    {
        DB::beginTransaction();
        try {
            $user = $request->user();
            $pengajuan = PengajuanIzin::with('jadwalKaryawan.karyawanProject')
                                      ->findOrFail($pengajuanId);

            
            $isOwner = isset($pengajuan->jadwalKaryawan->karyawanProject->karyawan_id) && 
                       $pengajuan->jadwalKaryawan->karyawanProject->karyawan_id === $user->id;
            $isAdmin = $user->role === 'admin';

            if (!$isOwner && !$isAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk menghapus pengajuan ini'
                ], 403);
            }

            
            if (!in_array($pengajuan->status, ['dibatalkan', 'ditolak'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya pengajuan yang dibatalkan atau ditolak yang dapat dihapus'
                ], 400);
            }

            
            if ($pengajuan->file_dokumen && Storage::exists('public/' . $pengajuan->file_dokumen)) {
                Storage::delete('public/' . $pengajuan->file_dokumen);
            }

            
            $pengajuan->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan izin berhasil dihapus'
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus pengajuan: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function getSummary($projectId, Request $request)
    {
        try {
            $query = PengajuanIzin::byProject($projectId);

            
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->betweenDates($request->start_date, $request->end_date);
            }

            $total = $query->count();
            $pending = (clone $query)->pending()->count();
            $disetujui = (clone $query)->disetujui()->count();
            $ditolak = (clone $query)->ditolak()->count();
            $dibatalkan = (clone $query)->dibatalkan()->count();

            
            $bykategori_izin = (clone $query)->select('kategori_izin', DB::raw('count(*) as total'))
                                         ->groupBy('kategori_izin')
                                         ->get()
                                         ->pluck('total', 'kategori_izin');

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'pending' => $pending,
                    'disetujui' => $disetujui,
                    'ditolak' => $ditolak,
                    'dibatalkan' => $dibatalkan,
                    'by_kategori_izin' => $bykategori_izin
                ]
            ]);

        } catch (\Exception $e) {
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat summary: ' . $e->getMessage()
            ], 500);
        }
    }

    

    
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

            $pengajuans = PengajuanIzin::with(['jadwalKaryawan', 'admin'])
                                       ->whereHas('jadwalKaryawan', function($q) use ($karyawanProject) {
                                           $q->where('karyawan_project_id', $karyawanProject->id);
                                       })
                                       ->orderBy('created_at', 'desc')
                                       ->get();

            $data = $pengajuans->map(function($pengajuan) {
                return [
                    'id' => $pengajuan->id,
                    'kategori_izin' => $pengajuan->kategori_izin ?? '',
                    'tanggal_mulai' => $pengajuan->tanggal_mulai->format('Y-m-d'),
                    'tanggal_selesai' => $pengajuan->tanggal_selesai->format('Y-m-d'),
                    'durasi_hari' => $pengajuan->durasi_hari,
                    'keterangan' => $pengajuan->keterangan,
                    'file_url' => $pengajuan->file_dokumen_url,
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
            
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data pengajuan: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function ajukanIzin(Request $request)
{
    $request->validate([
        'kategori_izin' => 'required|in:sakit,izin,cuti_tahunan,cuti_khusus',
        'sub_kategori_izin' => 'required_if:kategori_izin,cuti_khusus|in:pernikahan_karyawan,pernikahan_anak,istri_melahirkan,kematian_keluarga,kematian_serumah,khitanan_baptis',
        'tanggal_mulai' => 'required|date',
        'tanggal_selesai' => 'nullable|date|after_or_equal:tanggal_mulai',
        'deskripsi_izin' => 'nullable|string|max:255',
        'keterangan' => 'nullable|string|max:1000',
        'file_dokumen' => 'required_if:kategori_izin,sakit|nullable|file|mimes:pdf,jpg,jpeg,png|max:10240'
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

        $project = $karyawanProject->project;
        $kategoriIzin = $request->kategori_izin;
        $subKategoriIzin = $request->sub_kategori_izin;

        
        if (!$project->isKategoriIzinEnabled($kategoriIzin)) {
            $enabledCategories = $project->getEnabledKategoriIzin();
            $categoryLabels = array_map(function($cat) {
                return ucwords(str_replace('_', ' ', $cat));
            }, $enabledCategories);
            
            return response()->json([
                'success' => false,
                'message' => 'Kategori izin "' . ucwords(str_replace('_', ' ', $kategoriIzin)) . 
                             '" tidak diaktifkan untuk project ini. Kategori yang tersedia: ' . 
                             implode(', ', $categoryLabels)
            ], 422);
        }

        
        if ($kategoriIzin === PengajuanIzin::KATEGORI_CUTI_KHUSUS) {
            if (!$subKategoriIzin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sub kategori cuti khusus wajib dipilih'
                ], 422);
            }

            if (!$project->isSubKategoriEnabled($subKategoriIzin)) {
                $enabledSubCategories = $project->getEnabledSubKategoriIzin();
                $subCategoryLabels = array_map(function($subCat) {
                    return PengajuanIzin::getSubKategoriLabel($subCat);
                }, $enabledSubCategories);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Sub kategori cuti khusus "' . 
                                 PengajuanIzin::getSubKategoriLabel($subKategoriIzin) . 
                                 '" tidak diaktifkan untuk project ini. Sub kategori yang tersedia: ' . 
                                 implode(', ', $subCategoryLabels)
                ], 422);
            }
        }

        $tanggalMulai = $request->tanggal_mulai;
        
        $durasiOtomatis = null;
        $tanggalSelesai = $request->tanggal_selesai;
        
        if ($kategoriIzin === PengajuanIzin::KATEGORI_CUTI_KHUSUS) {
            $durasiOtomatis = PengajuanIzin::getDurasiCutiKhusus($subKategoriIzin);
            
            $tanggalMulaiCarbon = Carbon::parse($tanggalMulai);
            $tanggalSelesai = $tanggalMulaiCarbon->copy()->addDays($durasiOtomatis - 1)->format('Y-m-d');
        } else {
            if (!$tanggalSelesai) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tanggal selesai wajib diisi'
                ], 422);
            }
        }
        
        if ($kategoriIzin === PengajuanIzin::KATEGORI_CUTI_TAHUNAN) {
            $durasiHari = Carbon::parse($tanggalMulai)->diffInDays(Carbon::parse($tanggalSelesai)) + 1;
            
            if ($durasiHari > $karyawan->sisa_cuti_tahunan) {
                return response()->json([
                    'success' => false,
                    'message' => "Sisa cuti tahunan Anda tidak mencukupi. Sisa: {$karyawan->sisa_cuti_tahunan} hari, Diminta: {$durasiHari} hari"
                ], 400);
            }
        }

        $jadwalMulai = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
                                     ->whereDate('tanggal', $tanggalMulai)
                                     ->first();

        if (!$jadwalMulai) {
            return response()->json([
                'success' => false,
                'message' => 'Tanggal mulai tidak ditemukan dalam jadwal Anda.'
            ], 404);
        }

        if (PengajuanIzin::sudahMengajukanPeriode(
            $karyawanProject->id, 
            $tanggalMulai, 
            $tanggalSelesai
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah memiliki pengajuan izin untuk periode yang sama atau bertumpang tindih'
            ], 400);
        }

        $storedPath = null;
        if ($request->hasFile('file_dokumen')) {
            try {
                $file = $request->file('file_dokumen');
                $extension = $file->getClientOriginalExtension();
                $fileName = 'izin_' . $karyawan->id . '_' . time() . '.' . $extension;
                $path = 'izin/' . date('Y/m');
                $storedPath = $file->storeAs($path, $fileName, 'public');
                
            } catch (\Exception $e) {
                
                throw new \Exception("Gagal mengupload file: " . $e->getMessage());
            }
        }
        
        $deskripsiIzin = $request->deskripsi_izin;
        if (empty($deskripsiIzin)) {
            $deskripsiIzin = ucwords(str_replace('_', ' ', $kategoriIzin));
            if ($subKategoriIzin) {
                $deskripsiIzin .= ' - ' . PengajuanIzin::getSubKategoriLabel($subKategoriIzin);
            }
        }

        $pengajuan = PengajuanIzin::create([
            'jadwal_karyawan_id' => $jadwalMulai->id,
            'kategori_izin' => $kategoriIzin,
            'sub_kategori_izin' => $subKategoriIzin,
            'deskripsi_izin' => $deskripsiIzin,
            'durasi_otomatis' => $durasiOtomatis,
            'tanggal_mulai' => $tanggalMulai,
            'tanggal_selesai' => $tanggalSelesai,
            'file_dokumen' => $storedPath,
            'keterangan' => $request->keterangan,
            'status' => 'pending'
        ]);

        DB::commit();

        
        
        
        
        
        

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan izin berhasil dikirim',
            'data' => [
                'id' => $pengajuan->id,
                'kategori_izin' => $pengajuan->kategori_izin,
                'sub_kategori_izin' => $pengajuan->sub_kategori_izin,
                'deskripsi_izin' => $pengajuan->deskripsi_izin,
                'durasi_otomatis' => $pengajuan->durasi_otomatis,
                'tanggal_mulai' => $pengajuan->tanggal_mulai->format('Y-m-d'),
                'tanggal_selesai' => $pengajuan->tanggal_selesai->format('Y-m-d'),
                'durasi_hari' => $pengajuan->durasi_hari,
                'status' => $pengajuan->status,
                'created_at' => $pengajuan->created_at->format('Y-m-d H:i:s')
            ]
        ], 201);

    } catch (\Exception $e) {
        DB::rollback();
        
        if (isset($storedPath) && Storage::disk('public')->exists($storedPath)) {
            Storage::disk('public')->delete($storedPath);
        }

        
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengajukan izin: ' . $e->getMessage()
        ], 500);
    }
}


    
    public function batalkanPengajuan(Request $request, $pengajuanId)
    {
        DB::beginTransaction();
        try {
            $karyawan = $request->user();
            $pengajuan = PengajuanIzin::with('jadwalKaryawan.karyawanProject')
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
                'message' => 'Pengajuan izin berhasil dibatalkan'
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal membatalkan pengajuan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getKategoriIzinList(Request $request)
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

        $project = $karyawanProject->project;
        $enabledCategories = $project->getEnabledKategoriIzin();
        
        $kategoriList = [
            [
                'value' => PengajuanIzin::KATEGORI_SAKIT,
                'label' => 'Sakit',
                'kode' => 'S',
                'has_sub_kategori' => false,
                'butuh_dokumen' => true,
                'deskripsi' => 'Izin karena sakit (wajib lampirkan surat keterangan dokter)',
                'enabled' => in_array(PengajuanIzin::KATEGORI_SAKIT, $enabledCategories)
            ],
            [
                'value' => PengajuanIzin::KATEGORI_IZIN,
                'label' => 'Izin',
                'kode' => 'I',
                'has_sub_kategori' => false,
                'butuh_dokumen' => false,
                'deskripsi' => 'Izin umum (urusan pribadi)',
                'enabled' => in_array(PengajuanIzin::KATEGORI_IZIN, $enabledCategories)
            ],
            [
                'value' => PengajuanIzin::KATEGORI_CUTI_TAHUNAN,
                'label' => 'Cuti Tahunan',
                'kode' => 'CT',
                'has_sub_kategori' => false,
                'butuh_dokumen' => false,
                'max_hari' => 12,
                'sisa_cuti' => $karyawan->sisa_cuti_tahunan ?? 0,
                'deskripsi' => "Cuti tahunan (sisa: {$karyawan->sisa_cuti_tahunan} hari)",
                'enabled' => in_array(PengajuanIzin::KATEGORI_CUTI_TAHUNAN, $enabledCategories)
            ],
            [
                'value' => PengajuanIzin::KATEGORI_CUTI_KHUSUS,
                'label' => 'Cuti Izin Khusus',
                'kode' => 'IK',
                'has_sub_kategori' => true,
                'butuh_dokumen' => false,
                'deskripsi' => 'Cuti khusus untuk acara penting',
                'enabled' => in_array(PengajuanIzin::KATEGORI_CUTI_KHUSUS, $enabledCategories)
            ]
        ];
        
        
        $enabledKategoriList = array_filter($kategoriList, function($item) use ($enabledCategories) {
            return in_array($item['value'], $enabledCategories);
        });
        
        return response()->json([
            'success' => true,
            'data' => array_values($enabledKategoriList)
        ]);

    } catch (\Exception $e) {
        
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal memuat kategori izin: ' . $e->getMessage()
        ], 500);
    }
}


public function getSubKategoriCutiKhususList(Request $request)
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

        $project = $karyawanProject->project;
        $enabledSubCategories = $project->getEnabledSubKategoriIzin();
        
        $allSubKategoriList = [
            [
                'value' => PengajuanIzin::SUB_PERNIKAHAN_KARYAWAN,
                'label' => 'Pernikahan Karyawan',
                'durasi_hari' => 3,
                'deskripsi' => 'Pernikahan karyawan sendiri (3 hari)',
                'enabled' => in_array(PengajuanIzin::SUB_PERNIKAHAN_KARYAWAN, $enabledSubCategories)
            ],
            [
                'value' => PengajuanIzin::SUB_PERNIKAHAN_ANAK,
                'label' => 'Pernikahan Putra/Putri',
                'durasi_hari' => 2,
                'deskripsi' => 'Pernikahan anak karyawan (2 hari)',
                'enabled' => in_array(PengajuanIzin::SUB_PERNIKAHAN_ANAK, $enabledSubCategories)
            ],
            [
                'value' => PengajuanIzin::SUB_ISTRI_MELAHIRKAN,
                'label' => 'Istri Melahirkan/Keguguran',
                'durasi_hari' => 2,
                'deskripsi' => 'Istri melahirkan atau keguguran (2 hari)',
                'enabled' => in_array(PengajuanIzin::SUB_ISTRI_MELAHIRKAN, $enabledSubCategories)
            ],
            [
                'value' => PengajuanIzin::SUB_KEMATIAN_KELUARGA,
                'label' => 'Kematian Keluarga Inti',
                'durasi_hari' => 2,
                'deskripsi' => 'Kematian suami/istri/anak/orang tua/mertua (2 hari)',
                'enabled' => in_array(PengajuanIzin::SUB_KEMATIAN_KELUARGA, $enabledSubCategories)
            ],
            [
                'value' => PengajuanIzin::SUB_KEMATIAN_SERUMAH,
                'label' => 'Kematian Orang Serumah',
                'durasi_hari' => 1,
                'deskripsi' => 'Kematian orang yang tinggal serumah (1 hari)',
                'enabled' => in_array(PengajuanIzin::SUB_KEMATIAN_SERUMAH, $enabledSubCategories)
            ],
            [
                'value' => PengajuanIzin::SUB_KHITANAN_BAPTIS,
                'label' => 'Khitanan/Baptisan Anak',
                'durasi_hari' => 2,
                'deskripsi' => 'Khitanan atau baptisan anak karyawan (2 hari)',
                'enabled' => in_array(PengajuanIzin::SUB_KHITANAN_BAPTIS, $enabledSubCategories)
            ]
        ];
        
        
        $enabledSubKategoriList = array_filter($allSubKategoriList, function($item) use ($enabledSubCategories) {
            return in_array($item['value'], $enabledSubCategories);
        });
        
        return response()->json([
            'success' => true,
            'data' => array_values($enabledSubKategoriList)
        ]);

    } catch (\Exception $e) {
        
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal memuat sub kategori: ' . $e->getMessage()
        ], 500);
    }
}


public function hitungTanggalSelesai(Request $request)
{
    $request->validate([
        'tanggal_mulai' => 'required|date',
        'sub_kategori_izin' => 'required|in:pernikahan_karyawan,pernikahan_anak,istri_melahirkan,kematian_keluarga,kematian_serumah,khitanan_baptis'
    ]);
    
    try {
        $tanggalMulai = Carbon::parse($request->tanggal_mulai);
        $durasiHari = PengajuanIzin::getDurasiCutiKhusus($request->sub_kategori_izin);
        $tanggalSelesai = $tanggalMulai->copy()->addDays($durasiHari - 1);
        
        return response()->json([
            'success' => true,
            'data' => [
                'tanggal_mulai' => $tanggalMulai->format('Y-m-d'),
                'tanggal_selesai' => $tanggalSelesai->format('Y-m-d'),
                'durasi_hari' => $durasiHari
            ]
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Gagal menghitung tanggal: ' . $e->getMessage()
        ], 500);
    }
}
}