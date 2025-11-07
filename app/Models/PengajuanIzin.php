<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PengajuanIzin extends Model
{
    use HasFactory;

    // Konstanta untuk kategori izin
    const KATEGORI_SAKIT = 'sakit';
    const KATEGORI_IZIN = 'izin';
    const KATEGORI_CUTI_TAHUNAN = 'cuti_tahunan';
    const KATEGORI_CUTI_KHUSUS = 'cuti_khusus';

    // Konstanta untuk sub kategori cuti khusus
    const SUB_PERNIKAHAN_KARYAWAN = 'pernikahan_karyawan'; // 3 hari
    const SUB_PERNIKAHAN_ANAK = 'pernikahan_anak'; // 2 hari
    const SUB_ISTRI_MELAHIRKAN = 'istri_melahirkan'; // 2 hari
    const SUB_KEMATIAN_KELUARGA = 'kematian_keluarga'; // 2 hari (suami/istri/anak/ortu/mertua)
    const SUB_KEMATIAN_SERUMAH = 'kematian_serumah'; // 1 hari
    const SUB_KHITANAN_BAPTIS = 'khitanan_baptis'; // 2 hari

    protected $fillable = [
        'jadwal_karyawan_id',
        'kategori_izin',
        'sub_kategori_izin',
        'deskripsi_izin',
        'durasi_otomatis',
        'tanggal_mulai',
        'tanggal_selesai',
        'file_dokumen',
        'keterangan',
        'status',
        'catatan_admin',
        'diproses_pada',
        'diproses_oleh'
    ];

    protected $casts = [
        'tanggal_mulai' => 'date',
        'tanggal_selesai' => 'date',
        'diproses_pada' => 'datetime',
    ];

    // ========== HELPER METHODS ==========
    
    /**
     * Get durasi hari berdasarkan sub kategori cuti khusus
     */
    public static function getDurasiCutiKhusus($subKategori)
    {
        $durasi = [
            self::SUB_PERNIKAHAN_KARYAWAN => 3,
            self::SUB_PERNIKAHAN_ANAK => 2,
            self::SUB_ISTRI_MELAHIRKAN => 2,
            self::SUB_KEMATIAN_KELUARGA => 2,
            self::SUB_KEMATIAN_SERUMAH => 1,
            self::SUB_KHITANAN_BAPTIS => 2,
        ];
        
        return $durasi[$subKategori] ?? 1;
    }
    
    /**
     * Get label untuk sub kategori
     */
    public static function getSubKategoriLabel($subKategori)
    {
        $labels = [
            self::SUB_PERNIKAHAN_KARYAWAN => 'Pernikahan Karyawan (3 hari)',
            self::SUB_PERNIKAHAN_ANAK => 'Pernikahan Putra/Putri (2 hari)',
            self::SUB_ISTRI_MELAHIRKAN => 'Istri Melahirkan/Keguguran (2 hari)',
            self::SUB_KEMATIAN_KELUARGA => 'Kematian Keluarga Inti (2 hari)',
            self::SUB_KEMATIAN_SERUMAH => 'Kematian Orang Serumah (1 hari)',
            self::SUB_KHITANAN_BAPTIS => 'Khitanan/Baptisan Anak (2 hari)',
        ];
        
        return $labels[$subKategori] ?? $subKategori;
    }
    
    /**
     * Get kode untuk rekap presensi
     */
    public function getKodeRekap()
    {
        switch ($this->kategori_izin) {
            case self::KATEGORI_SAKIT:
                return 'S';
            case self::KATEGORI_IZIN:
                return 'I';
            case self::KATEGORI_CUTI_TAHUNAN:
                return 'CT';
            case self::KATEGORI_CUTI_KHUSUS:
                return 'IK';
            default:
                return 'I';
        }
    }

    // ========== RELATIONSHIPS ==========
    
    public function jadwalKaryawan()
    {
        return $this->belongsTo(JadwalKaryawan::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'diproses_oleh');
    }

    // ========== SCOPES ==========
    
    public function scopeByJadwal($query, $jadwalId)
    {
        return $query->where('jadwal_karyawan_id', $jadwalId);
    }

    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->where(function($q) use ($startDate, $endDate) {
            $q->whereBetween('tanggal_mulai', [$startDate, $endDate])
              ->orWhereBetween('tanggal_selesai', [$startDate, $endDate])
              ->orWhere(function($q2) use ($startDate, $endDate) {
                  $q2->where('tanggal_mulai', '<=', $startDate)
                     ->where('tanggal_selesai', '>=', $endDate);
              });
        });
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeDisetujui($query)
    {
        return $query->where('status', 'disetujui');
    }

    public function scopeDitolak($query)
    {
        return $query->where('status', 'ditolak');
    }

    public function scopeDibatalkan($query)
    {
        return $query->where('status', 'dibatalkan');
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

    public function scopeByKategori($query, $kategori)
    {
        return $query->where('kategori_izin', $kategori);
    }

    // ========== ACCESSORS ==========
    
    public function getStatusTextAttribute()
    {
        return match($this->status) {
            'pending' => 'Menunggu Persetujuan',
            'disetujui' => 'Disetujui',
            'ditolak' => 'Ditolak',
            'dibatalkan' => 'Dibatalkan',
            default => 'Unknown'
        };
    }

    public function getFileDokumenUrlAttribute()
    {
        if (!$this->file_dokumen) return null;
        
        // $isMobileApp = request()->header('X-Requested-With') === 'FlutterApp';
        
        // if ($isMobileApp) {
        //     $baseUrl = 'http://10.70.173.254:8000';
        // } else {
        //     $baseUrl = config('app.url');
        // }

        $baseUrl = config('app.url');
        
        return $baseUrl . Storage::url($this->file_dokumen);
    }

    public function getDurasiHariAttribute()
    {
        return $this->tanggal_mulai->diffInDays($this->tanggal_selesai) + 1;
    }
    
    public function getJenisIzinLengkapAttribute()
    {
        $kategori = ucwords(str_replace('_', ' ', $this->kategori_izin));
        
        if ($this->kategori_izin === self::KATEGORI_CUTI_KHUSUS && $this->sub_kategori_izin) {
            return $kategori . ' - ' . self::getSubKategoriLabel($this->sub_kategori_izin);
        }
        
        return $kategori;
    }

    // ========== INSTANCE METHODS ==========
    
    public function setujui($adminId, $catatan = null)
{
    DB::beginTransaction();
    try {
        $karyawanProject = $this->jadwalKaryawan->karyawanProject;
        $karyawan = $karyawanProject->karyawan;
        $project = $karyawanProject->project;
        
        // Validasi kategori izin masih aktif
        $validation = PengajuanIzin::validateKategoriForProject($project, $this->kategori_izin);
        if (!$validation['valid']) {
            throw new \Exception('Kategori izin tidak valid: ' . $validation['message']);
        }
        
        // Jika cuti khusus, validasi sub kategori
        if ($this->kategori_izin === self::KATEGORI_CUTI_KHUSUS) {
            $subValidation = PengajuanIzin::validateSubKategoriForProject($project, $this->sub_kategori_izin);
            if (!$subValidation['valid']) {
                throw new \Exception('Sub kategori tidak valid: ' . $subValidation['message']);
            }
        }
        
        // Jika cuti tahunan, kurangi sisa cuti
        if ($this->kategori_izin === self::KATEGORI_CUTI_TAHUNAN) {
            $karyawan->kurangiCutiTahunan($this->durasi_hari);
        }
        
        // Update status pengajuan
        $this->update([
            'status' => 'disetujui',
            'catatan_admin' => $catatan,
            'diproses_pada' => now(),
            'diproses_oleh' => $adminId
        ]);

        // Ambil semua jadwal dalam periode izin
        $jadwals = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
                                 ->where('tanggal', '>=', $this->tanggal_mulai->format('Y-m-d'))
                                 ->where('tanggal', '<=', $this->tanggal_selesai->format('Y-m-d'))
                                 ->orderBy('tanggal', 'asc')
                                 ->get();

        $createdCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($jadwals as $jadwal) {
            $shiftCode = strtoupper(trim($jadwal->shift_code ?? ''));
            
            // Skip hari libur
            if ($shiftCode === 'L' || empty($shiftCode)) {
                $skippedCount++;
                // \Log::info("Skip libur untuk jadwal ID {$jadwal->id} tanggal {$jadwal->tanggal}");
                continue;
            }

            // ✅ CRITICAL: Build keterangan dengan jenis izin lengkap
            $keterangan = $this->jenis_izin_lengkap;
            if ($this->keterangan) {
                $keterangan .= ": " . $this->keterangan;
            }

            // ✅ CRITICAL FIX: Update EXISTING presensi (termasuk alpa) dengan kategori_izin
            foreach (['masuk', 'pulang'] as $tipe) {
                $existingPresensi = \App\Models\Presensi::where('jadwal_karyawan_id', $jadwal->id)
                    ->where('tipe', $tipe)
                    ->first();

                if ($existingPresensi) {
                    // ✅ UPDATE existing presensi (bisa alpa, hadir, terlambat, dll)
                    $existingPresensi->update([
                        'status' => 'izin',
                        'kategori_izin' => $this->kategori_izin, // ✅ SET kategori_izin
                        'keterangan' => $keterangan,
                        // Keep existing waktu, foto, lokasi if any
                    ]);
                    $updatedCount++;
                    // \Log::info("✅ Updated existing presensi {$tipe} (old status: {$existingPresensi->status}) to izin with kategori: {$this->kategori_izin}", [
                    //     'presensi_id' => $existingPresensi->id,
                    //     'jadwal_id' => $jadwal->id,
                    //     'tanggal' => $jadwal->tanggal
                    // ]);
                } else {
                    \App\Models\Presensi::create([
                        'jadwal_karyawan_id' => $jadwal->id,
                        'tipe' => $tipe,
                        'tanggal' => $jadwal->tanggal,
                        'status' => 'izin',
                        'kategori_izin' => $this->kategori_izin, // ✅ SET kategori_izin
                        'keterangan' => $keterangan,
                        'waktu' => null,
                        'latitude' => null,
                        'longitude' => null,
                        'foto' => null
                    ]);
                    $createdCount++;
                    // \Log::info("✅ Created new presensi {$tipe} with kategori: {$this->kategori_izin}", [
                    //     'jadwal_id' => $jadwal->id,
                    //     'tanggal' => $jadwal->tanggal
                    // ]);
                }
            }
        }

        // \Log::info("✅ Pengajuan izin ID {$this->id} disetujui", [
        //     'kategori_izin' => $this->kategori_izin,
        //     'created_count' => $createdCount,
        //     'updated_count' => $updatedCount,
        //     'skipped_count' => $skippedCount
        // ]);

        DB::commit();
        return [
            'success' => true,
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'skipped_count' => $skippedCount
        ];
    } catch (\Exception $e) {
        DB::rollback();
        // \Log::error("❌ Error setujui pengajuan izin ID {$this->id}: " . $e->getMessage());
        throw $e;
    }
}

    /**
     * Tolak pengajuan izin
     */
    public function tolak($adminId, $catatan = null)
    {
        DB::beginTransaction();
        try {
            $this->update([
                'status' => 'ditolak',
                'catatan_admin' => $catatan,
                'diproses_pada' => now(),
                'diproses_oleh' => $adminId
            ]);

            // Cek apakah tanggal izin sudah lewat dan buat presensi alpa jika perlu
            $karyawanProject = $this->jadwalKaryawan->karyawanProject;
            $project = $karyawanProject->project;
            
            $jadwals = JadwalKaryawan::where('karyawan_project_id', $karyawanProject->id)
                                     ->where('tanggal', '>=', $this->tanggal_mulai->format('Y-m-d'))
                                     ->where('tanggal', '<=', $this->tanggal_selesai->format('Y-m-d'))
                                     ->where('tanggal', '<', now()->format('Y-m-d'))
                                     ->get();

            foreach ($jadwals as $jadwal) {
                $shiftCode = strtoupper(trim($jadwal->shift_code ?? ''));
                if ($shiftCode === 'L' || empty($shiftCode)) {
                    continue;
                }

                $shift = $project->shiftProjects()->where('kode', $jadwal->shift_code)->first();

                if ($shift) {
                    $waktuSelesaiShift = Carbon::parse($jadwal->tanggal . ' ' . $shift->waktu_selesai);
                    
                    if (now()->greaterThan($waktuSelesaiShift)) {
                        $existingPresensi = \App\Models\Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                                    ->where('tipe', 'masuk')
                                                    ->first();
                        
                        if (!$existingPresensi) {
                            \App\Models\Presensi::buatPresensiAlpa($jadwal->id, $jadwal->tanggal);
                        }
                    }
                }
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Batalkan pengajuan izin (oleh karyawan)
     */
    public function batalkan()
    {
        if ($this->status !== 'pending') {
            throw new \Exception('Hanya pengajuan dengan status pending yang dapat dibatalkan');
        }

        DB::beginTransaction();
        try {
            $this->update([
                'status' => 'dibatalkan'
            ]);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    // ========== STATIC METHODS ==========
    
    public static function sudahMengajukanPeriode($karyawanProjectId, $tanggalMulai, $tanggalSelesai, $excludeId = null)
    {
        $query = self::whereHas('jadwalKaryawan', function($q) use ($karyawanProjectId) {
                    $q->where('karyawan_project_id', $karyawanProjectId);
                })
                ->whereNotIn('status', ['dibatalkan', 'ditolak'])
                ->where(function($q) use ($tanggalMulai, $tanggalSelesai) {
                    $q->whereBetween('tanggal_mulai', [$tanggalMulai, $tanggalSelesai])
                      ->orWhereBetween('tanggal_selesai', [$tanggalMulai, $tanggalSelesai])
                      ->orWhere(function($q2) use ($tanggalMulai, $tanggalSelesai) {
                          $q2->where('tanggal_mulai', '<=', $tanggalMulai)
                             ->where('tanggal_selesai', '>=', $tanggalSelesai);
                      });
                });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    // ========== BOOT METHOD ==========
    
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($pengajuan) {
            if ($pengajuan->file_dokumen && Storage::exists('public/' . $pengajuan->file_dokumen)) {
                Storage::delete('public/' . $pengajuan->file_dokumen);
            }
        });
        
        // Jika izin dibatalkan atau ditolak setelah disetujui, kembalikan cuti tahunan
        static::updating(function ($pengajuan) {
            if ($pengajuan->isDirty('status')) {
                $oldStatus = $pengajuan->getOriginal('status');
                $newStatus = $pengajuan->status;
                
                // Jika dari disetujui ke ditolak/dibatalkan dan kategori cuti tahunan
                if ($oldStatus === 'disetujui' && 
                    in_array($newStatus, ['ditolak', 'dibatalkan']) &&
                    $pengajuan->kategori_izin === self::KATEGORI_CUTI_TAHUNAN) {
                    
                    $karyawan = $pengajuan->jadwalKaryawan->karyawanProject->karyawan;
                    $karyawan->kembalikanCutiTahunan($pengajuan->durasi_hari);
                }
            }
        });
    }

    /**
 * Validasi apakah kategori izin valid untuk project
 */
public static function validateKategoriForProject(Project $project, $kategoriIzin)
{
    if (!$project->isKategoriIzinEnabled($kategoriIzin)) {
        $enabledCategories = $project->getEnabledKategoriIzin();
        $categoryLabels = array_map(function($cat) {
            return ucwords(str_replace('_', ' ', $cat));
        }, $enabledCategories);
        
        return [
            'valid' => false,
            'message' => 'Kategori izin "' . ucwords(str_replace('_', ' ', $kategoriIzin)) . 
                        '" tidak diaktifkan untuk project ini. Kategori yang tersedia: ' . 
                        implode(', ', $categoryLabels)
        ];
    }
    
    return ['valid' => true];
}

/**
 * Validasi apakah sub kategori valid untuk project
 */
public static function validateSubKategoriForProject(Project $project, $subKategori)
{
    if (!$project->isSubKategoriEnabled($subKategori)) {
        $enabledSubCategories = $project->getEnabledSubKategoriIzin();
        $subCategoryLabels = array_map(function($subCat) {
            return self::getSubKategoriLabel($subCat);
        }, $enabledSubCategories);
        
        return [
            'valid' => false,
            'message' => 'Sub kategori "' . self::getSubKategoriLabel($subKategori) . 
                        '" tidak diaktifkan untuk project ini. Sub kategori yang tersedia: ' . 
                        implode(', ', $subCategoryLabels)
        ];
    }
    
    return ['valid' => true];
}
}