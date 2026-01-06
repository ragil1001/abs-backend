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

    // ========== INSTANCE METHODS ==========
    
    /**
     * ✅ CRITICAL FIX: Setujui pengajuan lembur dengan UPDATE PRESENSI yang benar
     */
    public function setujui($adminId, $catatan = null)
    {
        DB::beginTransaction();
        try {
            // Update status pengajuan
            $this->update([
                'status' => 'disetujui',
                'catatan_admin' => $catatan,
                'diproses_pada' => now(),
                'diproses_oleh' => $adminId
            ]);

            // Log::info("✅ Pengajuan lembur ID {$this->id} disetujui, mulai update presensi", [
            //     'kode_hari' => $this->kode_hari,
            //     'jadwal_id' => $this->jadwal_karyawan_id
            // ]);

            // ✅ CRITICAL: Handle berdasarkan kode_hari
            if ($this->kode_hari === 'L') {
                // LEMBUR DI HARI LIBUR
                $this->prosesPengajuanHariLibur();
            } else {
                // LEMBUR DI HARI KERJA (existing logic)
                $this->prosesPengajuanHariKerja();
            }

            DB::commit();
            
            // Log::info("✅ Pengajuan lembur ID {$this->id} disetujui dan presensi berhasil diupdate");
            
            return true;
        } catch (\Exception $e) {
            DB::rollback();
            // Log::error("❌ Error setujui pengajuan lembur ID {$this->id}: " . $e->getMessage());
            // Log::error($e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * ✅ CRITICAL FIX: Proses pengajuan lembur di hari libur dengan UPDATE DATABASE
     */
    private function prosesPengajuanHariLibur()
    {
        // Update presensi masuk dan pulang menjadi lembur
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
            
            // ✅ CRITICAL: Use DB::table for direct update
            DB::table('presensis')
                ->where('id', $presensiMasuk->id)
                ->update([
                    'status' => 'hadir',
                    'keterangan' => "Masuk di hari libur (Lembur disetujui) - Presensi jam {$waktuMasukFormatted}",
                    'updated_at' => now()
                ]);
            
            // Log::info("✅ Presensi masuk hari libur diupdate", [
            //     'presensi_id' => $presensiMasuk->id,
            //     'status' => 'hadir'
            // ]);
        }

        if ($presensiPulang) {
            $waktuPulangFormatted = $presensiPulang->waktu 
                ? \Carbon\Carbon::parse($presensiPulang->waktu)->format('H:i') 
                : 'tidak tercatat';
            
            // ✅ CRITICAL: Use DB::table for direct update
            DB::table('presensis')
                ->where('id', $presensiPulang->id)
                ->update([
                    'status' => 'lembur',
                    'keterangan' => "Lembur di hari libur dikonfirmasi - Jam kerja: {$this->jam_mulai} s/d {$this->jam_selesai} (Presensi pulang: {$waktuPulangFormatted})",
                    'updated_at' => now()
                ]);
            
            // Log::info("✅ Presensi pulang hari libur diupdate", [
            //     'presensi_id' => $presensiPulang->id,
            //     'status' => 'lembur'
            // ]);
        }

        // Log::info("✅ Presensi hari libur berhasil diupdate ke lembur", [
        //     'jadwal_id' => $this->jadwal_karyawan_id,
        //     'tanggal' => $this->tanggal,
        //     'jam_mulai' => $this->jam_mulai,
        //     'jam_selesai' => $this->jam_selesai
        // ]);
    }

    /**
     * ✅ CRITICAL FIX: Proses pengajuan lembur di hari kerja dengan UPDATE DATABASE
     */
    private function prosesPengajuanHariKerja()
    {
        $presensiPulang = Presensi::where('jadwal_karyawan_id', $this->jadwal_karyawan_id)
                                   ->where('tipe', 'pulang')
                                   ->first();

        if ($presensiPulang) {
            $keteranganLama = $presensiPulang->keterangan;
            
            // ✅ CRITICAL: Use DB::table for direct update
            DB::table('presensis')
                ->where('id', $presensiPulang->id)
                ->update([
                    'status' => 'lembur',
                    'keterangan' => 'Lembur - dikonfirmasi admin via pengajuan SKL' . 
                                   ($keteranganLama ? " (Sebelumnya: {$keteranganLama})" : ''),
                    'updated_at' => now()
                ]);
            
            // Log::info("✅ Presensi pulang hari kerja diupdate", [
            //     'presensi_id' => $presensiPulang->id,
            //     'jadwal_id' => $this->jadwal_karyawan_id,
            //     'tanggal' => $this->tanggal,
            //     'status' => 'lembur'
            // ]);
        } else {
            // Jika belum ada presensi pulang, buat baru dengan status lembur
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
                
                // Log::info("✅ Presensi pulang lembur dibuat", [
                //     'jadwal_id' => $this->jadwal_karyawan_id,
                //     'tanggal' => $this->tanggal
                // ]);
            }
        }
    }

    /**
     * Tolak pengajuan lembur
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

            // ✅ CRITICAL: Jika lembur di hari libur ditolak, kembalikan ke status libur
            if ($this->kode_hari === 'L') {
                $this->kembalikanKeStatusLibur();
            } else {
                // ✅ Untuk hari kerja, kembalikan dari lembur_pending ke hadir
                $this->kembalikanKeStatusHadir();
            }

            DB::commit();
            
            // Log::info("Pengajuan lembur ID {$this->id} ditolak");
            
            return true;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * ✅ NEW: Kembalikan presensi ke status libur jika pengajuan ditolak
     */
    private function kembalikanKeStatusLibur()
    {
        // Update kembali ke status libur
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

        // Log::info("Presensi dikembalikan ke status libur", [
        //     'jadwal_id' => $this->jadwal_karyawan_id,
        //     'tanggal' => $this->tanggal
        // ]);
    }

    /**
     * ✅ NEW: Kembalikan presensi pulang ke status hadir jika pengajuan hari kerja ditolak
     */
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

            // Log::info("Presensi pulang dikembalikan ke status hadir", [
            //     'presensi_id' => $presensiPulang->id,
            //     'jadwal_id' => $this->jadwal_karyawan_id
            // ]);
        }
    }

    /**
     * Batalkan pengajuan lembur (oleh karyawan)
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

            // ✅ Kembalikan status presensi pulang dari lembur_pending ke hadir
            if ($this->kode_hari === 'K') {
                $this->kembalikanKeStatusHadir();
            } else {
                // Untuk hari libur, kembalikan ke libur
                $this->kembalikanKeStatusLibur();
            }

            DB::commit();
            
            // Log::info("Pengajuan lembur ID {$this->id} dibatalkan");
            
            return true;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    // ========== STATIC METHODS ==========
    
    /**
     * Cek apakah sudah ada pengajuan lembur untuk jadwal ini
     */
    public static function sudahMengajukanLembur($jadwalKaryawanId)
    {
        return self::where('jadwal_karyawan_id', $jadwalKaryawanId)
                   ->whereNotIn('status', ['dibatalkan', 'ditolak'])
                   ->exists();
    }

    // ========== BOOT METHOD ==========
    
    protected static function boot()
    {
        parent::boot();

        // Hapus file SKL saat pengajuan dihapus
        static::deleting(function ($pengajuanLembur) {
            if ($pengajuanLembur->file_skl && Storage::exists('public/' . $pengajuanLembur->file_skl)) {
                Storage::delete('public/' . $pengajuanLembur->file_skl);
                // Log::info("File SKL dihapus: {$pengajuanLembur->file_skl}");
            }
        });
    }
}