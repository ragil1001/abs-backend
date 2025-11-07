<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Divisi extends Model
{
    use HasFactory;

    protected $table = 'divisis';
    
    // Allow manual ID assignment
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

    // Relationships
    public function karyawans()
    {
        return $this->hasMany(Karyawan::class, 'divisi_id');
    }

    // Scopes
    public function scopeSearch($query, $search)
    {
        return $query->where('nama', 'like', "%{$search}%");
    }

    public function scopeOrdered($query, $field = 'id', $direction = 'asc')
    {
        return $query->orderBy($field, $direction);
    }
}