// database/migrations/2025_10_16_100000_create_pengajuan_lemburs_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_lemburs', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('jadwal_karyawan_id')
                  ->constrained('jadwal_karyawans')
                  ->onDelete('cascade')
                  ->comment('Relasi ke jadwal karyawan');
            
            $table->date('tanggal')->comment('Tanggal lembur');
            
            $table->string('file_skl')
                  ->comment('Path file Surat Keterangan Lembur (SKL) - WAJIB');
            
            $table->enum('status', ['pending', 'disetujui', 'ditolak', 'dibatalkan'])
                  ->default('pending')
                  ->comment('Status pengajuan');
            
            $table->text('catatan_admin')->nullable()
                  ->comment('Catatan dari admin saat approve/reject');
            
            $table->timestamp('diproses_pada')->nullable()
                  ->comment('Waktu diproses oleh admin');
            
            $table->unsignedBigInteger('diproses_oleh')->nullable()
                  ->comment('ID admin yang memproses');
            
            $table->timestamps();
            
            // Indexes
            $table->index(['jadwal_karyawan_id'], 'idx_jadwal_lembur');
            $table->index(['tanggal'], 'idx_tanggal_lembur');
            $table->index(['status'], 'idx_status_lembur');
            $table->index(['created_at'], 'idx_created_lembur');
            
            // Unique constraint: 1 jadwal hanya bisa punya 1 pengajuan lembur
            $table->unique(['jadwal_karyawan_id'], 'unique_pengajuan_lembur');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_lemburs');
    }
};