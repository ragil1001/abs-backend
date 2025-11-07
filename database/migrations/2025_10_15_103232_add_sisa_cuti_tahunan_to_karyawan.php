<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('karyawans', function (Blueprint $table) {
            // Add sisa_cuti_tahunan if not exists
            if (!Schema::hasColumn('karyawans', 'sisa_cuti_tahunan')) {
                $table->integer('sisa_cuti_tahunan')->default(12)->after('status');
            }
            
            // Add composite indexes for better import performance
            $table->index(['divisi_id', 'jabatan_id']); 
            $table->index(['tanggal_bergabung', 'status']);
        });

        // Create imports directory if not exists
        if (!Storage::exists('imports')) {
            Storage::makeDirectory('imports');
        }
    }

    public function down(): void
    {
        Schema::table('karyawans', function (Blueprint $table) {
            $table->dropIndex(['divisi_id', 'jabatan_id']);
            $table->dropIndex(['tanggal_bergabung', 'status']);
        });
    }
};