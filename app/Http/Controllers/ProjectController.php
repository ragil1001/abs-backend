<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ShiftProject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ProjectExport;

class ProjectController extends Controller
{
    /**
     * Get all projects with shifts
     */
    public function index(Request $request)
    {
        try {
            // ✅ FIXED: Remove getExcludedJabatansAttribute from with() - it's an accessor
            $query = Project::with(['shiftProjects']);

            // Filter by status
            if ($request->filled('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Search
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('nama', 'ILIKE', '%' . $search . '%')
                      ->orWhere('bagian', 'ILIKE', '%' . $search . '%')
                      ->orWhere('lokasi_nama', 'ILIKE', '%' . $search . '%');
                });
            }

            // Sorting
            $sortField = $request->input('sort_field', 'id');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);

            $projects = $query->get();

            // ✅ Transform data - excluded_jabatans will be auto-included from $appends
            $projects = $projects->map(function($project) {
                $projectData = $project->toArray();
                
                // Format shifts with HH:mm time only
                if ($project->shiftProjects) {
                    $projectData['shifts'] = $project->shiftProjects->map(function($shift) {
                        return [
                            'id' => $shift->id,
                            'kode' => $shift->kode,
                            'waktu_mulai' => substr($shift->waktu_mulai, 0, 5), // HH:mm only
                            'waktu_selesai' => substr($shift->waktu_selesai, 0, 5), // HH:mm only
                        ];
                    })->toArray();
                }
                
                return $projectData;
            });

