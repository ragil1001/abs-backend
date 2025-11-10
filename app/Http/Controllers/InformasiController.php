<?php

namespace App\Http\Controllers;

use App\Models\Informasi;
use App\Models\InformasiKaryawan;
use App\Models\Karyawan;
use App\Models\Divisi;
use App\Models\Jabatan;
use App\Models\Project;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class InformasiController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    
    public function index(Request $request)
    {
        try {
            $query = Informasi::with(['user:id,username'])
                              ->withCount('informasiKaryawan as total_penerima')
                              ->withCount(['informasiKaryawan as total_dibaca' => function($q) {
                                  $q->where('is_read', true);
                              }]);

            
            if ($request->filled('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            
            if ($request->filled('target_type') && $request->target_type !== 'all') {
                $query->where('target_type', $request->target_type);
            }

            
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('judul', 'ILIKE', '%' . $search . '%')
                      ->orWhere('konten', 'ILIKE', '%' . $search . '%');
                });
            }

            
            $sortField = $request->input('sort_field', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);

            $perPage = min((int)$request->input('per_page', 15), 100);
            $result = $query->paginate($perPage);

            $data = $result->map(function($informasi) {
                return [
                    'id' => $informasi->id,
                    'judul' => $informasi->judul,
                    'konten' => $informasi->konten,
                    'file_name' => $informasi->file_name,
                    'file_type' => $informasi->file_type,
                    'file_url' => $informasi->file_url,
                    'file_size_formatted' => $informasi->file_size_formatted,
                    'target_type' => $informasi->target_type,
                    'target_names' => $informasi->target_names,
                    'total_penerima' => $informasi->total_penerima,
                    'total_dibaca' => $informasi->total_dibaca,
                    'persentase_dibaca' => $informasi->persentase_dibaca,
                    'status' => $informasi->status,
                    'dikirim_at' => $informasi->dikirim_at?->format('Y-m-d H:i:s'),
                    'time_ago' => $informasi->time_ago,
                    'created_by' => $informasi->user->username ?? 'System',
                    'created_at' => $informasi->created_at->format('Y-m-d H:i:s')
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
                'message' => 'Gagal memuat data informasi: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function show($informasiId)
    {
        try {
            $informasi = Informasi::with(['user:id,username'])
                                  ->withCount('informasiKaryawan as total_penerima')
                                  ->withCount(['informasiKaryawan as total_dibaca' => function($q) {
                                      $q->where('is_read', true);
                                  }])
                                  ->findOrFail($informasiId);

            $data = [
                'id' => $informasi->id,
                'judul' => $informasi->judul,
                'konten' => $informasi->konten,
                'file_path' => $informasi->file_path,
                'file_url' => $informasi->file_url,
                'file_name' => $informasi->file_name,
                'file_type' => $informasi->file_type,
                'file_size' => $informasi->file_size,
                'file_size_formatted' => $informasi->file_size_formatted,
                'target_type' => $informasi->target_type,
                'target_ids' => $informasi->target_ids,
                'target_names' => $informasi->target_names,
                'total_penerima' => $informasi->total_penerima,
                'total_dibaca' => $informasi->total_dibaca,
                'persentase_dibaca' => $informasi->persentase_dibaca,
                'status' => $informasi->status,
                'dikirim_at' => $informasi->dikirim_at?->format('Y-m-d H:i:s'),
                'created_by' => $informasi->user->username ?? 'System',
                'created_at' => $informasi->created_at->format('Y-m-d H:i:s')
            ];

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Informasi tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail informasi: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function store(Request $request)
    {
        $request->validate([
            'judul' => 'required|string|max:255',
            'konten' => 'required|string',
            'file' => 'nullable|file|max:10240', 
            'target_type' => 'required|in:semua,divisi,jabatan,project,karyawan',
            'target_ids' => 'required_unless:target_type,semua|array',
            'target_ids.*' => 'integer'
        ], [
            'judul.required' => 'Judul wajib diisi',
            'konten.required' => 'Konten wajib diisi',
            'file.max' => 'Ukuran file maksimal 10MB',
            'target_type.required' => 'Target penerima wajib dipilih',
            'target_ids.required_unless' => 'Pilih minimal 1 penerima'
        ]);

        try {
            DB::beginTransaction();

            $user = $request->user();

            $data = [
                'user_id' => $user->id,
                'judul' => $request->judul,
                'konten' => $request->konten,
                'target_type' => $request->target_type,
                'target_ids' => $request->target_type === 'semua' ? null : $request->target_ids,
                'status' => 'draft'
            ];

            
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $fileName = time() . '_' . $file->getClientOriginalName();
                $filePath = $file->storeAs('informasi', $fileName, 'public');
                
                $data['file_path'] = $filePath;
                $data['file_name'] = $file->getClientOriginalName();
                $data['file_type'] = $file->getClientMimeType();
                $data['file_size'] = $file->getSize();
            }

            $informasi = Informasi::create($data);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Draft informasi berhasil disimpan',
                'data' => $informasi->load('user')
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan informasi: ' . $e->getMessage()
            ], 500);
        }
    }

    
public function update(Request $request, $informasiId)
{
    
    $validator = \Validator::make($request->all(), [
        'judul' => 'required|string|max:255',
        'konten' => 'required|string',
        'file' => 'nullable|file|max:10240',
        'target_type' => 'required|in:semua,divisi,jabatan,project,karyawan',
        'target_ids' => 'required_unless:target_type,semua|array',
        'target_ids.*' => 'integer',
        'delete_file' => 'nullable|boolean'
    ], [
        'judul.required' => 'Judul wajib diisi',
        'konten.required' => 'Konten wajib diisi',
        'file.max' => 'Ukuran file maksimal 10MB',
        'target_type.required' => 'Target penerima wajib dipilih',
        'target_ids.required_unless' => 'Pilih minimal 1 penerima',
        'target_ids.array' => 'Format target tidak valid'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Data yang dimasukkan tidak valid',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        DB::beginTransaction();

        $informasi = Informasi::findOrFail($informasiId);

        
        if ($informasi->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya informasi dengan status draft yang dapat diubah'
            ], 422);
        }

        $data = [
            'judul' => $request->judul,
            'konten' => $request->konten,
            'target_type' => $request->target_type,
            'target_ids' => $request->target_type === 'semua' ? null : $request->target_ids
        ];

        
        if ($request->delete_file && $informasi->file_path) {
            Storage::disk('public')->delete($informasi->file_path);
            $data['file_path'] = null;
            $data['file_name'] = null;
            $data['file_type'] = null;
            $data['file_size'] = null;
        }

        
        if ($request->hasFile('file')) {
            
            if ($informasi->file_path) {
                Storage::disk('public')->delete($informasi->file_path);
            }

            $file = $request->file('file');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $filePath = $file->storeAs('informasi', $fileName, 'public');
            
            $data['file_path'] = $filePath;
            $data['file_name'] = $file->getClientOriginalName();
            $data['file_type'] = $file->getClientMimeType();
            $data['file_size'] = $file->getSize();
        }

        $informasi->update($data);

        DB::commit();

        
        $informasi = $informasi->fresh([
            'user:id,username'
        ]);

        
        $responseData = [
            'id' => $informasi->id,
            'judul' => $informasi->judul,
            'konten' => $informasi->konten,
            'file_name' => $informasi->file_name,
            'file_type' => $informasi->file_type,
            'file_url' => $informasi->file_url,
            'file_size_formatted' => $informasi->file_size_formatted,
            'target_type' => $informasi->target_type,
            'target_ids' => $informasi->target_ids,
            'target_names' => $informasi->target_names,
            'status' => $informasi->status,
            'created_by' => $informasi->user->username ?? 'System',
            'created_at' => $informasi->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $informasi->updated_at->format('Y-m-d H:i:s')
        ];

        return response()->json([
            'success' => true,
            'message' => 'Informasi berhasil diperbarui',
            'data' => $responseData
        ]);

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        DB::rollback();
        return response()->json([
            'success' => false,
            'message' => 'Informasi tidak ditemukan'
        ], 404);
    } catch (\Exception $e) {
        DB::rollback();
        
        
        
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal memperbarui informasi: ' . $e->getMessage()
        ], 500);
    }
}

    
    public function send($informasiId)
    {
        try {
            DB::beginTransaction();

            $informasi = Informasi::findOrFail($informasiId);

            
            if ($informasi->status === 'terkirim') {
                return response()->json([
                    'success' => false,
                    'message' => 'Informasi sudah pernah dikirim'
                ], 422);
            }

            
            $karyawanIds = $informasi->getTargetKaryawanIds();

            if (empty($karyawanIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada karyawan yang menjadi target'
                ], 422);
            }

            
            $informasiKaryawanData = [];
            foreach ($karyawanIds as $karyawanId) {
                $informasiKaryawanData[] = [
                    'informasi_id' => $informasi->id,
                    'karyawan_id' => $karyawanId,
                    'is_read' => false,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }

            InformasiKaryawan::insert($informasiKaryawanData);

            
            $informasi->kirim();
            $informasi->update(['total_penerima' => count($karyawanIds)]);

            
            foreach ($karyawanIds as $karyawanId) {
                $this->notificationService->notifyKaryawanNewInformasi($informasi, $karyawanId);
            }

            DB::commit();

            
            
            
            

            return response()->json([
                'success' => true,
                'message' => 'Informasi berhasil dikirim ke ' . count($karyawanIds) . ' karyawan',
                'data' => [
                    'informasi_id' => $informasi->id,
                    'total_penerima' => count($karyawanIds)
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Informasi tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            DB::rollback();
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim informasi: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function destroy($informasiId)
    {
        try {
            DB::beginTransaction();

            $informasi = Informasi::findOrFail($informasiId);

            
            if ($informasi->status !== 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya informasi dengan status draft yang dapat dihapus'
                ], 422);
            }

            
            if ($informasi->file_path) {
                Storage::disk('public')->delete($informasi->file_path);
            }

            $informasi->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Informasi berhasil dihapus'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Informasi tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            DB::rollback();
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus informasi: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function getPenerima($informasiId, Request $request)
    {
        try {
            $informasi = Informasi::findOrFail($informasiId);

            $query = InformasiKaryawan::with(['karyawan.divisi', 'karyawan.jabatan'])
                                      ->where('informasi_id', $informasi->id);

            
            if ($request->filled('is_read') && $request->is_read !== 'all') {
                $isRead = $request->is_read === 'true' || $request->is_read === '1';
                $query->where('is_read', $isRead);
            }

            
            if ($request->filled('search')) {
                $search = $request->search;
                $query->whereHas('karyawan', function($q) use ($search) {
                    $q->where('nama', 'ILIKE', '%' . $search . '%')
                      ->orWhere('nik', 'LIKE', $search . '%');
                });
            }

            $perPage = min((int)$request->input('per_page', 20), 100);
            $result = $query->latest()->paginate($perPage);

            $data = $result->map(function($infKaryawan) {
                return [
                    'id' => $infKaryawan->id,
                    'karyawan' => [
                        'id' => $infKaryawan->karyawan->id,
                        'nik' => $infKaryawan->karyawan->nik,
                        'nama' => $infKaryawan->karyawan->nama,
                        'divisi' => $infKaryawan->karyawan->divisi->nama ?? '-',
                        'jabatan' => $infKaryawan->karyawan->jabatan->nama ?? '-'
                    ],
                    'is_read' => $infKaryawan->is_read,
                    'read_at' => $infKaryawan->read_at?->format('Y-m-d H:i:s'),
                    'created_at' => $infKaryawan->created_at->format('Y-m-d H:i:s')
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

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Informasi tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data penerima: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function getTargetOptions(Request $request)
    {
        try {
            $type = $request->input('type'); 

            $data = [];

            switch ($type) {
                case 'divisi':
                    $data = Divisi::select('id', 'nama')
                                  ->orderBy('nama')
                                  ->get()
                                  ->map(fn($item) => [
                                      'value' => $item->id,
                                      'label' => $item->nama
                                  ]);
                    break;

                case 'jabatan':
                    $data = Jabatan::select('id', 'nama')
                                   ->orderBy('nama')
                                   ->get()
                                   ->map(fn($item) => [
                                       'value' => $item->id,
                                       'label' => $item->nama
                                   ]);
                    break;

                case 'project':
    $data = Project::select('id', 'nama')
                   ->orderBy('nama')
                   ->get()
                   ->map(fn($item) => [
                       'value' => $item->id,
                       'label' => $item->nama
                   ]);
    break;

                case 'karyawan':
                    $data = Karyawan::select('id', 'nik', 'nama')
                                    ->where('status', 'aktif')
                                    ->orderBy('nama')
                                    ->get()
                                    ->map(fn($item) => [
                                        'value' => $item->id,
                                        'label' => "{$item->nama} ({$item->nik})"
                                    ]);
                    break;
            }

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data target: ' . $e->getMessage()
            ], 500);
        }
    }
}