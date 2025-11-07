<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_izins', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('jadwal_karyawan_id')
                  ->constrained('jadwal_karyawans')
                  ->onDelete('cascade')
                  ->comment('Relasi ke jadwal karyawan (tanggal mulai)');
            
            $table->string('jenis_izin')->comment('Jenis izin (sakit, cuti, dll)');
            
            $table->date('tanggal_mulai')->comment('Tanggal mulai izin');
            $table->date('tanggal_selesai')->comment('Tanggal selesai izin');
            
            $table->string('file_dokumen')->nullable()->comment('Path file dokumen izin (PDF)');
            
            $table->text('keterangan')->nullable()->comment('Keterangan/alasan pengajuan izin');
            
            $table->enum('status', ['pending', 'disetujui', 'ditolak', 'dibatalkan'])
                  ->default('pending')
                  ->comment('Status pengajuan');
            
            $table->text('catatan_admin')->nullable()->comment('Catatan dari admin saat approve/reject');
            
            $table->timestamp('diproses_pada')->nullable()->comment('Waktu diproses oleh admin');
            $table->unsignedBigInteger('diproses_oleh')->nullable()->comment('ID admin yang memproses');
            
            $table->timestamps();
            
            // Indexes
            $table->index(['jadwal_karyawan_id'], 'idx_jadwal');
            $table->index(['tanggal_mulai', 'tanggal_selesai'], 'idx_periode');
            $table->index(['status'], 'idx_status_pengajuan');
            $table->index(['created_at'], 'idx_created');
            $table->index(['jenis_izin'], 'idx_jenis_izin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_izins');
    }
};