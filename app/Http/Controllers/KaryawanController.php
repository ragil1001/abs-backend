<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\Divisi;
use App\Models\Jabatan;
use App\Models\KaryawanProject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\KaryawanImport;
use App\Exports\KaryawanExport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class KaryawanController extends Controller
{
    public function index(Request $request)
    {
        try {
            // Base query
            $karyawans = Karyawan::select([
                'karyawans.id',
                'karyawans.nik',
                'karyawans.nama',
                'karyawans.no_telepon',
                'karyawans.divisi_id',
                'karyawans.jabatan_id',
                'karyawans.jenis_kelamin',
                'karyawans.tempat_lahir',
                'karyawans.tanggal_lahir',
                'karyawans.tanggal_bergabung',
                'karyawans.tanggal_keluar',
                'karyawans.sisa_cuti_tahunan',
                'karyawans.status',
                'karyawans.username'
            ])
                ->with([
                    'divisi:id,nama',
                    'jabatan:id,nama',
                    'activeProject.project:id,nama'
                ]);

            // FIXED: Search filter with proper case-insensitive matching
            if ($request->filled('search')) {
                $search = trim($request->search);

                $karyawans->where(function ($q) use ($search) {
                    // Search by nama (case-insensitive)
                    $q->whereRaw('LOWER(nama) LIKE ?', ['%' . strtolower($search) . '%'])
                        // Search by NIK (exact or starts with)
                        ->orWhere('nik', 'LIKE', $search . '%')
                        // Search by ID (exact match)
                        ->orWhere('id', '=', $search);
                });
            }

            // Status filter
            if ($request->filled('status') && $request->status !== 'all') {
                $karyawans->where('status', $request->status);
            }

            // Project filter
            if ($request->filled('project_id') && $request->project_id !== 'all') {
                if ($request->project_id === 'unassigned') {
                    // Karyawan belum ada project
                    $karyawans->whereDoesntHave('activeProject');
                } else {
                    // Karyawan di project tertentu
                    $karyawans->whereHas('activeProject', function ($q) use ($request) {
                        $q->where('project_id', $request->project_id);
                    });
                }
            }

            // Jabatan filter
            if ($request->filled('jabatan_id') && $request->jabatan_id !== 'all') {
                $karyawans->where('jabatan_id', $request->jabatan_id);
            }

            // Jenis kelamin filter
            if ($request->filled('jenis_kelamin') && $request->jenis_kelamin !== 'all') {
                $karyawans->where('jenis_kelamin', $request->jenis_kelamin);
            }

            // Sorting
            $sortField = $request->input('sort_field', 'id');
            $sortDirection = $request->input('sort_direction', 'asc');

            if ($sortField === 'divisi') {
                $karyawans->leftJoin('divisis', 'karyawans.divisi_id', '=', 'divisis.id')
                    ->orderBy('divisis.nama', $sortDirection)
                    ->select('karyawans.*');
            } elseif ($sortField === 'jabatan') {
                $karyawans->join('jabatans', 'karyawans.jabatan_id', '=', 'jabatans.id')
                    ->orderBy('jabatans.nama', $sortDirection)
                    ->select('karyawans.*');
            } elseif ($sortField === 'project') {
                $karyawans->leftJoin('karyawan_projects as kp', function ($join) {
                    $join->on('karyawans.id', '=', 'kp.karyawan_id')
                        ->where('kp.status', '=', 'aktif');
                })
                    ->leftJoin('projects', 'kp.project_id', '=', 'projects.id')
                    ->orderBy('projects.nama', $sortDirection)
                    ->select('karyawans.*');
            } else {
                $karyawans->orderBy('karyawans.' . $sortField, $sortDirection);
            }

            // Pagination
            $perPage = min((int)$request->input('per_page', 10), 100);
            $result = $karyawans->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $result->items(),
                'pagination' => [
                    'current_page' => $result->currentPage(),
                    'per_page' => $result->perPage(),
                    'total' => $result->total(),
                    'last_page' => $result->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Karyawan index error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data karyawan'
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'nik' => 'required|string|max:20|unique:karyawans,nik',
            'no_telepon' => 'required|string|max:15',
            'nama' => 'required|string|max:255',
            'divisi_id' => 'nullable|exists:divisis,id', // âœ… CHANGED: nullable
            'jabatan_id' => 'required|exists:jabatans,id',
            'jenis_kelamin' => 'required|in:L,P',
            'tempat_lahir' => 'required|string|max:255',
            'tanggal_lahir' => 'required|date|before:today',
            'tanggal_bergabung' => 'required|date|before_or_equal:today',
            'tanggal_keluar' => 'nullable|date|after:tanggal_bergabung',
            'status' => 'required|in:aktif,tidak_aktif',
            'sisa_cuti_tahunan' => 'nullable|integer|min:0|max:12'
        ], [
            'nik.required' => 'NIK wajib diisi',
            'nik.unique' => 'NIK sudah terdaftar',
            'nama.required' => 'Nama wajib diisi',
            'no_telepon.required' => 'Nomor telepon wajib diisi',
            'divisi_id.exists' => 'Divisi tidak valid', // âœ… UPDATED: removed required message
            'jabatan_id.required' => 'Jabatan wajib dipilih',
            'jabatan_id.exists' => 'Jabatan tidak valid',
            'jenis_kelamin.required' => 'Jenis kelamin wajib dipilih',
            'jenis_kelamin.in' => 'Jenis kelamin tidak valid',
            'tempat_lahir.required' => 'Tempat lahir wajib diisi',
            'tanggal_lahir.required' => 'Tanggal lahir wajib diisi',
            'tanggal_lahir.before' => 'Tanggal lahir harus sebelum hari ini',
            'tanggal_bergabung.required' => 'Tanggal bergabung wajib diisi',
            'tanggal_bergabung.before_or_equal' => 'Tanggal bergabung tidak boleh di masa depan',
            'tanggal_keluar.after' => 'Tanggal keluar harus setelah tanggal bergabung',
            'status.required' => 'Status wajib dipilih',
            'status.in' => 'Status tidak valid',
            'sisa_cuti_tahunan.integer' => 'Sisa cuti tahunan harus berupa angka',
            'sisa_cuti_tahunan.min' => 'Sisa cuti tahunan minimal 0',
            'sisa_cuti_tahunan.max' => 'Sisa cuti tahunan maksimal 12'
        ]);

        try {
            DB::beginTransaction();

            $username = $this->generateUsername($request->nik);
            
            $originalUsername = $username;
            $counter = 1;
            while (Karyawan::where('username', $username)->exists()) {
                $username = $originalUsername . $counter;
                $counter++;
            }

            $tanggalLahir = Carbon::parse($request->tanggal_lahir);
            $defaultPassword = $tanggalLahir->format('dmY');

            $karyawan = new Karyawan();
            $karyawan->nik = $request->nik;
            $karyawan->nama = $request->nama;
            $karyawan->no_telepon = $request->no_telepon;
            $karyawan->divisi_id = $request->divisi_id; // âœ… Can be null now
            $karyawan->jabatan_id = $request->jabatan_id;
            $karyawan->jenis_kelamin = $request->jenis_kelamin;
            $karyawan->tempat_lahir = $request->tempat_lahir;
            $karyawan->tanggal_lahir = $request->tanggal_lahir;
            $karyawan->tanggal_bergabung = $request->tanggal_bergabung;
            $karyawan->tanggal_keluar = $request->tanggal_keluar;
            $karyawan->username = $username;
            $karyawan->password = Hash::make($defaultPassword);
            $karyawan->status = $request->status;
            $karyawan->sisa_cuti_tahunan = $request->input('sisa_cuti_tahunan', 12);
            $karyawan->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Karyawan berhasil dibuat dengan username: ' . $username . ' dan password: ' . $defaultPassword,
                'data' => $karyawan->load(['divisi', 'jabatan']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan karyawan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function show(Karyawan $karyawan)
    {
        return response()->json([
            'success' => true,
            'data' => $karyawan->load(['divisi', 'jabatan']),
        ]);
    }

    public function update(Request $request, Karyawan $karyawan)
    {
        $request->validate([
            'nik' => 'required|string|max:20|unique:karyawans,nik,' . $karyawan->id,
            'nama' => 'required|string|max:255',
            'no_telepon' => 'required|string|max:15',
            'divisi_id' => 'nullable|exists:divisis,id', // âœ… CHANGED: nullable
            'jabatan_id' => 'required|exists:jabatans,id',
            'jenis_kelamin' => 'required|in:L,P',
            'tempat_lahir' => 'required|string|max:255',
            'tanggal_lahir' => 'required|date|before:today',
            'tanggal_bergabung' => 'required|date|before_or_equal:today',
            'tanggal_keluar' => 'nullable|date|after:tanggal_bergabung',
            'password' => 'nullable|string|min:6',
            'status' => 'required|in:aktif,tidak_aktif',
            'sisa_cuti_tahunan' => 'nullable|integer|min:0|max:12'
        ]);

        try {
            $tanggalLahir = $request->tanggal_lahir;
            $tanggalBergabung = $request->tanggal_bergabung;
            $tanggalKeluar = $request->tanggal_keluar;

            $status = $request->status;
            
            if ($status === 'aktif') {
                $tanggalKeluar = null;
            } elseif ($status === 'tidak_aktif' && empty($tanggalKeluar)) {
                $tanggalKeluar = now()->format('Y-m-d');
            } elseif (!empty($tanggalKeluar)) {
                $status = 'tidak_aktif';
            }

            $updateData = [
                'nik' => $request->nik,
                'nama' => $request->nama,
                'no_telepon' => $request->no_telepon,
                'divisi_id' => $request->divisi_id, // âœ… Can be null now
                'jabatan_id' => $request->jabatan_id,
                'jenis_kelamin' => $request->jenis_kelamin,
                'tempat_lahir' => $request->tempat_lahir,
                'tanggal_lahir' => $tanggalLahir,
                'tanggal_bergabung' => $tanggalBergabung,
                'tanggal_keluar' => $tanggalKeluar,
                'sisa_cuti_tahunan' => $request->sisa_cuti_tahunan,
                'status' => $status
            ];

            $oldTanggalLahir = $karyawan->getOriginal('tanggal_lahir');
            if ($oldTanggalLahir !== $tanggalLahir) {
                $birthDate = \Carbon\Carbon::createFromFormat('Y-m-d', $tanggalLahir);
                $newPassword = $birthDate->format('dmY');
                $updateData['password'] = Hash::make($newPassword);
                
                $passwordChanged = true;
                $displayPassword = $newPassword;
            } elseif ($request->filled('password')) {
                $updateData['password'] = Hash::make($request->password);
                $passwordChanged = true;
                $displayPassword = $request->password;
            } else {
                $passwordChanged = false;
            }

            DB::table('karyawans')
                ->where('id', $karyawan->id)
                ->update($updateData + ['updated_at' => now()]);

            $karyawan->refresh();

            $message = 'Karyawan berhasil diperbarui';
            if (isset($passwordChanged) && $passwordChanged) {
                $message .= '. Password baru: ' . $displayPassword;
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $karyawan->fresh()->load(['divisi', 'jabatan']),
            ]);

        } catch (\Exception $e) {
            // Log::error('Update karyawan error:', [
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui karyawan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function resetPassword(Karyawan $karyawan)
    {
        try {
            // Generate password from tanggal_lahir (ddmmyyyy)
            $tanggalLahir = Carbon::parse($karyawan->tanggal_lahir);
            $defaultPassword = $tanggalLahir->format('dmY');
            
            $karyawan->update([
                'password' => Hash::make($defaultPassword)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Password berhasil direset!',
                'data' => $karyawan->fresh()->load(['divisi', 'jabatan']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mereset password: ' . $e->getMessage(),
            ], 500);
        }
    }

    // Soft delete - mengubah status menjadi tidak aktif
    public function destroy(Karyawan $karyawan)
    {
        try {
            // Soft delete dengan mengubah status dan menambah tanggal keluar
            $karyawan->update([
                'status' => 'tidak_aktif',
                'tanggal_keluar' => now()->format('Y-m-d')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Karyawan berhasil dinonaktifkan',
                'data' => $karyawan->fresh()->load(['divisi', 'jabatan']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menonaktifkan karyawan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function export()
    {
        try {
            // Check if there's data to export
            $count = Karyawan::count();
            if ($count === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data karyawan untuk diekspor',
                ], 404);
            }

            $fileName = 'data-karyawan-' . date('Y-m-d-His') . '.xlsx';
            
            return Excel::download(new KaryawanExport, $fileName, \Maatwebsite\Excel\Excel::XLSX, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            ]);

        } catch (\Exception $e) {
            // Log::error('Export error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengekspor data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate import file before processing (preview)
     */
    public function validateImport(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls|max:40000',
        ]);

        try {
            $file = $request->file('file');
            
            // Read only first 1000 rows for validation
            $rows = Excel::toCollection(null, $file)->first();
            
            // Validate structure and master data
            $validation = \App\Imports\KaryawanImport::validateMasterData($rows);

            if (!$validation['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $validation['message']
                ], 422);
            }

            // Check if missing projects exist
            $missingProjects = $validation['master_data']['project']['missing'];
            
            if (!empty($missingProjects)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Beberapa project belum ada dalam sistem. Buat project terlebih dahulu sebelum import.',
                    'validation' => $validation,
                    'type' => 'missing_projects',
                    'can_proceed' => false
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'File valid dan siap diimport',
                'validation' => $validation,
                'can_proceed' => $validation['can_proceed']
            ]);

        } catch (\Exception $e) {
            // Log::error('Validation failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memvalidasi file: ' . $e->getMessage()
            ], 422);
        }
    }

    /**
     * Import karyawan with queue job for large files
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls|max:40000', // Max 10MB
        ], [
            'file.required' => 'File wajib dipilih',
            'file.mimes' => 'File harus berformat Excel (.xlsx atau .xls)',
            'file.max' => 'Ukuran file maksimal 10MB',
        ]);

        try {
            // Store file temporarily
            $file = $request->file('file');
            $fileName = 'imports/' . uniqid('karyawan_import_') . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs('imports', $fileName);

            // Log::info('Import file uploaded', [
            //     'file_name' => $file->getClientOriginalName(),
            //     'file_path' => $filePath,
            //     'file_size' => $file->getSize(),
            //     'user_id' => auth()->id()
            // ]);

            // Dispatch job
            $job = new \App\Jobs\ImportKaryawanJob($filePath, auth()->id());
            dispatch($job);

            $importId = $job->getImportId();

            // Store import ID for tracking
            Cache::put("import_job_{$importId}", [
                'status' => 'queued',
                'message' => 'Import sedang diproses...',
                'started_at' => now()->toIso8601String()
            ], 3600);

            return response()->json([
                'success' => true,
                'message' => 'Import sedang diproses di background. Anda akan menerima notifikasi saat selesai.',
                'import_id' => $importId,
                'type' => 'queued'
            ], 202); // 202 Accepted

        } catch (\Exception $e) {
            // Log::error('Import upload failed', [
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString(),
            //     'user_id' => auth()->id()
            // ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memulai import: ' . $e->getMessage(),
                'type' => 'upload_error'
            ], 422);
        }
    }

    /**
     * Check import progress/status
     */
    public function checkImportStatus(Request $request)
    {
        $request->validate([
            'import_id' => 'required|string'
        ]);

        $importId = $request->import_id;

        // Check status in cache
        $status = Cache::get("import_status_{$importId}");

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'Status import tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $status
        ]);
    }

    /**
     * Get import progress (for real-time updates)
     */
    public function getImportProgress(Request $request)
    {
        $request->validate([
            'import_id' => 'required|string'
        ]);

        $importId = $request->import_id;

        // Get progress from cache
        $progress = Cache::get("import_progress_{$importId}");

        if (!$progress) {
            // Check if import is completed or failed
            $status = Cache::get("import_status_{$importId}");
            
            if ($status) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'percent' => $status['status'] === 'completed' ? 100 : 0,
                        'message' => $status['message'],
                        'status' => $status['status'],
                        'data' => $status['data'] ?? []
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Progress import tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $progress
        ]);
    }

    private function generateUsername($nik)
    {
        // Convert name to username format
        $username = $nik;
        
        return $username;
    }
}