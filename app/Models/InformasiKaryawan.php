<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class InformasiKaryawan extends Model
{
    use HasFactory;

    protected $table = 'informasi_karyawan';

    protected $fillable = [
        'informasi_id',
        'karyawan_id',
        'is_read',
        'read_at'
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime'
    ];

    
    public function informasi()
    {
        return $this->belongsTo(Informasi::class);
    }

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    
    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeForKaryawan($query, $karyawanId)
    {
        return $query->where('karyawan_id', $karyawanId);
    }

    
    public function markAsRead()
    {
        if (!$this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => Carbon::now()
            ]);

            
            $this->informasi->updateStatistik();
        }
    }
}