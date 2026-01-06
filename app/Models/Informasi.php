<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Informasi extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'judul',
        'konten',
        'file_path',
        'file_name',
        'file_type',
        'file_size',
        'target_type',
        'target_ids',
        'total_penerima',
        'total_dibaca',
        'status',
        'dikirim_at'
    ];

    protected $casts = [
        'target_ids' => 'array',
        'dikirim_at' => 'datetime',
        'total_penerima' => 'integer',
        'total_dibaca' => 'integer'
    ];

    protected $appends = ['time_ago', 'file_url'];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function informasiKaryawan()
    {
        return $this->hasMany(InformasiKaryawan::class);
    }

    public function karyawans()
    {
        return $this->belongsToMany(Karyawan::class, 'informasi_karyawan')
                    ->withPivot('is_read', 'read_at')
                    ->withTimestamps();
    }

    // Scopes
    public function scopeTerkirim($query)
    {
        return $query->where('status', 'terkirim');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeByTargetType($query, $type)
    {
        return $query->where('target_type', $type);
    }

    // Accessors
    public function getTimeAgoAttribute()
    {
        return $this->dikirim_at ? $this->dikirim_at->diffForHumans() : null;
    }

    public function getFileUrlAttribute()
    {
        return $this->file_path ? url('storage/' . $this->file_path) : null;
    }

    public function getFileSizeFormattedAttribute()
    {
        if (!$this->file_size) return null;

        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function getPersentaseDibacaAttribute()
    {
        if ($this->total_penerima === 0) return 0;
        return round(($this->total_dibaca / $this->total_penerima) * 100, 1);
    }

    // Methods
    public function kirim()
    {
        $this->update([
            'status' => 'terkirim',
            'dikirim_at' => Carbon::now()
        ]);
    }

    public function updateStatistik()
    {
        $this->total_dibaca = $this->informasiKaryawan()->where('is_read', true)->count();
        $this->save();
    }

    /**
     * Get list of karyawan IDs based on target type and IDs
     */
    public function getTargetKaryawanIds()
    {
        switch ($this->target_type) {
            case 'semua':
                return Karyawan::where('status', 'aktif')->pluck('id')->toArray();
                
            case 'divisi':
                return Karyawan::where('status', 'aktif')
                              ->whereIn('divisi_id', $this->target_ids)
                              ->pluck('id')
                              ->toArray();
                
            case 'jabatan':
                return Karyawan::where('status', 'aktif')
                              ->whereIn('jabatan_id', $this->target_ids)
                              ->pluck('id')
                              ->toArray();
                
            case 'project':
                return \App\Models\KaryawanProject::where('status', 'aktif')
                                                   ->whereIn('project_id', $this->target_ids)
                                                   ->pluck('karyawan_id')
                                                   ->unique()
                                                   ->toArray();
                
            case 'karyawan':
                return $this->target_ids;
                
            default:
                return [];
        }
    }

    /**
     * Get target names for display
     */
    public function getTargetNamesAttribute()
    {
        switch ($this->target_type) {
            case 'semua':
                return 'Semua Karyawan';
                
            case 'divisi':
                $divisis = \App\Models\Divisi::whereIn('id', $this->target_ids)->pluck('nama');
                return $divisis->count() > 3 
                    ? $divisis->take(3)->implode(', ') . ' +' . ($divisis->count() - 3) . ' lainnya'
                    : $divisis->implode(', ');
                
            case 'jabatan':
                $jabatans = \App\Models\Jabatan::whereIn('id', $this->target_ids)->pluck('nama');
                return $jabatans->count() > 3
                    ? $jabatans->take(3)->implode(', ') . ' +' . ($jabatans->count() - 3) . ' lainnya'
                    : $jabatans->implode(', ');
                
            case 'project':
                $projects = \App\Models\Project::whereIn('id', $this->target_ids)->pluck('nama');
                return $projects->count() > 3
                    ? $projects->take(3)->implode(', ') . ' +' . ($projects->count() - 3) . ' lainnya'
                    : $projects->implode(', ');
                
            case 'karyawan':
                $karyawans = Karyawan::whereIn('id', $this->target_ids)->pluck('nama');
                return $karyawans->count() > 3
                    ? $karyawans->take(3)->implode(', ') . ' +' . ($karyawans->count() - 3) . ' lainnya'
                    : $karyawans->implode(', ');
                
            default:
                return '-';
        }
    }
}