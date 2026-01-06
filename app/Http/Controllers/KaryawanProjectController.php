<?php
// app/Http/Controllers/KaryawanProjectController.php

namespace App\Http\Controllers;

use App\Models\KaryawanProject;
use App\Models\Karyawan;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\KaryawanProjectImport;
use App\Exports\KaryawanProjectExport;
use Carbon\Carbon;

class KaryawanProjectController extends Controller
{
    // Get all assignments dengan filter
    public function index(Request $request)
    {
        $assignments = KaryawanProject::with(['karyawan.divisi', 'karyawan.jabatan', 'project']);

        // Filter by project
        if ($request->has('project_id') && $request->project_id !== 'all') {
            $assignments->where('project_id', $request->project_id);
        }

        // Filter by karyawan
        if ($request->has('karyawan_id')) {
            $assignments->where('karyawan_id', $request->karyawan_id);
        }

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $assignments->where('status', $request->status);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $assignments->whereHas('karyawan', function($q) use ($search) {
                $q->where('nama', 'like', '%' . $search . '%')
                  ->orWhere('nik', 'like', '%' . $search . '%');
            });
        }

        // Sorting
        $sortField = $request->input('sort_field', 'tanggal_assign');
        $sortDirection = $request->input('sort_direction', 'desc');
        $assignments->orderBy($sortField, $sortDirection);

        $perPage = $request->input('per_page', 10);
        $result = $assignments->paginate($perPage);

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
    }

    // ============================================
// 5. KARYAWAN PROJECT CONTROLLER - FIXED
// ============================================

