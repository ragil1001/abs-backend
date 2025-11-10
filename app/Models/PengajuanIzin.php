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

    
    const KATEGORI_SAKIT = 'sakit';
    const KATEGORI_IZIN = 'izin';
    const KATEGORI_CUTI_TAHUNAN = 'cuti_tahunan';
    const KATEGORI_CUTI_KHUSUS = 'cuti_khusus';

    
    const SUB_PERNIKAHAN_KARYAWAN = 'pernikahan_karyawan'; 
    const SUB_PERNIKAHAN_ANAK = 'pernikahan_anak'; 
    const SUB_ISTRI_MELAHIRKAN = 'istri_melahirkan'; 
    const SUB_KEMATIAN_KELUARGA = 'kematian_keluarga'; 
    const SUB_KEMATIAN_SERUMAH = 'kematian_serumah'; 
    const SUB_KHITANAN_BAPTIS = 'khitanan_baptis'; 

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

    
    
    public function jadwalKaryawan()
    {
        return $this->belongsTo(JadwalKaryawan::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'diproses_oleh');
    }

    
    
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

    
    
    public function setujui($adminId, $catatan = null)
{
    DB::beginTransaction();
    try {
        $karyawanProject = $this->jadwalKaryawan->karyawanProject;
        $karyawan = $karyawanProject->karyawan;
        $project = $karyawanProject->project;
        
        
        $validation = PengajuanIzin::validateKategoriForProject($project, $this->kategori_izin);
        if (!$validation['valid']) {
            throw new \Exception('Kategori izin tidak valid: ' . $validation['message']);
        }
        
        
        if ($this->kategori_izin === self::KATEGORI_CUTI_KHUSUS) {
            $subValidation = PengajuanIzin::validateSubKategoriForProject($project, $this->sub_kategori_izin);
            if (!$subValidation['valid']) {
                throw new \Exception('Sub kategori tidak valid: ' . $subValidation['message']);
            }
        }
        
        
        if ($this->kategori_izin === self::KATEGORI_CUTI_TAHUNAN) {
            $karyawan->kurangiCutiTahunan($this->durasi_hari);
        }
        
        
        $this->update([
            'status' => 'disetujui',
            'catatan_admin' => $catatan,
            'diproses_pada' => now(),
            'diproses_oleh' => $adminId
        ]);

        
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
            
            
            if ($shiftCode === 'L' || empty($shiftCode)) {
                $skippedCount++;
                
                continue;
            }

            
            $keterangan = $this->jenis_izin_lengkap;
            if ($this->keterangan) {
                $keterangan .= ": " . $this->keterangan;
            }

            
            foreach (['masuk', 'pulang'] as $tipe) {
                $existingPresensi = \App\Models\Presensi::where('jadwal_karyawan_id', $jadwal->id)
                    ->where('tipe', $tipe)
                    ->first();

                if ($existingPresensi) {
                    
                    $existingPresensi->update([
                        'status' => 'izin',
                        'kategori_izin' => $this->kategori_izin, 
                        'keterangan' => $keterangan,
                        
                    ]);
                    $updatedCount++;
                    
                    
                    
                    
                    
                } else {
                    \App\Models\Presensi::create([
                        'jadwal_karyawan_id' => $jadwal->id,
                        'tipe' => $tipe,
                        'tanggal' => $jadwal->tanggal,
                        'status' => 'izin',
                        'kategori_izin' => $this->kategori_izin, 
                        'keterangan' => $keterangan,
                        'waktu' => null,
                        'latitude' => null,
                        'longitude' => null,
                        'foto' => null
                    ]);
                    $createdCount++;
                    
                    
                    
                    
                }
            }
        }

        
        
        
        
        
        

        DB::commit();
        return [
            'success' => true,
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'skipped_count' => $skippedCount
        ];
    } catch (\Exception $e) {
        DB::rollback();
        
        throw $e;
    }
}

    
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

    
    
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($pengajuan) {
            if ($pengajuan->file_dokumen && Storage::exists('public/' . $pengajuan->file_dokumen)) {
                Storage::delete('public/' . $pengajuan->file_dokumen);
            }
        });
        
        
        static::updating(function ($pengajuan) {
            if ($pengajuan->isDirty('status')) {
                $oldStatus = $pengajuan->getOriginal('status');
                $newStatus = $pengajuan->status;
                
                
                if ($oldStatus === 'disetujui' && 
                    in_array($newStatus, ['ditolak', 'dibatalkan']) &&
                    $pengajuan->kategori_izin === self::KATEGORI_CUTI_TAHUNAN) {
                    
                    $karyawan = $pengajuan->jadwalKaryawan->karyawanProject->karyawan;
                    $karyawan->kembalikanCutiTahunan($pengajuan->durasi_hari);
                }
            }
        });
    }

    
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