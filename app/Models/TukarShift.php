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

    
    public function peminta()
    {
        return $this->belongsTo(Karyawan::class, 'peminta_karyawan_id');
    }

    
    public function target()
    {
        return $this->belongsTo(Karyawan::class, 'target_karyawan_id');
    }

    
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    
    public function jadwalPeminta()
    {
        return $this->belongsTo(JadwalKaryawan::class, 'jadwal_peminta_id');
    }

    
    public function jadwalTarget()
    {
        return $this->belongsTo(JadwalKaryawan::class, 'jadwal_target_id');
    }

    
    public function scopeByKaryawan($query, $karyawanId)
    {
        return $query->where(function($q) use ($karyawanId) {
            $q->where('peminta_karyawan_id', $karyawanId)
              ->orWhere('target_karyawan_id', $karyawanId);
        });
    }

    
    public function scopePermintaanSaya($query, $karyawanId)
    {
        return $query->where('peminta_karyawan_id', $karyawanId);
    }

    
    public function scopePermintaanOrangLain($query, $karyawanId)
    {
        return $query->where('target_karyawan_id', $karyawanId);
    }

    
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    
    public function scopeByProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    
    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('tanggal_pengajuan', [$startDate, $endDate]);
    }

    
    public function isPeminta($karyawanId)
    {
        return $this->peminta_karyawan_id == $karyawanId;
    }

    
    public function isTarget($karyawanId)
    {
        return $this->target_karyawan_id == $karyawanId;
    }

    
    public function canBeCancelled()
    {
        return $this->status === 'pending';
    }

    
    public function canBeProcessed()
    {
        return $this->status === 'pending';
    }

    
    public function approve()
    {
        if (!$this->canBeProcessed()) {
            throw new \Exception('Permintaan tidak dapat disetujui karena status bukan pending');
        }

        $this->status = 'disetujui';
        $this->tanggal_diproses = now();
        $this->save();
    }

    
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

    
    public function cancel()
    {
        if (!$this->canBeCancelled()) {
            throw new \Exception('Permintaan tidak dapat dibatalkan karena status bukan pending');
        }

        $this->status = 'dibatalkan';
        $this->tanggal_dibatalkan = now();
        $this->save();
    }

    
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