public function getByProject($projectId, Request $request)
{
    // Load with nullable divisi support
    $assignments = KaryawanProject::with(['karyawan.divisi', 'karyawan.jabatan'])
        ->where('project_id', $projectId);

    // Filter by status
    if ($request->has('status') && $request->status !== 'all') {
        $assignments->where('status', $request->status);
    }

    // Filter by division
    if ($request->has('divisi_id') && $request->divisi_id !== 'all') {
        $assignments->whereHas('karyawan', function($q) use ($request) {
            $q->where('divisi_id', $request->divisi_id);
        });
    }

    // Filter by position
    if ($request->has('jabatan_id') && $request->jabatan_id !== 'all') {
        $assignments->whereHas('karyawan', function($q) use ($request) {
            $q->where('jabatan_id', $request->jabatan_id);
        });
    }

    // Search by name or NIK
    if ($request->has('search') && $request->search !== '') {
        $search = $request->search;
        $assignments->whereHas('karyawan', function($q) use ($search) {
            $q->where('nama', 'like', '%' . $search . '%')
              ->orWhere('nik', 'like', '%' . $search . '%');
        });
    }

    // Sorting
    $sortField = $request->input('sort_field', 'tanggal_assign');
    $sortDirection = $request->input('sort_direction', 'desc');

    if (in_array($sortField, ['nama', 'nik'])) {
        $assignments->join('karyawans', 'karyawan_projects.karyawan_id', '=', 'karyawans.id')
                    ->orderBy('karyawans.' . $sortField, $sortDirection)
                    ->select('karyawan_projects.*');
    } else {
        $assignments->orderBy($sortField, $sortDirection);
    }

    // Pagination
    $perPage = $request->input('per_page', 10);
    $result = $assignments->paginate($perPage);

    // Get project info
    $project = Project::with('shiftProjects')->find($projectId);

    // ✅ FIXED response mapping (divisi nullable)
    $data = $result->map(function ($assignment) {
        $karyawan = $assignment->karyawan;
        return [
            'id' => $assignment->id,
            'karyawan' => [
                'id' => $karyawan->id,
                'nik' => $karyawan->nik,
                'nama' => $karyawan->nama,
                'divisi' => $karyawan->divisi ? [
                    'id' => $karyawan->divisi->id,
                    'nama' => $karyawan->divisi->nama
                ] : [
                    'id' => null,
                    'nama' => '-'
                ],
                'jabatan' => $karyawan->jabatan ? [
                    'id' => $karyawan->jabatan->id,
                    'nama' => $karyawan->jabatan->nama
                ] : [
                    'id' => null,
                    'nama' => '-'
                ],
            ],
            'tanggal_assign' => $assignment->tanggal_assign,
            'status' => $assignment->status,
            'keterangan' => $assignment->keterangan
        ];
    });

    return response()->json([
        'success' => true,
        'project' => $project,
        'data' => $data,
        'pagination' => [
            'current_page' => $result->currentPage(),
            'per_page' => $result->perPage(),
            'total' => $result->total(),
            'last_page' => $result->lastPage(),
        ],
    ]);
}


    // Assign karyawan ke project (single or multiple)
    public function store(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'karyawan_ids' => 'required|array|min:1',
            'karyawan_ids.*' => 'required|exists:karyawans,id',
            'tanggal_assign' => 'required|date|before_or_equal:today',
            'keterangan' => 'nullable|string'
        ], [
            'project_id.required' => 'Project wajib dipilih',
            'project_id.exists' => 'Project tidak ditemukan',
            'karyawan_ids.required' => 'Minimal 1 karyawan harus dipilih',
            'karyawan_ids.array' => 'Format karyawan tidak valid',
            'karyawan_ids.min' => 'Minimal 1 karyawan harus dipilih',
            'tanggal_assign.required' => 'Tanggal assign wajib diisi',
            'tanggal_assign.before_or_equal' => 'Tanggal assign tidak boleh di masa depan'
        ]);

        DB::beginTransaction();
        try {
            $successCount = 0;
            $errors = [];

            foreach ($request->karyawan_ids as $karyawanId) {
                try {
                    // Cek apakah karyawan sudah aktif di project lain
                    $hasActiveProject = KaryawanProject::checkKaryawanHasActiveProject($karyawanId);
                    
                    if ($hasActiveProject) {
                        $karyawan = Karyawan::find($karyawanId);
                        $errors[] = "Karyawan {$karyawan->nama} ({$karyawan->nik}) sudah aktif di project lain";
                        continue;
                    }

                    // Cek apakah sudah pernah di-assign ke project ini
                    $existing = KaryawanProject::where('karyawan_id', $karyawanId)
                                               ->where('project_id', $request->project_id)
                                               ->first();

                    if ($existing) {
                        if ($existing->status === 'aktif') {
                            $karyawan = Karyawan::find($karyawanId);
                            $errors[] = "Karyawan {$karyawan->nama} ({$karyawan->nik}) sudah aktif di project ini";
                            continue;
                        } else {
                            // Reaktivasi
                            $existing->aktifkanKembali();
                            $successCount++;
                        }
                    } else {
                        // Create new assignment
                        KaryawanProject::create([
                            'karyawan_id' => $karyawanId,
                            'project_id' => $request->project_id,
                            'tanggal_assign' => $request->tanggal_assign,
                            'status' => 'aktif',
                            'keterangan' => $request->keterangan
                        ]);
                        $successCount++;
                    }
                } catch (\Exception $e) {
                    $karyawan = Karyawan::find($karyawanId);
                    $errors[] = "Gagal assign {$karyawan->nama}: " . $e->getMessage();
                }
            }

            DB::commit();

            $message = "$successCount karyawan berhasil di-assign";
            if (count($errors) > 0) {
                $message .= ". " . count($errors) . " gagal.";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'success_count' => $successCount,
                'errors' => $errors
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal assign karyawan: ' . $e->getMessage()
            ], 500);
        }
    }

    // Nonaktifkan karyawan dari project
    public function deactivate(Request $request, KaryawanProject $karyawanProject)
    {
        $request->validate([
            'tanggal_selesai' => 'required|date|after_or_equal:tanggal_assign',
            'keterangan' => 'nullable|string'
        ], [
            'tanggal_selesai.required' => 'Tanggal selesai wajib diisi',
            'tanggal_selesai.after_or_equal' => 'Tanggal selesai harus setelah atau sama dengan tanggal assign'
        ]);

        try {
            $karyawanProject->nonaktifkan($request->tanggal_selesai, $request->keterangan);

            return response()->json([
                'success' => true,
                'message' => 'Karyawan berhasil dinonaktifkan dari project',
                'data' => $karyawanProject->fresh()->load(['karyawan.divisi', 'karyawan.jabatan', 'project'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menonaktifkan karyawan: ' . $e->getMessage()
            ], 500);
        }
    }

    // Aktifkan kembali karyawan di project
    public function reactivate(KaryawanProject $karyawanProject)
    {
        try {
            $karyawanProject->aktifkanKembali();

            return response()->json([
                'success' => true,
                'message' => 'Karyawan berhasil diaktifkan kembali',
                'data' => $karyawanProject->fresh()->load(['karyawan.divisi', 'karyawan.jabatan', 'project'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    // Get available karyawan (belum aktif di project manapun)
    public function getAvailableKaryawan(Request $request)
    {
        $activeKaryawanIds = KaryawanProject::aktif()->pluck('karyawan_id');

        $karyawans = Karyawan::with(['divisi', 'jabatan'])
                             ->where('status', 'aktif')
                             ->whereNotIn('id', $activeKaryawanIds);

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $karyawans->where(function($q) use ($search) {
                $q->where('nama', 'like', '%' . $search . '%')
                  ->orWhere('nik', 'like', '%' . $search . '%');
            });
        }

        // Filter by division
        if ($request->has('divisi_id') && $request->divisi_id !== 'all') {
            $karyawans->where('divisi_id', $request->divisi_id);
        }

        // Filter by position
        if ($request->has('jabatan_id') && $request->jabatan_id !== 'all') {
            $karyawans->where('jabatan_id', $request->jabatan_id);
        }

        $perPage = $request->input('per_page', 10);
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
    }

    // Show single assignment
    public function show(KaryawanProject $karyawanProject)
    {
        return response()->json([
            'success' => true,
            'data' => $karyawanProject->load(['karyawan.divisi', 'karyawan.jabatan', 'project'])
        ]);
    }

    // Delete assignment (hard delete - hati-hati!)
    public function destroy(KaryawanProject $karyawanProject)
    {
        try {
            $karyawanProject->delete();

            return response()->json([
                'success' => true,
                'message' => 'Assignment berhasil dihapus'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus assignment: ' . $e->getMessage()
            ], 500);
        }
    }

    // Export assignments by project
    public function export($projectId)
    {
        try {
            $project = Project::with('shiftProjects')->findOrFail($projectId);
            
            $count = KaryawanProject::where('project_id', $projectId)->count();
            if ($count === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data karyawan untuk diekspor dari project ini'
                ], 404);
            }

            $fileName = 'karyawan-' . str_replace(' ', '-', strtolower($project->nama)) . '-' . date('Y-m-d-His') . '.xlsx';
            
            return Excel::download(
                new KaryawanProjectExport($projectId), 
                $fileName, 
                \Maatwebsite\Excel\Excel::XLSX
            );

        } catch (\Exception $e) {
            // \Log::error('Export error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengekspor data: ' . $e->getMessage()
            ], 500);
        }
    }

    // Import karyawan to project
    public function import(Request $request, $projectId)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls|max:2048',
        ], [
            'file.required' => 'File wajib dipilih',
            'file.mimes' => 'File harus berformat Excel (.xlsx atau .xls)',
            'file.max' => 'Ukuran file maksimal 2MB'
        ]);

        try {
            $project = Project::findOrFail($projectId);

            DB::beginTransaction();

            $import = new KaryawanProjectImport($projectId);
            Excel::import($import, $request->file('file'));

            DB::commit();

            $successCount = $import->getSuccessCount();
            $errors = $import->getErrors();

            $message = "$successCount karyawan berhasil diimport ke project {$project->nama}";
            if (count($errors) > 0) {
                $message .= ". " . count($errors) . " gagal.";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'success_count' => $successCount,
                'errors' => $errors
            ], 200);

        } catch (\Exception $e) {
            DB::rollback();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengimport data: ' . $e->getMessage()
            ], 422);
        }
    }
}