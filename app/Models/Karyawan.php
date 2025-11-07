<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Carbon\Carbon;

class Karyawan extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'nik',
        'nama',
        'no_telepon',
        'divisi_id',
        'jabatan_id',
        'jenis_kelamin',
        'tempat_lahir',
        'tanggal_lahir',
        'tanggal_bergabung',
        'tanggal_keluar',
        'username',
        'password',
        'status',
        'sisa_cuti_tahunan'
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    // ✅ UPDATED: Append active_project to JSON
    protected $appends = ['active_project_name'];

    // Relationships
    public function divisi()
    {
        return $this->belongsTo(Divisi::class);
    }

    public function jabatan()
    {
        return $this->belongsTo(Jabatan::class);
    }

    public function informasis()
    {
        return $this->belongsToMany(Informasi::class, 'informasi_karyawan')
                    ->withPivot('is_read', 'read_at')
                    ->withTimestamps();
    }

    public function informasiKaryawan()
    {
        return $this->hasMany(InformasiKaryawan::class);
    }

    public function karyawanProjects()
    {
        return $this->hasMany(KaryawanProject::class);
    }

    // ✅ CRITICAL: Active Project Relationship
     public function activeProject()
    {
        return $this->hasOne(KaryawanProject::class)
                    ->where('status', 'aktif')
                    ->with(['project' => function($query) {
                        // ✅ CRITICAL: Load ALL columns explicitly
                        $query->select([
                            'id',
                            'nama',
                            'bagian',
                            'lokasi_nama',
                            'lokasi_latitude',
                            'lokasi_longitude',
                            'tanggal_mulai',
                            'waktu_toleransi',
                            'excluded_jabatan_ids',
                            'radius',
                            'status',
                            'enabled_izin_categories',
                            'enabled_sub_kategori_izin',
                            'created_at',
                            'updated_at'
                        ]);
                    }]);
    }

    /**
     * ✅ Alternative: Get active project without select restriction
     */
    public function getActiveProjectAttribute()
    {
        return $this->karyawanProjects()
                    ->where('status', 'aktif')
                    ->with('project') // Load all columns
                    ->first();
    }

    // ✅ NEW: Accessor for active project name
    public function getActiveProjectNameAttribute()
    {
        if (!$this->relationLoaded('activeProject')) {
            $this->load('activeProject.project');
        }
        
        return $this->activeProject?->project?->nama;
    }

    // Scopes
    public function scopeAktif($query)
    {
        return $query->where('status', 'aktif');
    }

    public function scopeTidakAktif($query)
    {
        return $query->where('status', 'tidak_aktif');
    }

    public function scopeByDivisi($query, $divisiId)
    {
        return $query->where('divisi_id', $divisiId);
    }

    public function scopeByJabatan($query, $jabatanId)
    {
        return $query->where('jabatan_id', $jabatanId);
    }

    public function scopeByGender($query, $gender)
    {
        return $query->where('jenis_kelamin', $gender);
    }

    // ✅ NEW: Scope for project filter
    public function scopeByProject($query, $projectId)
    {
        if ($projectId === 'unassigned') {
            return $query->whereDoesntHave('activeProject');
        }
        
        return $query->whereHas('activeProject', function($q) use ($projectId) {
            $q->where('project_id', $projectId);
        });
    }

    // Accessors
    public function getJenisKelaminTextAttribute()
    {
        return $this->jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan';
    }

    public function getStatusTextAttribute()
    {
        return $this->status === 'aktif' ? 'Aktif' : 'Tidak Aktif';
    }

    public function getUmurAttribute()
    {
        if (!$this->tanggal_lahir) return null;
        
        return Carbon::parse($this->tanggal_lahir)->diffInYears(Carbon::now());
    }

    public function getLamaKerjaAttribute()
    {
        if (!$this->tanggal_bergabung) return null;
        
        $endDate = $this->tanggal_keluar ? Carbon::parse($this->tanggal_keluar) : Carbon::now();
        return Carbon::parse($this->tanggal_bergabung)->diffInDays($endDate);
    }

    public function getLamaKerjaTextAttribute()
    {
        $days = $this->lama_kerja;
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

    public function getIsActiveAttribute()
    {
        return $this->status === 'aktif';
    }

    // Mutators
    public function setNikAttribute($value)
    {
        $this->attributes['nik'] = strtoupper(trim($value));
    }

    public function setNamaAttribute($value)
    {
        $this->attributes['nama'] = ucwords(strtolower(trim($value)));
    }

    public function setNoTeleponAttribute($value)
    {
        if ($value) {
            $cleaned = preg_replace('/[^0-9+]/', '', $value);
            $this->attributes['no_telepon'] = $cleaned;
        } else {
            $this->attributes['no_telepon'] = null;
        }
    }

    public function getNoTeleponFormattedAttribute()
    {
        if (!$this->no_telepon) return '-';
        return $this->no_telepon;
    }

    public function setTempatLahirAttribute($value)
    {
        $this->attributes['tempat_lahir'] = ucwords(strtolower(trim($value)));
    }

    public function setUsernameAttribute($value)
    {
        $this->attributes['username'] = strtolower(trim($value));
    }

    // ========== CUTI METHODS ==========
    
    public function cekResetCutiTahunan()
    {
        if (!$this->tanggal_bergabung) return;
        
        $tanggalBergabung = Carbon::parse($this->tanggal_bergabung);
        $now = Carbon::now();
        
        $anniversaryThisYear = $tanggalBergabung->copy()->year($now->year);
        
        if ($now->greaterThanOrEqualTo($anniversaryThisYear) && $now->lessThan($anniversaryThisYear->copy()->addDay())) {
            $this->resetCutiTahunan();
        }
    }
    
    public function resetCutiTahunan()
    {
        $this->update(['sisa_cuti_tahunan' => 12]);
        \Log::info("Cuti tahunan direset untuk karyawan {$this->nama} (ID: {$this->id})");
    }
    
    public function kurangiCutiTahunan(int $jumlahHari)
    {
        if ($this->sisa_cuti_tahunan < $jumlahHari) {
            throw new \Exception("Sisa cuti tahunan tidak mencukupi. Sisa: {$this->sisa_cuti_tahunan} hari");
        }
        
        $this->decrement('sisa_cuti_tahunan', $jumlahHari);
        
        \Log::info("Cuti tahunan dikurangi {$jumlahHari} hari untuk {$this->nama}. Sisa: " . $this->fresh()->sisa_cuti_tahunan);
    }
    
    public function kembalikanCutiTahunan(int $jumlahHari)
    {
        $newSisa = min($this->sisa_cuti_tahunan + $jumlahHari, 12);
        $this->update(['sisa_cuti_tahunan' => $newSisa]);
        
        \Log::info("Cuti tahunan dikembalikan {$jumlahHari} hari untuk {$this->nama}. Sisa: {$newSisa}");
    }

    // Static methods for generating defaults
    public static function generateUsername($nik)
    {
        $username = $nik;
        
        return $username;
    }

    public static function generateDefaultPassword($tanggalLahir)
    {
        if (!$tanggalLahir) return 'password123';
        
        $date = Carbon::parse($tanggalLahir);
        return $date->format('dmY');
    }

    // Boot method for model events
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($karyawan) {
            if (empty($karyawan->username)) {
                $karyawan->username = self::generateUsername($karyawan->nik);
            }
            
            if (empty($karyawan->password)) {
                $karyawan->password = self::generateDefaultPassword($karyawan->tanggal_lahir);
            }
            
            if (is_null($karyawan->sisa_cuti_tahunan)) {
                $karyawan->sisa_cuti_tahunan = 12;
            }
        });

        static::updating(function ($karyawan) {
            if ($karyawan->isDirty('nama') && empty($karyawan->getOriginal('username'))) {
                $karyawan->username = self::generateUsername($karyawan->nik);
            }
        });
    }

    // Custom methods
    public function updateStatus($status)
    {
        $this->update([
            'status' => $status,
            'tanggal_keluar' => $status === 'tidak_aktif' ? now()->format('Y-m-d') : null
        ]);
    }

    public function aktivasi()
    {
        $this->updateStatus('aktif');
    }

    public function nonaktifkan()
    {
        $this->updateStatus('tidak_aktif');
    }

    public function resetPassword($newPassword = null)
    {
        $password = $newPassword ?: self::generateDefaultPassword($this->tanggal_lahir);
        $this->update(['password' => bcrypt($password)]);
        return $password;
    }

    // Query helpers
    public static function getByNik($nik)
    {
        return self::where('nik', $nik)->first();
    }

    public static function getByUsername($username)
    {
        return self::where('username', $username)->first();
    }

    public static function getAktif()
    {
        return self::aktif()->with(['divisi', 'jabatan', 'activeProject.project'])->get();
    }

    public static function getTidakAktif()
    {
        return self::tidakAktif()->with(['divisi', 'jabatan', 'activeProject.project'])->get();
    }

    public static function searchByName($name)
    {
        return self::where('nama', 'like', '%' . $name . '%')
                  ->with(['divisi', 'jabatan', 'activeProject.project'])
                  ->get();
    }
}