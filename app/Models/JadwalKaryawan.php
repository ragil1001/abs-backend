<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class JadwalKaryawan extends Model
{
    use HasFactory;

    protected $table = 'jadwal_karyawans';

    protected $fillable = [
        'karyawan_project_id',
        'tanggal',
        'bulan',
        'shift_code',
        'status',
        'keterangan'
    ];

    // CRITICAL FIX: Remove date casting - let database handle it as plain date
    // No $casts for tanggal!
    
    // Remove $dates array completely to prevent Carbon from converting
    // protected $dates = [];

    // ========== RELATIONSHIPS ==========
    
    public function karyawanProject()
    {
        return $this->belongsTo(KaryawanProject::class);
    }

    public function karyawan()
    {
        return $this->hasOneThrough(
            Karyawan::class,
            KaryawanProject::class,
            'id',
            'id',
            'karyawan_project_id',
            'karyawan_id'
        );
    }

    public function project()
    {
        return $this->hasOneThrough(
            Project::class,
            KaryawanProject::class,
            'id',
            'id',
            'karyawan_project_id',
            'project_id'
        );
    }

    // ========== SCOPES ==========
    
    public function scopeByProject($query, $projectId)
    {
        return $query->whereHas('karyawanProject', function($q) use ($projectId) {
            $q->where('project_id', $projectId);
        });
    }

    public function scopeByKaryawan($query, $karyawanId)
    {
        return $query->whereHas('karyawanProject', function($q) use ($karyawanId) {
            $q->where('karyawan_id', $karyawanId);
        });
    }

    public function scopeByBulan($query, $bulan)
    {
        return $query->where('bulan', $bulan);
    }

    public function scopeByTanggal($query, $tanggal)
    {
        return $query->where('tanggal', $tanggal);
    }

    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        // Use simple where instead of whereDate to avoid timezone conversion
        return $query->where('tanggal', '>=', $startDate)
                     ->where('tanggal', '<=', $endDate);
    }

    public function scopeByShiftCode($query, $shiftCode)
    {
        return $query->where('shift_code', $shiftCode);
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeAbsent($query)
    {
        return $query->where('status', 'absent');
    }

    // ========== ACCESSORS ==========
    
    public function getStatusTextAttribute()
    {
        return match($this->status) {
            'scheduled' => 'Dijadwalkan',
            'completed' => 'Selesai',
            'absent' => 'Tidak Hadir',
            default => 'Unknown'
        };
    }

    public function getIsLiburAttribute()
    {
        return strtoupper($this->shift_code) === 'L';
    }

    public function getTanggalFormattedAttribute()
    {
        // tanggal is now a plain string in Y-m-d format
        $date = \DateTime::createFromFormat('Y-m-d', $this->tanggal);
        return $date ? $date->format('d/m/Y') : $this->tanggal;
    }

    public function getHariAttribute()
    {
        $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $date = \DateTime::createFromFormat('Y-m-d', $this->tanggal);
        return $date ? $days[$date->format('w')] : '-';
    }

    // ========== STATIC METHODS ==========
    
    public static function bulkInsertJadwal($karyawanProjectId, $periodStart, $shifts)
    {
        $insertData = [];
        
        for ($index = 0; $index < count($shifts); $index++) {
            // Calculate date without Carbon
            $date = new \DateTime($periodStart, new \DateTimeZone('UTC'));
            $date->modify("+{$index} days");
            $tanggalString = $date->format('Y-m-d');
            $bulan = substr($tanggalString, 0, 7);
            
            $insertData[] = [
                'karyawan_project_id' => $karyawanProjectId,
                'tanggal' => $tanggalString,
                'bulan' => $bulan,
                'shift_code' => $shifts[$index] ?: 'L',
                'status' => 'scheduled',
                'keterangan' => null,
                'created_at' => now(),
                'updated_at' => now()
            ];
        }
        
        // Use DB::table to avoid Eloquent date casting
        return \DB::table('jadwal_karyawans')->insert($insertData);
    }

    public static function updateOrCreateJadwal($karyawanProjectId, $tanggal, $shiftCode)
    {
        // Use plain string date
        $bulan = substr($tanggal, 0, 7);
        
        return \DB::table('jadwal_karyawans')->updateOrInsert(
            [
                'karyawan_project_id' => $karyawanProjectId,
                'tanggal' => $tanggal
            ],
            [
                'shift_code' => $shiftCode ?: 'L',
                'bulan' => $bulan,
                'status' => 'scheduled',
                'updated_at' => now()
            ]
        );
    }

    public static function deleteByPeriode($karyawanProjectId, $startDate, $endDate)
    {
        return self::where('karyawan_project_id', $karyawanProjectId)
                   ->where('tanggal', '>=', $startDate)
                   ->where('tanggal', '<=', $endDate)
                   ->delete();
    }

    public static function getJadwalByProject($projectId, $startDate, $endDate)
    {
        return self::with(['karyawanProject.karyawan.divisi', 'karyawanProject.karyawan.jabatan'])
                   ->byProject($projectId)
                   ->where('tanggal', '>=', $startDate)
                   ->where('tanggal', '<=', $endDate)
                   ->orderBy('tanggal')
                   ->get()
                   ->groupBy('karyawan_project_id');
    }

    public static function validateShiftCode($projectId, $shiftCode)
    {
        if (empty($shiftCode)) return true;
        
        $validCodes = \App\Models\ShiftProject::where('project_id', $projectId)
                                  ->pluck('kode')
                                  ->map(fn($code) => strtoupper($code))
                                  ->toArray();
        
        $validCodes[] = 'L';
        
        return in_array(strtoupper($shiftCode), $validCodes);
    }

    /**
     * Check apakah jadwal ini hasil tukar shift
     */
    public function isDitukar()
    {
        return $this->keterangan && str_contains($this->keterangan, 'Ditukar dengan');
    }

    /**
     * Get info tukar shift jika ada
     */
    public function getTukarShiftInfo()
    {
        if (!$this->isDitukar()) {
            return null;
        }

        // Extract ID tukar shift dari keterangan
        preg_match('/ID Tukar: (\d+)/', $this->keterangan, $matches);
        $tukarShiftId = $matches[1] ?? null;

        if ($tukarShiftId) {
            return \App\Models\TukarShift::find($tukarShiftId);
        }

        return null;
    }
}