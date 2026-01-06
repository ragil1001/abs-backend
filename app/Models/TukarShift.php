<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class TukarShift extends Model
{
    use HasFactory;

    protected $fillable = [
        'peminta_karyawan_id',
        'target_karyawan_id',
        'project_id',
        'jadwal_peminta_id',
        'jadwal_target_id',
        'status',
        'catatan',
        'alasan_penolakan',
        'tanggal_pengajuan',
        'tanggal_diproses',
        'tanggal_dibatalkan',
    ];

    protected $casts = [
        'tanggal_pengajuan' => 'datetime',
        'tanggal_diproses' => 'datetime',
        'tanggal_dibatalkan' => 'datetime',
    ];

    /**
     * Relasi ke karyawan peminta
     */
    public function peminta()
    {
        return $this->belongsTo(Karyawan::class, 'peminta_karyawan_id');
    }

    /**
     * Relasi ke karyawan target
     */
    public function target()
    {
        return $this->belongsTo(Karyawan::class, 'target_karyawan_id');
    }

    /**
     * Relasi ke project
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Relasi ke jadwal peminta
     */
    public function jadwalPeminta()
    {
        return $this->belongsTo(JadwalKaryawan::class, 'jadwal_peminta_id');
    }

    /**
     * Relasi ke jadwal target
     */
    public function jadwalTarget()
    {
        return $this->belongsTo(JadwalKaryawan::class, 'jadwal_target_id');
    }

    /**
     * Scope untuk filter berdasarkan karyawan (baik sebagai peminta atau target)
     */
    public function scopeByKaryawan($query, $karyawanId)
    {
        return $query->where(function($q) use ($karyawanId) {
            $q->where('peminta_karyawan_id', $karyawanId)
              ->orWhere('target_karyawan_id', $karyawanId);
        });
    }

    /**
     * Scope untuk filter permintaan saya (sebagai peminta)
     */
    public function scopePermintaanSaya($query, $karyawanId)
    {
        return $query->where('peminta_karyawan_id', $karyawanId);
    }

    /**
     * Scope untuk filter permintaan orang lain (sebagai target)
     */
    public function scopePermintaanOrangLain($query, $karyawanId)
    {
        return $query->where('target_karyawan_id', $karyawanId);
    }

    /**
     * Scope untuk filter berdasarkan status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope untuk filter berdasarkan project
     */
    public function scopeByProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Scope untuk filter berdasarkan tanggal pengajuan
     */
    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('tanggal_pengajuan', [$startDate, $endDate]);
    }

    /**
     * Check apakah karyawan adalah peminta
     */
    public function isPeminta($karyawanId)
    {
        return $this->peminta_karyawan_id == $karyawanId;
    }

    /**
     * Check apakah karyawan adalah target
     */
    public function isTarget($karyawanId)
    {
        return $this->target_karyawan_id == $karyawanId;
    }

    /**
     * Check apakah masih bisa dibatalkan
     */
    public function canBeCancelled()
    {
        return $this->status === 'pending';
    }

    /**
     * Check apakah masih bisa diproses (disetujui/ditolak)
     */
    public function canBeProcessed()
    {
        return $this->status === 'pending';
    }

    /**
     * Setujui tukar shift
     */
    public function approve()
    {
        if (!$this->canBeProcessed()) {
            throw new \Exception('Permintaan tidak dapat disetujui karena status bukan pending');
        }

        $this->status = 'disetujui';
        $this->tanggal_diproses = now();
        $this->save();
    }

    /**
     * Tolak tukar shift
     */
    public function reject($alasan = null)
    {
        if (!$this->canBeProcessed()) {
            throw new \Exception('Permintaan tidak dapat ditolak karena status bukan pending');
        }

        $this->status = 'ditolak';
        $this->alasan_penolakan = $alasan;
        $this->tanggal_diproses = now();
        $this->save();
    }

    /**
     * Batalkan tukar shift
     */
    public function cancel()
    {
        if (!$this->canBeCancelled()) {
            throw new \Exception('Permintaan tidak dapat dibatalkan karena status bukan pending');
        }

        $this->status = 'dibatalkan';
        $this->tanggal_dibatalkan = now();
        $this->save();
    }

    /**
     * Get jenis permintaan dari perspektif karyawan
     * 'saya' = karyawan adalah peminta
     * 'orang_lain' = karyawan adalah target
     */
    public function getJenisPerspektif($karyawanId)
    {
        if ($this->isPeminta($karyawanId)) {
            return 'saya';
        } elseif ($this->isTarget($karyawanId)) {
            return 'orang_lain';
        }
        return null;
    }
}