            return response()->json([
                'success' => true,
                'data' => $projects
            ]);

        } catch (\Exception $e) {
            // Log::error('Get projects error: ' . $e->getMessage(), [
            //     'trace' => $e->getTraceAsString()
            // ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data project: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single project with shifts
     */
    public function show($id)
    {
        try {
            // ✅ FIXED: Remove getExcludedJabatansAttribute from with()
            $project = Project::with(['shiftProjects'])->findOrFail($id);
            
            $projectData = $project->toArray();
            
            // Format shifts with HH:mm time only
            if ($project->shiftProjects) {
                $projectData['shifts'] = $project->shiftProjects->map(function($shift) {
                    return [
                        'id' => $shift->id,
                        'kode' => $shift->kode,
                        'waktu_mulai' => substr($shift->waktu_mulai, 0, 5), // HH:mm only
                        'waktu_selesai' => substr($shift->waktu_selesai, 0, 5), // HH:mm only
                    ];
                })->toArray();
            }

            return response()->json([
                'success' => true,
                'data' => $projectData
            ]);

        } catch (\Exception $e) {
            // Log::error('Get project error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Project tidak ditemukan'
            ], 404);
        }
    }

    /**
     * Create new project with shifts
     */
    public function store(Request $request)
{
    $request->validate([
        'nama' => 'required|string|max:255',
        'bagian' => 'required|string|max:255',
        'lokasi.nama' => 'required|string|max:255',
        'lokasi.latitude' => 'required|numeric|between:-90,90',
        'lokasi.longitude' => 'required|numeric|between:-180,180',
        'tanggal_mulai' => 'required|date',
        'radius' => 'required|integer|min:10|max:1000',
        'waktu_toleransi' => 'nullable|integer|min:0|max:180',
        'excluded_jabatan_ids' => 'nullable|array',
        'excluded_jabatan_ids.*' => 'exists:jabatans,id',
        'enabled_izin_categories' => 'nullable|array',
        'enabled_izin_categories.*' => 'in:sakit,izin,cuti_tahunan,cuti_khusus',
        'enabled_sub_kategori_izin' => 'nullable|array',
        'enabled_sub_kategori_izin.*' => 'in:pernikahan_karyawan,pernikahan_anak,istri_melahirkan,kematian_keluarga,kematian_serumah,khitanan_baptis',
        'status' => 'required|in:aktif,tidak_aktif',
        'shifts' => 'required|array|min:1',
        'shifts.*.kode' => 'required|string|max:50',
        'shifts.*.waktu_mulai' => 'required|date_format:H:i',
        'shifts.*.waktu_selesai' => 'required|date_format:H:i',
    ]);

    DB::beginTransaction();

    try {
        // VALIDASI: Jika enabled_sub_kategori_izin ada, pastikan cuti_khusus diaktifkan
        if (!empty($request->enabled_sub_kategori_izin) && 
            !in_array('cuti_khusus', $request->enabled_izin_categories ?? [])) {
            return response()->json([
                'success' => false,
                'message' => 'Sub kategori cuti khusus hanya bisa diaktifkan jika kategori "Cuti Khusus" juga diaktifkan'
            ], 422);
        }

        $project = Project::create([
            'nama' => $request->nama,
            'bagian' => $request->bagian,
            'lokasi_nama' => $request->lokasi['nama'],
            'lokasi_latitude' => $request->lokasi['latitude'],
            'lokasi_longitude' => $request->lokasi['longitude'],
            'tanggal_mulai' => $request->tanggal_mulai,
            'radius' => $request->radius,
            'waktu_toleransi' => $request->waktu_toleransi,
            'excluded_jabatan_ids' => $request->excluded_jabatan_ids ?? [],
            'enabled_izin_categories' => $request->enabled_izin_categories ?? null,
            'enabled_sub_kategori_izin' => $request->enabled_sub_kategori_izin ?? null,
            'status' => $request->status
        ]);

        foreach ($request->shifts as $shiftData) {
            ShiftProject::create([
                'project_id' => $project->id,
                'kode' => $shiftData['kode'],
                'waktu_mulai' => $shiftData['waktu_mulai'],
                'waktu_selesai' => $shiftData['waktu_selesai']
            ]);
        }

        DB::commit();

        $project->load(['shiftProjects']);
        
        $projectData = $project->toArray();
        
        if ($project->shiftProjects) {
            $projectData['shifts'] = $project->shiftProjects->map(function($shift) {
                return [
                    'id' => $shift->id,
                    'kode' => $shift->kode,
                    'waktu_mulai' => substr($shift->waktu_mulai, 0, 5),
                    'waktu_selesai' => substr($shift->waktu_selesai, 0, 5),
                ];
            })->toArray();
        }

        return response()->json([
            'success' => true,
            'message' => 'Project berhasil dibuat',
            'data' => $projectData
        ], 201);

    } catch (\Exception $e) {
        DB::rollback();
        // Log::error('Create project error: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal membuat project: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Update project with shifts
     */
    public function update(Request $request, $id)
{
    $request->validate([
        'nama' => 'required|string|max:255',
        'bagian' => 'required|string|max:255',
        'lokasi.nama' => 'required|string|max:255',
        'lokasi.latitude' => 'required|numeric|between:-90,90',
        'lokasi.longitude' => 'required|numeric|between:-180,180',
        'tanggal_mulai' => 'required|date',
        'radius' => 'required|integer|min:10|max:1000',
        'waktu_toleransi' => 'nullable|integer|min:0|max:180',
        'excluded_jabatan_ids' => 'nullable|array',
        'excluded_jabatan_ids.*' => 'exists:jabatans,id',
        'enabled_izin_categories' => 'nullable|array',
        'enabled_izin_categories.*' => 'in:sakit,izin,cuti_tahunan,cuti_khusus',
        'enabled_sub_kategori_izin' => 'nullable|array',
        'enabled_sub_kategori_izin.*' => 'in:pernikahan_karyawan,pernikahan_anak,istri_melahirkan,kematian_keluarga,kematian_serumah,khitanan_baptis',
        'status' => 'required|in:aktif,tidak_aktif',
        'shifts' => 'required|array|min:1',
        'shifts.*.id' => 'nullable|exists:shift_projects,id',
        'shifts.*.kode' => 'required|string|max:50',
        'shifts.*.waktu_mulai' => 'required|date_format:H:i',
        'shifts.*.waktu_selesai' => 'required|date_format:H:i',
    ]);

    DB::beginTransaction();

    try {
        // VALIDASI: Jika enabled_sub_kategori_izin ada, pastikan cuti_khusus diaktifkan
        if (!empty($request->enabled_sub_kategori_izin) && 
            !in_array('cuti_khusus', $request->enabled_izin_categories ?? [])) {
            return response()->json([
                'success' => false,
                'message' => 'Sub kategori cuti khusus hanya bisa diaktifkan jika kategori "Cuti Khusus" juga diaktifkan'
            ], 422);
        }

        $project = Project::findOrFail($id);

        $project->update([
            'nama' => $request->nama,
            'bagian' => $request->bagian,
            'lokasi_nama' => $request->lokasi['nama'],
            'lokasi_latitude' => $request->lokasi['latitude'],
            'lokasi_longitude' => $request->lokasi['longitude'],
            'tanggal_mulai' => $request->tanggal_mulai,
            'radius' => $request->radius,
            'waktu_toleransi' => $request->waktu_toleransi,
            'excluded_jabatan_ids' => $request->excluded_jabatan_ids ?? [],
            'enabled_izin_categories' => $request->enabled_izin_categories ?? null,
            'enabled_sub_kategori_izin' => $request->enabled_sub_kategori_izin ?? null,
            'status' => $request->status
        ]);

        $existingShiftIds = $project->shiftProjects->pluck('id')->toArray();
        $updatedShiftIds = [];

        foreach ($request->shifts as $shiftData) {
            if (isset($shiftData['id']) && $shiftData['id']) {
                $shift = ShiftProject::find($shiftData['id']);
                if ($shift && $shift->project_id == $project->id) {
                    $shift->update([
                        'kode' => $shiftData['kode'],
                        'waktu_mulai' => $shiftData['waktu_mulai'],
                        'waktu_selesai' => $shiftData['waktu_selesai']
                    ]);
                    $updatedShiftIds[] = $shift->id;
                }
            } else {
                $newShift = ShiftProject::create([
                    'project_id' => $project->id,
                    'kode' => $shiftData['kode'],
                    'waktu_mulai' => $shiftData['waktu_mulai'],
                    'waktu_selesai' => $shiftData['waktu_selesai']
                ]);
                $updatedShiftIds[] = $newShift->id;
            }
        }

        $shiftsToDelete = array_diff($existingShiftIds, $updatedShiftIds);
        if (!empty($shiftsToDelete)) {
            ShiftProject::whereIn('id', $shiftsToDelete)->delete();
        }

        DB::commit();

        $project->load(['shiftProjects']);
        
        $projectData = $project->toArray();
        
        if ($project->shiftProjects) {
            $projectData['shifts'] = $project->shiftProjects->map(function($shift) {
                return [
                    'id' => $shift->id,
                    'kode' => $shift->kode,
                    'waktu_mulai' => substr($shift->waktu_mulai, 0, 5),
                    'waktu_selesai' => substr($shift->waktu_selesai, 0, 5),
                ];
            })->toArray();
        }

        return response()->json([
            'success' => true,
            'message' => 'Project berhasil diperbarui',
            'data' => $projectData
        ]);

    } catch (\Exception $e) {
        DB::rollback();
        // Log::error('Update project error: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal memperbarui project: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Soft delete project (change status to tidak_aktif)
     */
    public function destroy($id)
    {
        try {
            $project = Project::findOrFail($id);
            
            $project->update([
                'status' => 'tidak_aktif'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Project berhasil dinonaktifkan'
            ]);

        } catch (\Exception $e) {
            // Log::error('Delete project error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menonaktifkan project'
            ], 500);
        }
    }

    /**
     * Export projects to Excel
     */
    public function export()
    {
        try {
            $count = Project::count();
            
            if ($count === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data project untuk diekspor'
                ], 404);
            }

            $fileName = 'projects-' . date('Y-m-d-His') . '.xlsx';
            
            return Excel::download(
                new ProjectExport, 
                $fileName,
                \Maatwebsite\Excel\Excel::XLSX
            );

        } catch (\Exception $e) {
            // Log::error('Export project error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengekspor data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getIzinConfiguration($projectId)
{
    try {
        $project = Project::findOrFail($projectId);
        
        $enabledCategories = $project->getEnabledKategoriIzin();
        $enabledSubCategories = $project->getEnabledSubKategoriIzin();
        
        // Data kategori master
        $allCategories = [
            [
                'value' => PengajuanIzin::KATEGORI_SAKIT,
                'label' => 'Sakit',
                'kode' => 'S',
                'deskripsi' => 'Izin karena sakit (wajib lampirkan surat keterangan dokter)',
                'can_disable' => true, // Semua kategori bisa di-disable
                'enabled' => in_array(PengajuanIzin::KATEGORI_SAKIT, $enabledCategories)
            ],
            [
                'value' => PengajuanIzin::KATEGORI_IZIN,
                'label' => 'Izin',
                'kode' => 'I',
                'deskripsi' => 'Izin umum (urusan pribadi)',
                'can_disable' => true,
                'enabled' => in_array(PengajuanIzin::KATEGORI_IZIN, $enabledCategories)
            ],
            [
                'value' => PengajuanIzin::KATEGORI_CUTI_TAHUNAN,
                'label' => 'Cuti Tahunan',
                'kode' => 'CT',
                'deskripsi' => 'Cuti tahunan 12 hari per tahun',
                'can_disable' => true,
                'enabled' => in_array(PengajuanIzin::KATEGORI_CUTI_TAHUNAN, $enabledCategories)
            ],
            [
                'value' => PengajuanIzin::KATEGORI_CUTI_KHUSUS,
                'label' => 'Cuti Izin Khusus',
                'kode' => 'IK',
                'deskripsi' => 'Cuti khusus untuk acara penting (memiliki sub-kategori)',
                'can_disable' => true,
                'enabled' => in_array(PengajuanIzin::KATEGORI_CUTI_KHUSUS, $enabledCategories),
                'sub_categories' => [
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
                ]
            ]
        ];
        
        return response()->json([
            'success' => true,
            'data' => [
                'project_id' => $project->id,
                'project_name' => $project->nama,
                'categories' => $allCategories,
                'enabled_categories' => $enabledCategories,
                'enabled_sub_categories' => $enabledSubCategories
            ]
        ]);
        
    } catch (\Exception $e) {
        // Log::error('Get izin configuration error: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal memuat konfigurasi izin: ' . $e->getMessage()
        ], 500);
    }
}
}