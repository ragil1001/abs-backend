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
    // Get raw values from database
    $attributes = $this->getAttributes();
    
    $nama = $attributes['lokasi_nama'] ?? null;
    $latitude = $attributes['lokasi_latitude'] ?? null;
    $longitude = $attributes['lokasi_longitude'] ?? null;
    
    // ✅ DEBUG LOG
    // \Log::info('🏢 Project getLokasiAttribute', [
    //     'project_id' => $this->id,
    //     'lokasi_nama' => $nama,
    //     'lokasi_latitude' => $latitude,
    //     'lokasi_longitude' => $longitude,
    //     'latitude_type' => gettype($latitude),
    //     'longitude_type' => gettype($longitude),
    // ]);
    
    // ✅ CRITICAL: Return null if coordinates are missing
    if ($latitude === null || $longitude === null || $latitude == 0 || $longitude == 0) {
        // \Log::warning('⚠️ Project location is NULL or ZERO', [
        //     'project_id' => $this->id,
        //     'latitude' => $latitude,
        //     'longitude' => $longitude,
        // ]);
        
        return null; // Return null instead of empty array
    }
    
    return [
        'nama' => $nama,
        'latitude' => (float) $latitude,
        'longitude' => (float) $longitude
    ];
}

/**
 * ✅ FIXED: Set lokasi with validation
 */
public function setLokasiAttribute($value)
{
    if (is_array($value)) {
        $this->attributes['lokasi_nama'] = $value['nama'] ?? null;
        $this->attributes['lokasi_latitude'] = isset($value['latitude']) ? (float)$value['latitude'] : null;
        $this->attributes['lokasi_longitude'] = isset($value['longitude']) ? (float)$value['longitude'] : null;
        
        // \Log::info('✅ Setting project location', [
        //     'lokasi_nama' => $this->attributes['lokasi_nama'],
        //     'lokasi_latitude' => $this->attributes['lokasi_latitude'],
        //     'lokasi_longitude' => $this->attributes['lokasi_longitude'],
        // ]);
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

    /**
     * ✅ CRITICAL FIX: Helper method untuk cek apakah jabatan dikecualikan
     * Memperbaiki type comparison issue antara string dan integer
     */
    public function isJabatanExcluded($jabatanId)
    {
        if (empty($this->excluded_jabatan_ids)) {
            return false;
        }

        // ✅ CRITICAL: Convert both to integers for comparison
        // Database bisa return string "1" atau integer 1
        // Karyawan jabatan_id bisa string atau integer
        $jabatanIdInt = (int) $jabatanId;
        
        // Convert all excluded IDs to integers
        $excludedIdsInt = array_map('intval', $this->excluded_jabatan_ids);
        
        $isExcluded = in_array($jabatanIdInt, $excludedIdsInt, true);
        
        // Log for debugging
        // \Log::info('isJabatanExcluded Check', [
        //     'input_jabatan_id' => $jabatanId,
        //     'input_type' => gettype($jabatanId),
        //     'converted_jabatan_id' => $jabatanIdInt,
        //     'excluded_jabatan_ids_raw' => $this->excluded_jabatan_ids,
        //     'excluded_jabatan_ids_int' => $excludedIdsInt,
        //     'is_excluded' => $isExcluded
        // ]);
        
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
    // ✅ Default categories jika tidak ada konfigurasi
    $defaultCategories = [
        PengajuanIzin::KATEGORI_SAKIT,
        PengajuanIzin::KATEGORI_IZIN,
        PengajuanIzin::KATEGORI_CUTI_TAHUNAN,
        PengajuanIzin::KATEGORI_CUTI_KHUSUS
    ];
    
    // ✅ DEBUG: Log untuk check
    // \Log::info('DEBUG getEnabledKategoriIzin', [
    //     'project_id' => $this->id,
    //     'enabled_izin_categories_raw' => $this->enabled_izin_categories,
    //     'is_empty' => empty($this->enabled_izin_categories),
    // ]);
    
    // ✅ Jika null atau empty, return default
    if (empty($this->enabled_izin_categories)) {
        // \Log::info('Using default categories', [
        //     'categories' => $defaultCategories,
        // ]);
        return $defaultCategories;
    }
    
    // ✅ Return configured categories
    // \Log::info('Using configured categories', [
    //     'categories' => $this->enabled_izin_categories,
    // ]);
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
    
    // \Log::info('DEBUG getEnabledSubKategoriIzin', [
    //     'project_id' => $this->id,
    //     'enabled_sub_kategori_raw' => $this->enabled_sub_kategori_izin,
    //     'is_empty' => empty($this->enabled_sub_kategori_izin),
    // ]);
    
    if (empty($this->enabled_sub_kategori_izin)) {
        return $defaultSubCategories;
    }
    
    return $this->enabled_sub_kategori_izin;
}

    /**
     * Cek apakah kategori izin diaktifkan di project ini
     */
    public function isKategoriIzinEnabled($kategoriIzin)
    {
        return in_array($kategoriIzin, $this->getEnabledKategoriIzin(), true);
    }

    /**
     * Cek apakah sub kategori cuti khusus diaktifkan di project ini
     */
    public function isSubKategoriEnabled($subKategori)
    {
        return in_array($subKategori, $this->getEnabledSubKategoriIzin(), true);
    }
}