<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presensis', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('jadwal_karyawan_id')
                  ->constrained('jadwal_karyawans')
                  ->onDelete('cascade')
                  ->comment('Relasi ke jadwal karyawan');
            
            $table->date('tanggal')->comment('Tanggal presensi');
            
            $table->enum('tipe', ['masuk', 'pulang'])
                  ->comment('Tipe presensi: masuk atau pulang');
            
            $table->enum('status', ['hadir', 'izin', 'terlambat', 'lembur_pending', 'lembur', 'pulang_cepat', 'tidak_presensi_pulang', 'alpa', 'libur'])
                  ->comment('Status presensi');
            
            $table->time('waktu')->nullable()->comment('Waktu presensi dilakukan');
            
            $table->decimal('latitude', 10, 8)->nullable()->comment('Latitude lokasi presensi');
            $table->decimal('longitude', 11, 8)->nullable()->comment('Longitude lokasi presensi');
            
            $table->string('foto')->nullable()->comment('Path foto selfie presensi');
            
            $table->text('keterangan')->nullable()->comment('Keterangan tambahan (menit terlambat, lembur, dll)');
            
            $table->timestamps();
            
            // Indexes
            $table->index(['jadwal_karyawan_id', 'tanggal', 'tipe'], 'idx_jadwal_tanggal_tipe');
            $table->index(['tanggal'], 'idx_tanggal_presensi');
            $table->index(['tipe'], 'idx_tipe');
            $table->index(['status'], 'idx_status_presensi');
            
            // Unique constraint: 1 jadwal hanya bisa punya 1 presensi masuk dan 1 presensi pulang
            $table->unique(['jadwal_karyawan_id', 'tipe'], 'unique_presensi_tipe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presensis');
    }
};