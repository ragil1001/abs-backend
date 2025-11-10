<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
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
        'enabled_sub_kategori_izin'
    ];

    protected $casts = [
        'lokasi_latitude' => 'decimal:8',
        'lokasi_longitude' => 'decimal:8',
        'waktu_toleransi' => 'integer',
        'excluded_jabatan_ids' => 'array',
        'enabled_izin_categories' => 'array',
        'enabled_sub_kategori_izin' => 'array'
    ];

    protected $appends = ['excluded_jabatans'];

    public function karyawanProjects()
    {
        return $this->hasMany(KaryawanProject::class);
    }

    public function activeKaryawans()
    {
        return $this->hasMany(KaryawanProject::class)
                    ->where('status', 'aktif')
                    ->with(['karyawan.divisi', 'karyawan.jabatan']);
    }

    public function getTotalKaryawanAttribute()
    {
        return $this->karyawanProjects()->where('status', 'aktif')->count();
    }

    public function getLokasiAttribute()
{
    
    $attributes = $this->getAttributes();
    
    $nama = $attributes['lokasi_nama'] ?? null;
    $latitude = $attributes['lokasi_latitude'] ?? null;
    $longitude = $attributes['lokasi_longitude'] ?? null;
    
    
    
    
    
    
    
    
    
    
    
    
    if ($latitude === null || $longitude === null || $latitude == 0 || $longitude == 0) {
        
        
        
        
        
        
        return null; 
    }
    
    return [
        'nama' => $nama,
        'latitude' => (float) $latitude,
        'longitude' => (float) $longitude
    ];
}


public function setLokasiAttribute($value)
{
    if (is_array($value)) {
        $this->attributes['lokasi_nama'] = $value['nama'] ?? null;
        $this->attributes['lokasi_latitude'] = isset($value['latitude']) ? (float)$value['latitude'] : null;
        $this->attributes['lokasi_longitude'] = isset($value['longitude']) ? (float)$value['longitude'] : null;
        
        
        
        
        
        
    }
}

    public function shiftProjects()
    {
        return $this->hasMany(ShiftProject::class);
    }

    public function getExcludedJabatansAttribute()
    {
        if (empty($this->excluded_jabatan_ids)) {
            return [];
        }

        return Jabatan::whereIn('id', $this->excluded_jabatan_ids)
                      ->select('id', 'nama')
                      ->get()
                      ->toArray();
    }

    
    public function isJabatanExcluded($jabatanId)
    {
        if (empty($this->excluded_jabatan_ids)) {
            return false;
        }

        
        
        
        $jabatanIdInt = (int) $jabatanId;
        
        
        $excludedIdsInt = array_map('intval', $this->excluded_jabatan_ids);
        
        $isExcluded = in_array($jabatanIdInt, $excludedIdsInt, true);
        
        
        
        
        
        
        
        
        
        
        
        return $isExcluded;
    }

    public function loadWithShifts()
    {
        return $this->load('shiftProjects');
    }

    public function getShiftsAttribute()
    {
        if (!$this->relationLoaded('shiftProjects')) {
            $this->load('shiftProjects');
        }
        
        return $this->shiftProjects->map(function($shift) {
            return [
                'id' => $shift->id,
                'kode' => $shift->kode,
                'waktu_mulai' => substr($shift->waktu_mulai, 0, 5),
                'waktu_selesai' => substr($shift->waktu_selesai, 0, 5),
            ];
        })->toArray();
    }

    public function getEnabledKategoriIzin()
{
    
    $defaultCategories = [
        PengajuanIzin::KATEGORI_SAKIT,
        PengajuanIzin::KATEGORI_IZIN,
        PengajuanIzin::KATEGORI_CUTI_TAHUNAN,
        PengajuanIzin::KATEGORI_CUTI_KHUSUS
    ];
    
    
    
    
    
    
    
    
    
    if (empty($this->enabled_izin_categories)) {
        
        
        
        return $defaultCategories;
    }
    
    
    
    
    
    return $this->enabled_izin_categories;
}

public function getEnabledSubKategoriIzin()
{
    $defaultSubCategories = [
        PengajuanIzin::SUB_PERNIKAHAN_KARYAWAN,
        PengajuanIzin::SUB_PERNIKAHAN_ANAK,
        PengajuanIzin::SUB_ISTRI_MELAHIRKAN,
        PengajuanIzin::SUB_KEMATIAN_KELUARGA,
        PengajuanIzin::SUB_KEMATIAN_SERUMAH,
        PengajuanIzin::SUB_KHITANAN_BAPTIS
    ];
    
    
    
    
    
    
    
    if (empty($this->enabled_sub_kategori_izin)) {
        return $defaultSubCategories;
    }
    
    return $this->enabled_sub_kategori_izin;
}

    
    public function isKategoriIzinEnabled($kategoriIzin)
    {
        return in_array($kategoriIzin, $this->getEnabledKategoriIzin(), true);
    }

    
    public function isSubKategoriEnabled($subKategori)
    {
        return in_array($subKategori, $this->getEnabledSubKategoriIzin(), true);
    }
}