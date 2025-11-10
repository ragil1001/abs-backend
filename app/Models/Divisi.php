<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Divisi extends Model
{
    use HasFactory;

    protected $table = 'divisis';
    
    
    public $incrementing = false;
    protected $keyType = 'int';
    
    protected $fillable = [
        'id',
        'nama',
    ];

    protected $casts = [
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    
    public function karyawans()
    {
        return $this->hasMany(Karyawan::class, 'divisi_id');
    }

    
    public function scopeSearch($query, $search)
    {
        return $query->where('nama', 'like', "%{$search}%");
    }

    public function scopeOrdered($query, $field = 'id', $direction = 'asc')
    {
        return $query->orderBy($field, $direction);
    }
}