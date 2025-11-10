<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PengajuanLembur extends Model
{
    use HasFactory;

    protected $fillable = [
        'jadwal_karyawan_id',
        'tanggal',
        'kode_hari',
        'jam_mulai',
        'jam_selesai',
        'file_skl',
        'keterangan_karyawan',
        'status',
        'catatan_admin',
        'diproses_pada',
        'diproses_oleh'
    ];

    protected $casts = [
        'tanggal' => 'date',
        'diproses_pada' => 'datetime',
    ];

    
    
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

    public function scopeByTanggal($query, $tanggal)
    {
        return $query->where('tanggal', $tanggal);
    }

    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('tanggal', [$startDate, $endDate]);
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

    public function getKodeHariTextAttribute()
    {
        return match($this->kode_hari) {
            'K' => 'Hari Kerja',
            'L' => 'Hari Libur',
            default => 'Unknown'
        };
    }

    public function getFileSklUrlAttribute()
    {
        if (!$this->file_skl) return null;
        
        $baseUrl = config('app.url');
        
        return $baseUrl . Storage::url($this->file_skl);
    }

    
    
    
    public function setujui($adminId, $catatan = null)
    {
        DB::beginTransaction();
        try {
            
            $this->update([
                'status' => 'disetujui',
                'catatan_admin' => $catatan,
                'diproses_pada' => now(),
                'diproses_oleh' => $adminId
            ]);

            
            
            
            

            
            if ($this->kode_hari === 'L') {
                
                $this->prosesPengajuanHariLibur();
            } else {
                
                $this->prosesPengajuanHariKerja();
            }

            DB::commit();
            
            
            
            return true;
        } catch (\Exception $e) {
            DB::rollback();
            
            
            throw $e;
        }
    }

    
    private function prosesPengajuanHariLibur()
    {
        
        $presensiMasuk = Presensi::where('jadwal_karyawan_id', $this->jadwal_karyawan_id)
                                  ->where('tipe', 'masuk')
                                  ->first();

        $presensiPulang = Presensi::where('jadwal_karyawan_id', $this->jadwal_karyawan_id)
                                   ->where('tipe', 'pulang')
                                   ->first();

        if ($presensiMasuk) {
            $waktuMasukFormatted = $presensiMasuk->waktu 
                ? \Carbon\Carbon::parse($presensiMasuk->waktu)->format('H:i') 
                : 'tidak tercatat';
            
            
            DB::table('presensis')
                ->where('id', $presensiMasuk->id)
                ->update([
                    'status' => 'hadir',
                    'keterangan' => "Masuk di hari libur (Lembur disetujui) - Presensi jam {$waktuMasukFormatted}",
                    'updated_at' => now()
                ]);
            
            
            
            
            
        }

        if ($presensiPulang) {
            $waktuPulangFormatted = $presensiPulang->waktu 
                ? \Carbon\Carbon::parse($presensiPulang->waktu)->format('H:i') 
                : 'tidak tercatat';
            
            
            DB::table('presensis')
                ->where('id', $presensiPulang->id)
                ->update([
                    'status' => 'lembur',
                    'keterangan' => "Lembur di hari libur dikonfirmasi - Jam kerja: {$this->jam_mulai} s/d {$this->jam_selesai} (Presensi pulang: {$waktuPulangFormatted})",
                    'updated_at' => now()
                ]);
            
            
            
            
            
        }

        
        
        
        
        
        
    }

    
    private function prosesPengajuanHariKerja()
    {
        $presensiPulang = Presensi::where('jadwal_karyawan_id', $this->jadwal_karyawan_id)
                                   ->where('tipe', 'pulang')
                                   ->first();

        if ($presensiPulang) {
            $keteranganLama = $presensiPulang->keterangan;
            
            
            DB::table('presensis')
                ->where('id', $presensiPulang->id)
                ->update([
                    'status' => 'lembur',
                    'keterangan' => 'Lembur - dikonfirmasi admin via pengajuan SKL' . 
                                   ($keteranganLama ? " (Sebelumnya: {$keteranganLama})" : ''),
                    'updated_at' => now()
                ]);
            
            
            
            
            
            
            
        } else {
            
            $jadwal = $this->jadwalKaryawan;
            $project = $jadwal->karyawanProject->project;
            $shift = $project->shiftProjects()->where('kode', $jadwal->shift_code)->first();
            
            if ($shift) {
                DB::table('presensis')->insert([
                    'jadwal_karyawan_id' => $this->jadwal_karyawan_id,
                    'tanggal' => $this->tanggal,
                    'tipe' => 'pulang',
                    'status' => 'lembur',
                    'waktu' => null,
                    'latitude' => null,
                    'longitude' => null,
                    'foto' => null,
                    'keterangan' => 'Lembur - dikonfirmasi admin via pengajuan SKL (tidak presensi pulang)',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                
                
                
                
            }
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

            
            if ($this->kode_hari === 'L') {
                $this->kembalikanKeStatusLibur();
            } else {
                
                $this->kembalikanKeStatusHadir();
            }

            DB::commit();
            
            
            
            return true;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    
    private function kembalikanKeStatusLibur()
    {
        
        DB::table('presensis')
            ->where('jadwal_karyawan_id', $this->jadwal_karyawan_id)
            ->where('tipe', 'masuk')
            ->update([
                'status' => 'libur',
                'keterangan' => 'Hari libur',
                'updated_at' => now()
            ]);

        DB::table('presensis')
            ->where('jadwal_karyawan_id', $this->jadwal_karyawan_id)
            ->where('tipe', 'pulang')
            ->update([
                'status' => 'libur',
                'keterangan' => 'Hari libur',
                'updated_at' => now()
            ]);

        
        
        
        
    }

    
    private function kembalikanKeStatusHadir()
    {
        $presensiPulang = Presensi::where('jadwal_karyawan_id', $this->jadwal_karyawan_id)
                                   ->where('tipe', 'pulang')
                                   ->first();

        if ($presensiPulang && $presensiPulang->status === 'lembur_pending') {
            DB::table('presensis')
                ->where('id', $presensiPulang->id)
                ->update([
                    'status' => 'hadir',
                    'keterangan' => 'Pulang tepat waktu (Pengajuan lembur ditolak)',
                    'updated_at' => now()
                ]);

            
            
            
            
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

            
            if ($this->kode_hari === 'K') {
                $this->kembalikanKeStatusHadir();
            } else {
                
                $this->kembalikanKeStatusLibur();
            }

            DB::commit();
            
            
            
            return true;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    
    
    
    public static function sudahMengajukanLembur($jadwalKaryawanId)
    {
        return self::where('jadwal_karyawan_id', $jadwalKaryawanId)
                   ->whereNotIn('status', ['dibatalkan', 'ditolak'])
                   ->exists();
    }

    
    
    protected static function boot()
    {
        parent::boot();

        
        static::deleting(function ($pengajuanLembur) {
            if ($pengajuanLembur->file_skl && Storage::exists('public/' . $pengajuanLembur->file_skl)) {
                Storage::delete('public/' . $pengajuanLembur->file_skl);
                
            }
        });
    }
}