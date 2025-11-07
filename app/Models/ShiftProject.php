<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'kode',
        'waktu_mulai',
        'waktu_selesai'
    ];

    protected $casts = [
    'waktu_mulai' => 'string',
    'waktu_selesai' => 'string',
];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
    
    public function getDisplayNameAttribute()
    {
        return "{$this->kode}: {$this->waktu_mulai} - {$this->waktu_selesai}";
    }
}