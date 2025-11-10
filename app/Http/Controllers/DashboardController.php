<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\Project;
use App\Models\PengajuanIzin;
use App\Models\JadwalKaryawan;
use App\Models\Presensi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DashboardController extends Controller
{
    
    public function getDashboardData(Request $request)
    {
        try {
            $today = Carbon::today()->format('Y-m-d');
            
            
            $selectedProject = $request->input('project_id', 'all');
            $selectedShift = $request->input('shift_code', 'semua');
            
            
            $data = [
                'employee_stats' => $this->getEmployeeStats(),
                'attendance_stats' => $this->getAttendanceStats($today, $selectedProject, $selectedShift),
                'submissions' => $this->getRecentSubmissions(),
                'projects' => $this->getActiveProjects(),
            ];
            
            return response()->json([
                'success' => true,
                'data' => $data,
                'timestamp' => now()->toDateTimeString(),
                'cached' => false 
            ]);
            
        } catch (\Exception $e) {
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data dashboard: ' . $e->getMessage()
            ], 500);
        }
    }
    
    
    private function getEmployeeStats()
    {
        
        $stats = Karyawan::where('status', 'aktif')
            ->select('jenis_kelamin', DB::raw('count(*) as count'))
            ->groupBy('jenis_kelamin')
            ->get()
            ->keyBy('jenis_kelamin');
        
        $male = $stats->get('L');
        $female = $stats->get('P');
        
        $maleCount = $male ? $male->count : 0;
        $femaleCount = $female ? $female->count : 0;
        $total = $maleCount + $femaleCount;
        
        return [
            'total' => $total,
            'male' => [
                'count' => $maleCount,
                'percentage' => $total > 0 ? round(($maleCount / $total) * 100) : 0
            ],
            'female' => [
                'count' => $femaleCount,
                'percentage' => $total > 0 ? round(($femaleCount / $total) * 100) : 0
            ]
        ];
    }
    
    
    private function getAttendanceStats($tanggal, $projectId, $shiftCode)
    {
        if ($projectId === 'all') {
            return $this->getAggregatedAttendance($tanggal);
        }
        
        return $this->getProjectAttendance($tanggal, $projectId, $shiftCode);
    }
    
    
    private function getAggregatedAttendance($tanggal)
    {
        $projects = Project::where('status', 'aktif')->pluck('id');
        
        if ($projects->isEmpty()) {
            return $this->getEmptyAttendanceStats();
        }
        
        
        $jadwalIds = JadwalKaryawan::whereHas('karyawanProject.project', function($q) use ($projects) {
                $q->whereIn('id', $projects);
            })
            ->where('tanggal', $tanggal)
            ->pluck('id');
        
        if ($jadwalIds->isEmpty()) {
            return $this->getEmptyAttendanceStats();
        }
        
        
        $stats = $this->calculateAttendanceFromJadwal($jadwalIds, $tanggal);
        
        return [
            'chart_data' => [
                ['name' => 'Hadir', 'value' => $stats['hadir'], 'color' => '#10B981'],
                ['name' => 'Terlambat', 'value' => $stats['terlambat'], 'color' => '#F59E0B'],
                ['name' => 'Sakit', 'value' => $stats['sakit'], 'color' => '#EC4899'],
                ['name' => 'Izin', 'value' => $stats['izin'], 'color' => '#3B82F6'],
                ['name' => 'Cuti', 'value' => $stats['cuti'], 'color' => '#8B5CF6'],
                ['name' => 'Alpa', 'value' => $stats['alpa'], 'color' => '#EF4444'],
                ['name' => 'Libur', 'value' => $stats['libur'], 'color' => '#9CA3AF']
            ],
            'shifts' => [
                ['id' => 'semua', 'name' => 'Semua Shift']
            ]
        ];
    }
    
    
    private function getProjectAttendance($tanggal, $projectId, $shiftCode)
    {
        $project = Project::with('shiftProjects')->find($projectId);
        
        if (!$project) {
            return $this->getEmptyAttendanceStats();
        }
        
        
        $query = JadwalKaryawan::whereHas('karyawanProject', function($q) use ($projectId) {
                $q->where('project_id', $projectId);
            })
            ->where('tanggal', $tanggal);
        
        if ($shiftCode !== 'semua') {
            $query->where('shift_code', $shiftCode);
        }
        
        $jadwalIds = $query->pluck('id');
        
        if ($jadwalIds->isEmpty()) {
            return $this->getEmptyAttendanceStats();
        }
        
        $stats = $this->calculateAttendanceFromJadwal($jadwalIds, $tanggal);
        
        
        $shifts = [['id' => 'semua', 'name' => 'Semua Shift']];
        foreach ($project->shiftProjects as $shift) {
            $shifts[] = [
                'id' => $shift->kode,
                'name' => "Shift {$shift->kode} ({$shift->waktu_mulai} - {$shift->waktu_selesai})"
            ];
        }
        
        return [
            'chart_data' => [
                ['name' => 'Hadir', 'value' => $stats['hadir'], 'color' => '#10B981'],
                ['name' => 'Terlambat', 'value' => $stats['terlambat'], 'color' => '#F59E0B'],
                ['name' => 'Sakit', 'value' => $stats['sakit'], 'color' => '#EC4899'],
                ['name' => 'Izin', 'value' => $stats['izin'], 'color' => '#3B82F6'],
                ['name' => 'Cuti', 'value' => $stats['cuti'], 'color' => '#8B5CF6'],
                ['name' => 'Alpa', 'value' => $stats['alpa'], 'color' => '#EF4444'],
                ['name' => 'Libur', 'value' => $stats['libur'], 'color' => '#9CA3AF']
            ],
            'shifts' => $shifts
        ];
    }
    
    
    private function calculateAttendanceFromJadwal($jadwalIds, $tanggal)
    {
        
        $jadwals = JadwalKaryawan::whereIn('id', $jadwalIds)
            ->select('id', 'shift_code')
            ->get();
        
        
        $libur = $jadwals->filter(function($jadwal) {
            return strtoupper($jadwal->shift_code) === 'L';
        })->count();
        
        $workingJadwalIds = $jadwals->filter(function($jadwal) {
            return strtoupper($jadwal->shift_code) !== 'L';
        })->pluck('id');
        
        if ($workingJadwalIds->isEmpty()) {
            return [
                'hadir' => 0,
                'terlambat' => 0,
                'sakit' => 0,
                'izin' => 0,
                'cuti' => 0,
                'alpa' => 0,
                'libur' => $libur
            ];
        }
        
        
        
        $presensiStats = Presensi::whereIn('jadwal_karyawan_id', $workingJadwalIds->toArray())
            ->where('tipe', 'masuk')
            ->select(
                DB::raw("COUNT(CASE WHEN status = 'hadir' THEN 1 END) as hadir"),
                DB::raw("COUNT(CASE WHEN status = 'terlambat' THEN 1 END) as terlambat"),
                DB::raw("COUNT(CASE WHEN status = 'izin' AND kategori_izin = 'sakit' THEN 1 END) as sakit"),
                DB::raw("COUNT(CASE WHEN status = 'izin' AND kategori_izin = 'izin' THEN 1 END) as izin"),
                DB::raw("COUNT(CASE WHEN status = 'izin' AND kategori_izin IN ('cuti_tahunan', 'cuti_khusus') THEN 1 END) as cuti"),
                DB::raw("COUNT(CASE WHEN status = 'alpa' THEN 1 END) as alpa")
            )
            ->first();
        
        $totalPresensi = ($presensiStats->hadir ?? 0) + 
                        ($presensiStats->terlambat ?? 0) + 
                        ($presensiStats->sakit ?? 0) + 
                        ($presensiStats->izin ?? 0) + 
                        ($presensiStats->cuti ?? 0) + 
                        ($presensiStats->alpa ?? 0);
        
        
        $alpa = $workingJadwalIds->count() - $totalPresensi + ($presensiStats->alpa ?? 0);
        
        return [
            'hadir' => (int)($presensiStats->hadir ?? 0),
            'terlambat' => (int)($presensiStats->terlambat ?? 0),
            'sakit' => (int)($presensiStats->sakit ?? 0),
            'izin' => (int)($presensiStats->izin ?? 0),
            'cuti' => (int)($presensiStats->cuti ?? 0),
            'alpa' => max(0, (int)$alpa),
            'libur' => $libur
        ];
    }
    
    
    private function getRecentSubmissions()
    {
        
        $submissions = PengajuanIzin::with([
                'jadwalKaryawan.karyawanProject.karyawan:id,nik,nama',
                'jadwalKaryawan.karyawanProject.project:id,nama'
            ])
            ->select([
                'id', 'jadwal_karyawan_id', 'kategori_izin', 'tanggal_mulai', 
                'tanggal_selesai', 'status', 'created_at'
            ])
            ->orderByRaw("CASE 
                WHEN status = 'pending' THEN 1 
                WHEN status = 'disetujui' THEN 2 
                WHEN status = 'ditolak' THEN 3 
                WHEN status = 'dibatalkan' THEN 4 
                ELSE 5 END")
            ->orderBy('created_at', 'desc')
            ->limit(4)
            ->get();
        
        return $submissions->map(function($sub) {
            $karyawan = $sub->jadwalKaryawan->karyawanProject->karyawan;
            
            
            $start = \Carbon\Carbon::parse($sub->tanggal_mulai);
            $end = \Carbon\Carbon::parse($sub->tanggal_selesai);
            $durasiHari = $start->diffInDays($end) + 1;
            
            return [
                'id' => $sub->id,
                'karyawan' => [
                    'nik' => $karyawan->nik,
                    'nama' => $karyawan->nama
                ],
                'kategori_izin' => $sub->kategori_izin,
                'tanggal_mulai' => $sub->tanggal_mulai,
                'tanggal_selesai' => $sub->tanggal_selesai,
                'durasi_hari' => $durasiHari,
                'status' => $sub->status,
                'created_at' => $sub->created_at->format('Y-m-d H:i:s')
            ];
        });
    }
    
    
    private function getActiveProjects()
    {
        return Project::where('status', 'aktif')
            ->select('id', 'nama')
            ->orderBy('nama', 'asc')
            ->get();
    }
    
    
    private function getEmptyAttendanceStats()
    {
        return [
            'chart_data' => [
                ['name' => 'Hadir', 'value' => 0, 'color' => '#10B981'],
                ['name' => 'Terlambat', 'value' => 0, 'color' => '#F59E0B'],
                ['name' => 'Sakit', 'value' => 0, 'color' => '#EC4899'],
                ['name' => 'Izin', 'value' => 0, 'color' => '#3B82F6'],
                ['name' => 'Cuti', 'value' => 0, 'color' => '#8B5CF6'],
                ['name' => 'Alpa', 'value' => 0, 'color' => '#EF4444'],
                ['name' => 'Libur', 'value' => 0, 'color' => '#9CA3AF']
            ],
            'shifts' => [
                ['id' => 'semua', 'name' => 'Semua Shift']
            ]
        ];
    }
}