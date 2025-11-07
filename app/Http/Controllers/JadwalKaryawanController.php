<?php

namespace App\Http\Controllers;

use App\Models\JadwalKaryawan;
use App\Models\KaryawanProject;
use App\Models\Project;
use App\Models\ShiftProject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\JadwalKaryawanImport;
use App\Exports\JadwalKaryawanExport;

class JadwalKaryawanController extends Controller
{
    /**
     * Get jadwal by project and periode
     */
    public function getByProject(Request $request, $projectId)
{
    $request->validate([
        'start_date' => 'required|date',
        'end_date' => 'required|date|after_or_equal:start_date',
        'status' => 'nullable|in:scheduled,completed,absent,all'
    ]);

    try {
        $project = Project::with('shiftProjects')->findOrFail($projectId);
        
        $startDate = $request->start_date;
        $endDate = $request->end_date;
        
        $query = JadwalKaryawan::with([
            'karyawanProject.karyawan.divisi',
            'karyawanProject.karyawan.jabatan'
        ])
        ->byProject($projectId)
        ->where('tanggal', '>=', $startDate)
        ->where('tanggal', '<=', $endDate);

        if ($request->status && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('karyawan_id')) {
            $query->byKaryawan($request->karyawan_id);
        }

        if ($request->has('shift_code')) {
            $query->where('shift_code', $request->shift_code);
        }

        $jadwals = $query->orderBy('tanggal')->get();

        // ✅ CREATE SHIFT MAP FOR QUICK LOOKUP
        $shiftMap = $project->shiftProjects->keyBy('kode')->map(function($shift) {
            return [
                'waktu_mulai' => substr($shift->waktu_mulai, 0, 5),
                'waktu_selesai' => substr($shift->waktu_selesai, 0, 5)
            ];
        })->toArray();

        $groupedByKaryawan = $jadwals->groupBy('karyawan_project_id')->map(function($items) use ($shiftMap) {
            $karyawanProject = $items->first()->karyawanProject;
            $karyawan = $karyawanProject->karyawan; 
            return [
                'karyawan_project_id' => $karyawanProject->id,
                'karyawan' => [
                    'id' => $karyawanProject->karyawan->id,
                    'nik' => $karyawanProject->karyawan->nik,
                    'nama' => $karyawanProject->karyawan->nama,
                    'divisi' => $karyawan->divisi ? [ // ✅ FIXED
                        'id' => $karyawan->divisi->id,
                        'nama' => $karyawan->divisi->nama
                    ] : [
                        'id' => null,
                        'nama' => '-'
                    ],
                    'jabatan' => $karyawanProject->karyawan->jabatan,
                ],
                'jadwals' => $items->map(function($jadwal) use ($shiftMap) {
                    // ✅ CHECK IF SHIFT IS SWAPPED
                    $isDitukar = false;
                    $tukarShiftInfo = null;
                    
                    $tukarShift = \App\Models\TukarShift::where('status', 'disetujui')
                        ->where(function($q) use ($jadwal) {
                            $q->where('jadwal_peminta_id', $jadwal->id)
                              ->orWhere('jadwal_target_id', $jadwal->id);
                        })
                        ->with(['peminta', 'target'])
                        ->first();
                    
                    if ($tukarShift) {
                        $isDitukar = true;
                        
                        if ($tukarShift->jadwal_peminta_id == $jadwal->id) {
                            $tukarShiftInfo = [
                                'id' => $tukarShift->id,
                                'dengan' => $tukarShift->target->nama
                            ];
                        } else {
                            $tukarShiftInfo = [
                                'id' => $tukarShift->id,
                                'dengan' => $tukarShift->peminta->nama
                            ];
                        }
                    }

                    // ✅ GET SHIFT TIMES FROM SHIFT MAP
                    $shiftCode = strtoupper($jadwal->shift_code);
                    $waktuMulai = null;
                    $waktuSelesai = null;

                    if ($shiftCode !== 'L' && isset($shiftMap[$shiftCode])) {
                        $waktuMulai = $shiftMap[$shiftCode]['waktu_mulai'];
                        $waktuSelesai = $shiftMap[$shiftCode]['waktu_selesai'];
                    }
                    
                    return [
                        'id' => $jadwal->id,
                        'tanggal' => $jadwal->tanggal,
                        'shift_code' => $jadwal->shift_code,
                        'waktu_mulai' => $waktuMulai,      // ✅ ADD THIS
                        'waktu_selesai' => $waktuSelesai,  // ✅ ADD THIS
                        'status' => $jadwal->status,
                        'is_libur' => $jadwal->is_libur,
                        'hari' => $jadwal->hari,
                        'is_ditukar' => $isDitukar,
                        'tukar_shift_info' => $tukarShiftInfo
                    ];
                })->values()
            ];
        })->values();

        return response()->json([
            'success' => true,
            'project' => $project,
            'data' => $groupedByKaryawan,
            'summary' => [
                'total_karyawan' => $groupedByKaryawan->count(),
                'total_jadwal' => $jadwals->count(),
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        ]);

    } catch (\Exception $e) {
        // Log::error('Get jadwal error: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal memuat jadwal: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Import jadwal from Excel
     */
    public function import(Request $request, $projectId)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls|max:5120',
            'period_start' => 'required|date'
        ], [
            'file.required' => 'File wajib dipilih',
            'file.mimes' => 'File harus berformat Excel (.xlsx atau .xls)',
            'file.max' => 'Ukuran file maksimal 5MB',
            'period_start.required' => 'Tanggal mulai periode wajib diisi'
        ]);

        try {
            $project = Project::with('shiftProjects')->findOrFail($projectId);
            
            $validShiftCodes = $project->shiftProjects->pluck('kode')->map(fn($code) => strtoupper($code))->toArray();
            $validShiftCodes[] = 'L';

            DB::beginTransaction();

            // CRITICAL: Pass raw date string - NO parsing
            $import = new JadwalKaryawanImport($projectId, $request->period_start, $validShiftCodes);
            Excel::import($import, $request->file('file'));

            DB::commit();

$successCount = $import->getSuccessCount();
$errors = $import->getErrors();
$skippedPast = $import->getSkippedPastCount(); // ✅ NEW

$message = "$successCount jadwal berhasil diimport untuk project {$project->nama}";
if ($skippedPast > 0) {
    $message .= ". {$skippedPast} jadwal masa lalu dilewati.";
}
if (count($errors) > 0) {
    $message .= ". " . count($errors) . " error ditemukan.";
}

return response()->json([
    'success' => true,
    'message' => $message,
    'success_count' => $successCount,
    'skipped_past' => $skippedPast, // ✅ NEW
    'errors' => $errors,
    'project' => $project
], 200);

        } catch (\Exception $e) {
            DB::rollback();
            
            // Log::error('Import jadwal error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengimport data: ' . $e->getMessage()
            ], 422);
        }
    }

    /**
     * Export jadwal to Excel
     */
    public function export($projectId, Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        try {
            $project = Project::with('shiftProjects')->findOrFail($projectId);
            
            // CRITICAL: Use raw date strings
            $startDate = $request->start_date;
            $endDate = $request->end_date;
            
            $count = JadwalKaryawan::byProject($projectId)
                                   ->where('tanggal', '>=', $startDate)
                                   ->where('tanggal', '<=', $endDate)
                                   ->count();
                                   
            if ($count === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data jadwal untuk diekspor dari periode ini'
                ], 404);
            }

            $fileName = 'jadwal-' . str_replace(' ', '-', strtolower($project->nama)) . '-' . 
                       $startDate . '-' . 
                       date('His') . '.xlsx';
            
            return Excel::download(
                new JadwalKaryawanExport($projectId, $startDate, $endDate), 
                $fileName, 
                \Maatwebsite\Excel\Excel::XLSX
            );

        } catch (\Exception $e) {
            // Log::error('Export jadwal error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengekspor data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete jadwal by periode
     */
    public function deleteByPeriode(Request $request, $projectId)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        try {
            DB::beginTransaction();

            // CRITICAL: Use raw date strings
            $startDate = $request->start_date;
            $endDate = $request->end_date;

            $deleted = JadwalKaryawan::byProject($projectId)
                                     ->where('tanggal', '>=', $startDate)
                                     ->where('tanggal', '<=', $endDate)
                                     ->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "$deleted jadwal berhasil dihapus",
                'deleted_count' => $deleted
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            
            // Log::error('Delete jadwal error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus jadwal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get summary statistics
     */
    public function getSummary($projectId, Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        try {
            // CRITICAL: Use raw date strings
            $startDate = $request->start_date;
            $endDate = $request->end_date;
            
            $jadwals = JadwalKaryawan::byProject($projectId)
                                     ->where('tanggal', '>=', $startDate)
                                     ->where('tanggal', '<=', $endDate)
                                     ->get();

            $summary = [
                'total_jadwal' => $jadwals->count(),
                'total_karyawan' => $jadwals->pluck('karyawan_project_id')->unique()->count(),
                'by_status' => [
                    'scheduled' => $jadwals->where('status', 'scheduled')->count(),
                    'completed' => $jadwals->where('status', 'completed')->count(),
                    'absent' => $jadwals->where('status', 'absent')->count(),
                ],
                'by_shift' => $jadwals->groupBy('shift_code')->map->count(),
                'total_hari_kerja' => $jadwals->where('shift_code', '!=', 'L')->count(),
                'total_hari_libur' => $jadwals->where('shift_code', 'L')->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $summary
            ]);

        } catch (\Exception $e) {
            // Log::error('Get summary error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat summary: ' . $e->getMessage()
            ], 500);
        }
    }
}