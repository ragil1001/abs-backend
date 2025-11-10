<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class KaryawanProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'karyawan_id',
        'project_id',
        'tanggal_assign',
        'tanggal_selesai',
        'status',
        'keterangan'
    ];

    protected $casts = [
        'tanggal_assign' => 'date',
        'tanggal_selesai' => 'date',
    ];

    protected $dates = [
        'tanggal_assign',
        'tanggal_selesai',
        'created_at',
        'updated_at'
    ];

    
    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    
    public function scopeAktif($query)
    {
        return $query->where('status', 'aktif');
    }

    public function scopeTidakAktif($query)
    {
        return $query->where('status', 'tidak_aktif');
    }

    public function scopeByProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeByKaryawan($query, $karyawanId)
    {
        return $query->where('karyawan_id', $karyawanId);
    }

    
    public function getIsAktifAttribute()
    {
        return $this->status === 'aktif';
    }

    public function getDurasiKerjaAttribute()
    {
        if (!$this->tanggal_assign) return null;
        
        $endDate = $this->tanggal_selesai ?: Carbon::now();
        return $this->tanggal_assign->diffInDays($endDate);
    }

    public function getDurasiKerjaTextAttribute()
    {
        $days = $this->durasi_kerja;
        if (!$days) return '-';
        
        $years = floor($days / 365);
        $months = floor(($days % 365) / 30);
        $remainingDays = $days % 30;
        
        $text = '';
        if ($years > 0) $text .= $years . ' tahun ';
        if ($months > 0) $text .= $months . ' bulan ';
        if ($remainingDays > 0) $text .= $remainingDays . ' hari';
        
        return trim($text) ?: '0 hari';
    }

    
    public static function getKaryawanAktifByProject($projectId)
    {
        return self::with(['karyawan.divisi', 'karyawan.jabatan'])
                   ->where('project_id', $projectId)
                   ->aktif()
                   ->get();
    }

    public static function getProjectAktifByKaryawan($karyawanId)
    {
        return self::with('project')
                   ->where('karyawan_id', $karyawanId)
                   ->aktif()
                   ->first();
    }

    public static function checkKaryawanHasActiveProject($karyawanId)
    {
        return self::where('karyawan_id', $karyawanId)
                   ->aktif()
                   ->exists();
    }

    
    public function nonaktifkan($tanggalSelesai, $keterangan = null)
    {
        $this->update([
            'status' => 'tidak_aktif',
            'tanggal_selesai' => $tanggalSelesai,
            'keterangan' => $keterangan
        ]);
    }

    public function aktifkanKembali()
    {
        
        $hasActiveProject = self::checkKaryawanHasActiveProject($this->karyawan_id);
        
        if ($hasActiveProject) {
            throw new \Exception('Karyawan sudah aktif di project lain. Nonaktifkan terlebih dahulu.');
        }

        $this->update([
            'status' => 'aktif',
            'tanggal_selesai' => null,
            'keterangan' => null
        ]);
    }

    
    protected static function boot()
    {
        parent::boot();

        
        static::saving(function ($karyawanProject) {
            if ($karyawanProject->status === 'aktif') {
                $exists = self::where('karyawan_id', $karyawanProject->karyawan_id)
                             ->where('id', '!=', $karyawanProject->id)
                             ->aktif()
                             ->exists();
                
                if ($exists) {
                    throw new \Exception('Karyawan sudah aktif di project lain. Nonaktifkan terlebih dahulu.');
                }
            }
        });
    }
}