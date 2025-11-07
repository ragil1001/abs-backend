<?php

namespace App\Http\Controllers;

use App\Models\TukarShift;
use App\Models\JadwalKaryawan;
use App\Models\Karyawan;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TukarShiftController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get daftar permintaan tukar shift untuk karyawan (mobile)
     */
    public function index(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:all,pending,disetujui,ditolak,dibatalkan',
            'jenis' => 'nullable|in:all,saya,orang_lain',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        try {
            $karyawan = $request->user();
            
            $query = TukarShift::with([
                'peminta.divisi',
                'peminta.jabatan',
                'target.divisi',
                'target.jabatan',
                'jadwalPeminta.karyawanProject.project.shiftProjects',
                'jadwalTarget.karyawanProject.project.shiftProjects',
                'project'
            ])->byKaryawan($karyawan->id);

            if ($request->has('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            if ($request->has('jenis') && $request->jenis !== 'all') {
                if ($request->jenis === 'saya') {
                    $query->where('peminta_karyawan_id', $karyawan->id);
                } else {
                    $query->where('target_karyawan_id', $karyawan->id);
                }
            }

            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween(
                    'tanggal_pengajuan',
                    [$request->start_date, $request->end_date . ' 23:59:59']
                );
            }

            $tukarShifts = $query->orderBy('tanggal_pengajuan', 'desc')->get();

            $result = $tukarShifts->map(function($ts) use ($karyawan) {
                $shiftPeminta = $this->getShiftDetails($ts->jadwalPeminta);
                $shiftTarget = $this->getShiftDetails($ts->jadwalTarget);
                
                $jenis = $ts->isPeminta($karyawan->id) ? 'saya' : 'orang_lain';
                $karyawanTujuan = $jenis === 'saya' ? $ts->target : $ts->peminta;

                return [
                    'id' => $ts->id,
                    'status' => $ts->status,
                    'jenis' => $jenis,
                    'tanggal_request' => $ts->tanggal_pengajuan->format('Y-m-d H:i:s'),
                    'shift_saya' => $jenis === 'saya' ? $shiftPeminta : $shiftTarget,
                    'shift_diminta' => $jenis === 'saya' ? $shiftTarget : $shiftPeminta,
                    'karyawan_tujuan' => [
                        'id' => $karyawanTujuan->id,
                        'nama' => $karyawanTujuan->nama,
                        'nik' => $karyawanTujuan->nik,
                        'no_telp' => $karyawanTujuan->no_telepon,
                        'divisi' => $karyawanTujuan->divisi ? $karyawanTujuan->divisi->nama : '-',
                        'jabatan' => $karyawanTujuan->jabatan->nama,
                    ],
                    'catatan' => $ts->catatan,
                    'alasan_penolakan' => $ts->alasan_penolakan,
                    'tanggal_diproses' => $ts->tanggal_diproses ? $ts->tanggal_diproses->format('Y-m-d H:i:s') : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            // Log::error('Get tukar shift error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detail tukar shift
     */
    public function show(Request $request, $tukarShiftId)
    {
        try {
            $karyawan = $request->user();
            
            $tukarShift = TukarShift::with([
                'peminta.divisi',
                'peminta.jabatan',
                'target.divisi',
                'target.jabatan',
                'jadwalPeminta.karyawanProject.project.shiftProjects',
                'jadwalTarget.karyawanProject.project.shiftProjects',
                'project'
            ])->findOrFail($tukarShiftId);

            // Validasi akses (skip untuk admin web)
            if ($request->user() instanceof \App\Models\Karyawan) {
                if (!$tukarShift->isPeminta($karyawan->id) && !$tukarShift->isTarget($karyawan->id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke permintaan ini'
                    ], 403);
                }
            }

            // Get shift details using helper
            $shiftPeminta = $this->getShiftDetails($tukarShift->jadwalPeminta);
            $shiftTarget = $this->getShiftDetails($tukarShift->jadwalTarget);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $tukarShift->id,
                    'status' => $tukarShift->status,
                    'tanggal_pengajuan' => $tukarShift->tanggal_pengajuan->format('Y-m-d H:i:s'),
                    'peminta' => [
                        'id' => $tukarShift->peminta->id,
                        'nik' => $tukarShift->peminta->nik,
                        'nama' => $tukarShift->peminta->nama,
                        'no_telepon' => $tukarShift->peminta->no_telepon,
                        'divisi' => $tukarShift->peminta->divisi ? $tukarShift->peminta->divisi->nama : '-',
                        'jabatan' => $tukarShift->peminta->jabatan->nama ?? '-',
                    ],
                    'target' => [
                        'id' => $tukarShift->target->id,
                        'nik' => $tukarShift->target->nik,
                        'nama' => $tukarShift->target->nama,
                        'no_telepon' => $tukarShift->target->no_telepon,
                        'divisi' => $tukarShift->target->divisi ? $tukarShift->target->divisi->nama : '-',
                        'jabatan' => $tukarShift->target->jabatan->nama ?? '-',
                    ],
                    'jadwal_peminta' => $shiftPeminta,
                    'jadwal_target' => $shiftTarget,
                    'catatan' => $tukarShift->catatan,
                    'alasan_penolakan' => $tukarShift->alasan_penolakan,
                    'tanggal_diproses' => $tukarShift->tanggal_diproses ? $tukarShift->tanggal_diproses->format('Y-m-d H:i:s') : null,
                    'dibatalkan_pada' => $tukarShift->tanggal_dibatalkan ? $tukarShift->tanggal_dibatalkan->format('Y-m-d H:i:s') : null,
                    'created_at' => $tukarShift->created_at->format('Y-m-d H:i:s'),
                ]
            ]);

        } catch (\Exception $e) {
            // Log::error('Get detail tukar shift error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get jadwal shift karyawan yang available untuk ditukar
     */
    public function getMyAvailableShifts(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
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

            $today = Carbon::today()->format('Y-m-d');
            
            $query = JadwalKaryawan::with('karyawanProject.project.shiftProjects')
                ->where('karyawan_project_id', $karyawanProject->id)
                ->where('tanggal', '>', $today)
                ->where('shift_code', '!=', 'L');

            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('tanggal', [$request->start_date, $request->end_date]);
            }

            $jadwals = $query->orderBy('tanggal', 'asc')->get();

            $result = $jadwals->map(function($jadwal) {
                return $this->getShiftDetails($jadwal);
            });

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            // Log::error('Get available shifts error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat jadwal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get karyawan yang memiliki shift di tanggal tertentu
     */
    /**
 * Get karyawan yang memiliki shift di tanggal tertentu
 */
public function getKaryawanWithShift(Request $request)
{
    $request->validate([
        'tanggal' => 'required|date|after:today',
        'search' => 'nullable|string|max:255',
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

        $projectId = $karyawanProject->project_id;
        $tanggal = $request->tanggal;

        // ✅ CHANGED: Use LEFT JOIN untuk divisi agar karyawan dengan divisi NULL tetap muncul
        $query = DB::table('karyawan_projects as kp')
            ->join('karyawans as k', 'kp.karyawan_id', '=', 'k.id')
            ->leftJoin('divisis as d', 'k.divisi_id', '=', 'd.id')  // ✅ LEFT JOIN
            ->join('jabatans as j', 'k.jabatan_id', '=', 'j.id')
            ->join('jadwal_karyawans as jk', 'kp.id', '=', 'jk.karyawan_project_id')
            ->where('kp.project_id', $projectId)
            ->where('kp.status', 'aktif')
            ->where('kp.karyawan_id', '!=', $karyawan->id)
            ->where('jk.tanggal', $tanggal)
            ->where('jk.shift_code', '!=', 'L')
            ->select(
                'k.id as karyawan_id',
                'k.nama',
                'k.nik',
                'k.no_telepon',
                'd.nama as divisi',  // Akan NULL jika tidak ada divisi
                'j.nama as jabatan',
                'jk.id as jadwal_id',
                'jk.shift_code',
                'jk.tanggal'
            );

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('k.nama', 'like', '%' . $search . '%')
                  ->orWhere('k.no_telepon', 'like', '%' . $search . '%');
            });
        }

        $karyawans = $query->get();

        $result = $karyawans->map(function($k) {
            $jadwal = JadwalKaryawan::with('karyawanProject.project.shiftProjects')
                ->find($k->jadwal_id);
            $shift = $this->getShiftDetails($jadwal);
            
            return [
                'id' => $k->karyawan_id,
                'nama' => $k->nama,
                'nik' => $k->nik,
                'no_telp' => $k->no_telepon,
                'divisi' => $k->divisi ?? '-',  // ✅ Tetap handle null di sini
                'jabatan' => $k->jabatan,
                'shift' => $shift,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $result
        ]);

    } catch (\Exception $e) {
        // Log::error('Get karyawan with shift error: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal memuat data: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Ajukan tukar shift
     */
    public function store(Request $request)
    {
        $request->validate([
            'jadwal_peminta_id' => 'required|exists:jadwal_karyawans,id',
            'jadwal_target_id' => 'required|exists:jadwal_karyawans,id',
            'catatan' => 'nullable|string|max:500',
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

            $jadwal = JadwalKaryawan::findOrFail($request->jadwal_peminta_id);

            $jadwalPeminta = JadwalKaryawan::whereHas('karyawanProject', function($q) use ($karyawan) {
                $q->where('karyawan_id', $karyawan->id);
            })
            ->where('tanggal', $jadwal->tanggal)
            ->where('shift_code', $jadwal->shift_code)
            ->first();

            if (!$jadwalPeminta) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki jadwal untuk shift ini pada tanggal tersebut'
                ], 403);
            }

            $jadwalTarget = JadwalKaryawan::with('karyawanProject')->findOrFail($request->jadwal_target_id);
            $targetKaryawanProject = $jadwalTarget->karyawanProject;
            
            if ($targetKaryawanProject->project_id !== $karyawanProject->project_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tukar shift hanya bisa dilakukan dalam project yang sama'
                ], 400);
            }

            if ($targetKaryawanProject->karyawan_id === $karyawan->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak bisa tukar shift dengan diri sendiri'
                ], 400);
            }

            $today = Carbon::today()->format('Y-m-d');
            if ($jadwalPeminta->tanggal <= $today) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak bisa tukar shift yang sudah lewat'
                ], 400);
            }

            if ($jadwalTarget->tanggal <= $today) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jadwal target sudah lewat'
                ], 400);
            }

            $existingPending = TukarShift::where('status', 'pending')
                ->where(function($q) use ($request) {
                    $q->where('jadwal_peminta_id', $request->jadwal_peminta_id)
                      ->orWhere('jadwal_target_id', $request->jadwal_target_id);
                })
                ->exists();

            if ($existingPending) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sudah ada permintaan pending untuk salah satu shift ini'
                ], 400);
            }

            $tukarShift = TukarShift::create([
                'peminta_karyawan_id' => $karyawan->id,
                'target_karyawan_id' => $targetKaryawanProject->karyawan_id,
                'project_id' => $karyawanProject->project_id,
                'jadwal_peminta_id' => $request->jadwal_peminta_id,
                'jadwal_target_id' => $request->jadwal_target_id,
                'status' => 'pending',
                'catatan' => $request->catatan,
                'tanggal_pengajuan' => now(),
            ]);

            // Load relationships untuk notifikasi
            $tukarShift->load([
                'peminta',
                'target',
                'jadwalPeminta',
                'jadwalTarget',
                'project'
            ]);

            DB::commit();

            // Send notification to target karyawan
            $this->notificationService->notifyKaryawanNewTukarShift($tukarShift);

            // Send notification to admin (optional)
            $this->notificationService->notifyAdminNewTukarShift($tukarShift);

            return response()->json([
                'success' => true,
                'message' => 'Permintaan tukar shift berhasil diajukan',
                'data' => $tukarShift
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            // Log::error('Create tukar shift error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengajukan tukar shift: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Proses tukar shift (setujui/tolak) - untuk target
     */
    public function proses(Request $request, $tukarShiftId)
    {
        $request->validate([
            'action' => 'required|in:setujui,tolak',
            'alasan_penolakan' => 'required_if:action,tolak|nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $karyawan = $request->user();
            
            $tukarShift = TukarShift::with([
                'peminta',
                'target',
                'jadwalPeminta',
                'jadwalTarget',
                'project'
            ])->findOrFail($tukarShiftId);

            if (!$tukarShift->isTarget($karyawan->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk memproses permintaan ini'
                ], 403);
            }

            if (!$tukarShift->canBeProcessed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permintaan tidak dapat diproses karena status bukan pending'
                ], 400);
            }

            $today = Carbon::today()->format('Y-m-d');
            if ($tukarShift->jadwalPeminta->tanggal <= $today || 
                $tukarShift->jadwalTarget->tanggal <= $today) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat memproses karena salah satu jadwal sudah lewat'
                ], 400);
            }

            if ($request->action === 'setujui') {
                $tukarShift->approve();
                $this->swapJadwal($tukarShift);
                
                // Send notification to peminta (approved)
                $this->notificationService->notifyKaryawanTukarShiftApproved($tukarShift);
                
                $message = 'Permintaan tukar shift berhasil disetujui';
            } else {
                $tukarShift->reject($request->alasan_penolakan);
                
                // Send notification to peminta (rejected)
                $this->notificationService->notifyKaryawanTukarShiftRejected($tukarShift);
                
                $message = 'Permintaan tukar shift berhasil ditolak';
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $tukarShift->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            // Log::error('Proses tukar shift error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses permintaan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Batalkan permintaan tukar shift - untuk peminta
     */
    public function cancel(Request $request, $tukarShiftId)
    {
        DB::beginTransaction();
        try {
            $karyawan = $request->user();
            
            $tukarShift = TukarShift::findOrFail($tukarShiftId);

            if (!$tukarShift->isPeminta($karyawan->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk membatalkan permintaan ini'
                ], 403);
            }

            if (!$tukarShift->canBeCancelled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permintaan tidak dapat dibatalkan karena status bukan pending'
                ], 400);
            }

            $tukarShift->cancel();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Permintaan tukar shift berhasil dibatalkan'
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            // Log::error('Cancel tukar shift error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal membatalkan permintaan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get summary tukar shift by project (UNTUK ADMIN WEB)
     */
    public function getSummary($projectId, Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        try {
            $startDate = $request->start_date;
            $endDate = $request->end_date;

            $query = TukarShift::where('project_id', $projectId)
                ->whereBetween('tanggal_pengajuan', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

            $total = $query->count();
            $pending = (clone $query)->where('status', 'pending')->count();
            $disetujui = (clone $query)->where('status', 'disetujui')->count();
            $ditolak = (clone $query)->where('status', 'ditolak')->count();
            $dibatalkan = (clone $query)->where('status', 'dibatalkan')->count();

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
            // Log::error('Get summary tukar shift error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat summary: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get tukar shift by project (UNTUK ADMIN WEB)
     */
    public function indexAdmin(Request $request, $projectId)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'status' => 'nullable|in:pending,disetujui,ditolak,dibatalkan,all'
        ]);

        try {
            $startDate = $request->start_date;
            $endDate = $request->end_date;

            $query = TukarShift::with([
                'jadwalPeminta.karyawanProject.project.shiftProjects',
                'jadwalTarget.karyawanProject.project.shiftProjects',
                'peminta.divisi',
                'peminta.jabatan',
                'target.divisi',
                'target.jabatan'
            ])
            ->where('project_id', $projectId)
            ->whereBetween('tanggal_pengajuan', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

            // Filter by status
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Search by nama
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->whereHas('peminta', function($sub) use ($search) {
                        $sub->where('nama', 'like', '%' . $search . '%')
                            ->orWhere('nik', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('target', function($sub) use ($search) {
                        $sub->where('nama', 'like', '%' . $search . '%')
                            ->orWhere('nik', 'like', '%' . $search . '%');
                    });
                });
            }

            // Sorting
            $sortField = $request->input('sort_field', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            
            if ($sortField === 'peminta') {
                $query->join('karyawans as k_peminta', 'tukar_shifts.peminta_karyawan_id', '=', 'k_peminta.id')
                      ->orderBy('k_peminta.nama', $sortDirection)
                      ->select('tukar_shifts.*');
            } elseif ($sortField === 'target') {
                $query->join('karyawans as k_target', 'tukar_shifts.target_karyawan_id', '=', 'k_target.id')
                      ->orderBy('k_target.nama', $sortDirection)
                      ->select('tukar_shifts.*');
            } elseif ($sortField === 'tanggal_peminta') {
                $query->join('jadwal_karyawans as j_peminta', 'tukar_shifts.jadwal_peminta_id', '=', 'j_peminta.id')
                      ->orderBy('j_peminta.tanggal', $sortDirection)
                      ->select('tukar_shifts.*');
            } elseif ($sortField === 'tanggal_target') {
                $query->join('jadwal_karyawans as j_target', 'tukar_shifts.jadwal_target_id', '=', 'j_target.id')
                      ->orderBy('j_target.tanggal', $sortDirection)
                      ->select('tukar_shifts.*');
            } else {
                $query->orderBy($sortField, $sortDirection);
            }

            $perPage = $request->input('per_page', 10);
            $result = $query->paginate($perPage);

            // Use helper method for shift details
            $data = $result->map(function($item) {
                $shiftPeminta = $this->getShiftDetails($item->jadwalPeminta);
                $shiftTarget = $this->getShiftDetails($item->jadwalTarget);

                return [
                    'id' => $item->id,
                    'peminta' => [
                        'id' => $item->peminta->id,
                        'nik' => $item->peminta->nik,
                        'nama' => $item->peminta->nama,
                        'divisi' => $item->peminta->divisi ? $item->peminta->divisi->nama : '-',
                        'jabatan' => $item->peminta->jabatan->nama ?? '-'
                    ],
                    'target' => [
                        'id' => $item->target->id,
                        'nik' => $item->target->nik,
                        'nama' => $item->target->nama,
                        'divisi' => $item->target->divisi ? $item->target->divisi->nama : '-',
                        'jabatan' => $item->target->jabatan->nama ?? '-'
                    ],
                    'jadwal_peminta' => $shiftPeminta,
                    'jadwal_target' => $shiftTarget,
                    'status' => $item->status,
                    'catatan' => $item->catatan,
                    'alasan_penolakan' => $item->alasan_penolakan,
                    'created_at' => $item->created_at->format('Y-m-d H:i:s'),
                    'tanggal_pengajuan' => $item->tanggal_pengajuan ? $item->tanggal_pengajuan->format('Y-m-d H:i:s') : null,
                    'tanggal_diproses' => $item->tanggal_diproses ? $item->tanggal_diproses->format('Y-m-d H:i:s') : null,
                    'dibatalkan_pada' => $item->tanggal_dibatalkan ? $item->tanggal_dibatalkan->format('Y-m-d H:i:s') : null
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
            // Log::error('Get tukar shift admin error: ' . $e->getMessage());
            // Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data tukar shift: ' . $e->getMessage()
            ], 500);
        }
    }

    // ========== HELPER METHODS ==========

    /**
     * Get shift details dari jadwal
     */
    private function getShiftDetails($jadwal)
    {
        // Load project with shifts
        $jadwal->load('karyawanProject.project.shiftProjects');
        
        $project = $jadwal->karyawanProject->project;
        $shiftCodeUpper = strtoupper($jadwal->shift_code);
        
        // Find shift dengan case-insensitive comparison
        $shift = $project->shiftProjects->first(function($s) use ($shiftCodeUpper) {
            return strtoupper($s->kode) === $shiftCodeUpper;
        });
        
        // Format waktu ke HH:mm
        $waktuMulai = null;
        $waktuSelesai = null;
        
        if ($shift && $shiftCodeUpper !== 'L') {
            $waktuMulai = substr($shift->waktu_mulai, 0, 5);
            $waktuSelesai = substr($shift->waktu_selesai, 0, 5);
        }
        
        return [
            'jadwal_id' => $jadwal->id,
            'tanggal' => $jadwal->tanggal,
            'hari' => $this->getHari($jadwal->tanggal),
            'shift_code' => $jadwal->shift_code,
            'waktu_mulai' => $waktuMulai,
            'waktu_selesai' => $waktuSelesai,
            'waktu' => ($waktuMulai && $waktuSelesai) ? "{$waktuMulai} - {$waktuSelesai}" : null,
        ];
    }

    /**
     * Swap jadwal antara peminta dan target
     */
    private function swapJadwal($tukarShift)
    {
        $jadwalPeminta = $tukarShift->jadwalPeminta;
        $jadwalTarget = $tukarShift->jadwalTarget;

        $tempShiftCode = $jadwalPeminta->shift_code;
        
        $jadwalPeminta->update([
            'shift_code' => $jadwalTarget->shift_code,
            'keterangan' => "Ditukar dengan {$tukarShift->target->nama} (ID Tukar: {$tukarShift->id})"
        ]);
        
        $jadwalTarget->update([
            'shift_code' => $tempShiftCode,
            'keterangan' => "Ditukar dengan {$tukarShift->peminta->nama} (ID Tukar: {$tukarShift->id})"
        ]);

        // Log::info('Jadwal berhasil ditukar', [
        //     'tukar_shift_id' => $tukarShift->id,
        //     'jadwal_peminta_id' => $jadwalPeminta->id,
        //     'jadwal_target_id' => $jadwalTarget->id,
        //     'shift_peminta_before' => $tempShiftCode,
        //     'shift_peminta_after' => $jadwalPeminta->fresh()->shift_code,
        //     'shift_target_before' => $jadwalTarget->shift_code,
        //     'shift_target_after' => $jadwalTarget->fresh()->shift_code,
        // ]);
    }

    /**
     * Get nama hari dalam bahasa Indonesia
     */
    private function getHari($tanggal)
    {
        $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $date = \DateTime::createFromFormat('Y-m-d', $tanggal);
        return $date ? $days[$date->format('w')] : '-';
    }
}