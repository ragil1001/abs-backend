<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Presensi extends Model
{
    use HasFactory;

    protected $fillable = [
        'jadwal_karyawan_id',
        'tanggal',
        'tipe',
        'status',
        'kategori_izin',
        'waktu',
        'latitude',
        'longitude',
        'foto',
        'keterangan'
    ];

    protected $casts = [
        'tanggal' => 'date',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    protected $dates = [
        'tanggal',
        'created_at',
        'updated_at'
    ];

    
    
    public function jadwalKaryawan()
    {
        return $this->belongsTo(JadwalKaryawan::class);
    }

    public function karyawanProject()
    {
        return $this->hasOneThrough(
            KaryawanProject::class,
            JadwalKaryawan::class,
            'id',
            'id',
            'jadwal_karyawan_id',
            'karyawan_project_id'
        );
    }

    
    
    public function scopeByJadwal($query, $jadwalId)
    {
        return $query->where('jadwal_karyawan_id', $jadwalId);
    }

    public function scopeByTanggal($query, $tanggal)
    {
        return $query->whereDate('tanggal', $tanggal);
    }

    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('tanggal', [$startDate, $endDate]);
    }

    public function scopeByTipe($query, $tipe)
    {
        return $query->where('tipe', $tipe);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeMasuk($query)
    {
        return $query->where('tipe', 'masuk');
    }

    public function scopePulang($query)
    {
        return $query->where('tipe', 'pulang');
    }

    public function scopeHadir($query)
    {
        return $query->where('status', 'hadir');
    }

    public function scopeTerlambat($query)
    {
        return $query->where('status', 'terlambat');
    }

    public function scopeLembur($query)
    {
        return $query->whereIn('status', ['lembur_pending', 'lembur']);
    }

    public function scopeLemburPending($query)
    {
        return $query->where('status', 'lembur_pending');
    }

    public function scopeAlpa($query)
    {
        return $query->where('status', 'alpa');
    }

    public function scopeByProject($query, $projectId)
    {
        return $query->whereHas('jadwalKaryawan.karyawanProject', function($q) use ($projectId) {
            $q->where('project_id', $projectId);
        });
    }

    public function scopeByKaryawan($query, $karyawanId)
    {
        return $query->whereHas('jadwalKaryawan.karyawanProject', function($q) use ($karyawanId) {
            $q->where('karyawan_id', $karyawanId);
        });
    }

    
    
    public function getStatusTextAttribute()
    {
        return match($this->status) {
            'hadir' => 'Hadir',
            'izin' => 'Izin',
            'terlambat' => 'Terlambat',
            'lembur_pending' => 'Lembur (Pending)',
            'lembur' => 'Lembur',
            'pulang_cepat' => 'Pulang Cepat',
            'tidak_presensi_pulang' => 'Tidak Presensi Pulang',
            'alpa' => 'Alpa',
            'libur' => 'Libur',
            default => 'Unknown'
        };
    }

    public function getTipeTextAttribute()
    {
        return $this->tipe === 'masuk' ? 'Presensi Masuk' : 'Presensi Pulang';
    }

    public function getFotoUrlAttribute()
    {
        if (!$this->foto) return null;
        return Storage::url($this->foto);
    }

    
    public function getStatusKodeAttribute()
{
    
    if ($this->status === 'libur') return 'L';
    if ($this->status === 'alpa') return 'A';
    
    
    if ($this->status === 'izin') {
        switch ($this->kategori_izin) {
            case 'sakit':
                return 'S';
            case 'izin':
                
                return 'I';
            case 'cuti_tahunan':
                return 'CT';
            case 'cuti_khusus':
                return 'IK';
            default:
                return 'I'; 
        }
    }
    
    if ($this->tipe === 'masuk') {
        if ($this->status === 'hadir') return 'H';
        if ($this->status === 'terlambat') return 'T';
    } else {
        if ($this->status === 'hadir') return 'H';
        if ($this->status === 'lembur' || $this->status === 'lembur_pending') return 'LB';
        if ($this->status === 'pulang_cepat') return 'PC';
        if ($this->status === 'tidak_presensi_pulang') return 'TPP';
    }
    
    return '-';
}

    
    
    
    public static function buatPresensiAlpa($jadwalKaryawanId, $tanggal)
    {
        try {
            DB::beginTransaction();
            
            $existingMasuk = self::where('jadwal_karyawan_id', $jadwalKaryawanId)
                                ->where('tipe', 'masuk')
                                ->first();
            
            if ($existingMasuk) {
                DB::rollBack();
                
                return null;
            }
            
            $presensiMasuk = self::create([
                'jadwal_karyawan_id' => $jadwalKaryawanId,
                'tanggal' => $tanggal,
                'tipe' => 'masuk',
                'status' => 'alpa',
                'kategori_izin' => null,
                'waktu' => null,
                'latitude' => null,
                'longitude' => null,
                'foto' => null,
                'keterangan' => 'Tidak melakukan presensi masuk (Alpa)'
            ]);
            
            self::create([
                'jadwal_karyawan_id' => $jadwalKaryawanId,
                'tanggal' => $tanggal,
                'tipe' => 'pulang',
                'status' => 'alpa',
                'kategori_izin' => null,
                'waktu' => null,
                'latitude' => null,
                'longitude' => null,
                'foto' => null,
                'keterangan' => 'Tidak melakukan presensi pulang (Alpa)'
            ]);
            
            DB::commit();
            
            $presensiMasuk->load([
                'jadwalKaryawan.karyawanProject.karyawan.divisi',
                'jadwalKaryawan.karyawanProject.karyawan.jabatan',
                'jadwalKaryawan.karyawanProject.project.shiftProjects'
            ]);
            
            
            
            
            
            
            return $presensiMasuk;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            throw $e;
        }
    }

    
    public static function cekTidakPresensiPulang($jadwalKaryawanId, $tanggal)
    {
        try {
            $presensiPulang = self::where('jadwal_karyawan_id', $jadwalKaryawanId)
                                  ->where('tipe', 'pulang')
                                  ->first();
            
            if ($presensiPulang) {
                return null;
            }
            
            $presensiMasuk = self::where('jadwal_karyawan_id', $jadwalKaryawanId)
                                 ->where('tipe', 'masuk')
                                 ->whereIn('status', ['hadir', 'terlambat'])
                                 ->first();
            
            if (!$presensiMasuk) {
                return null;
            }
            
            $presensi = self::create([
                'jadwal_karyawan_id' => $jadwalKaryawanId,
                'tanggal' => $tanggal,
                'tipe' => 'pulang',
                'status' => 'tidak_presensi_pulang',
                'kategori_izin' => null,
                'waktu' => null,
                'latitude' => null,
                'longitude' => null,
                'foto' => null,
                'keterangan' => 'Tidak melakukan presensi pulang'
            ]);
            
            
            
            
            
            
            return $presensi;
            
        } catch (\Exception $e) {
            
            throw $e;
        }
    }

    
    public static function buatPresensiLibur($jadwalKaryawanId, $tanggal)
    {
        try {
            DB::beginTransaction();
            
            $existing = self::where('jadwal_karyawan_id', $jadwalKaryawanId)->first();
            
            if ($existing) {
                DB::rollBack();
                return null;
            }
            
            self::create([
                'jadwal_karyawan_id' => $jadwalKaryawanId,
                'tanggal' => $tanggal,
                'tipe' => 'masuk',
                'status' => 'libur',
                'kategori_izin' => null,
                'waktu' => null,
                'latitude' => null,
                'longitude' => null,
                'foto' => null,
                'keterangan' => 'Hari libur'
            ]);
            
            self::create([
                'jadwal_karyawan_id' => $jadwalKaryawanId,
                'tanggal' => $tanggal,
                'tipe' => 'pulang',
                'status' => 'libur',
                'kategori_izin' => null,
                'waktu' => null,
                'latitude' => null,
                'longitude' => null,
                'foto' => null,
                'keterangan' => 'Hari libur'
            ]);
            
            DB::commit();
            
            
            
            
            
            
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            throw $e;
        }
    }

    
    public static function hitungSelisihMenit($waktu1, $waktu2)
    {
        $time1 = Carbon::parse($waktu1);
        $time2 = Carbon::parse($waktu2);
        return abs($time1->diffInMinutes($time2));
    }

    
    public static function formatMenit($menit)
    {
        if ($menit < 60) {
            return $menit . ' menit';
        }
        
        $jam = floor($menit / 60);
        $sisaMenit = $menit % 60;
        
        if ($sisaMenit > 0) {
            return $jam . ' jam ' . $sisaMenit . ' menit';
        }
        
        return $jam . ' jam';
    }

    
    
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($presensi) {
            if ($presensi->foto && Storage::exists($presensi->foto)) {
                Storage::delete($presensi->foto);
            }
        });

        static::saving(function ($presensi) {
            self::cleanupOldFiles();
        });
    }

    
    public static function cleanupOldFiles()
    {
        $cutoffDate = Carbon::now()->subDays(33);
        
        $oldPresensis = self::where('created_at', '<', $cutoffDate)
                            ->whereNotNull('foto')
                            ->get();

        foreach ($oldPresensis as $presensi) {
            if ($presensi->foto && Storage::exists($presensi->foto)) {
                Storage::delete($presensi->foto);
                $presensi->update(['foto' => null]);
            }
        }
    }
}