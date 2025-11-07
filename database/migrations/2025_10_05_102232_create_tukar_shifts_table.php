<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tukar_shifts', function (Blueprint $table) {
            $table->id();
            
            // Karyawan yang mengajukan (peminta)
            $table->foreignId('peminta_karyawan_id')
                  ->constrained('karyawans')
                  ->onDelete('cascade')
                  ->comment('Karyawan yang mengajukan tukar shift');
            
            // Karyawan yang diminta (target)
            $table->foreignId('target_karyawan_id')
                  ->constrained('karyawans')
                  ->onDelete('cascade')
                  ->comment('Karyawan yang diminta untuk tukar shift');
            
            // Project (harus sama)
            $table->foreignId('project_id')
                  ->constrained('projects')
                  ->onDelete('cascade')
                  ->comment('Project tempat tukar shift');
            
            // Jadwal peminta (shift yang akan diberikan)
            $table->foreignId('jadwal_peminta_id')
                  ->constrained('jadwal_karyawans')
                  ->onDelete('cascade')
                  ->comment('Jadwal shift peminta');
            
            // Jadwal target (shift yang diminta)
            $table->foreignId('jadwal_target_id')
                  ->constrained('jadwal_karyawans')
                  ->onDelete('cascade')
                  ->comment('Jadwal shift target');
            
            // Status pengajuan
            $table->enum('status', ['pending', 'disetujui', 'ditolak', 'dibatalkan'])
                  ->default('pending')
                  ->comment('Status permintaan tukar shift');
            
            // Catatan dari peminta
            $table->text('catatan')->nullable()->comment('Catatan dari peminta');
            
            // Alasan penolakan (jika ditolak)
            $table->text('alasan_penolakan')->nullable()->comment('Alasan penolakan dari target');
            
            // Tanggal pengajuan
            $table->timestamp('tanggal_pengajuan')->useCurrent();
            
            // Tanggal diproses (disetujui/ditolak)
            $table->timestamp('tanggal_diproses')->nullable();
            
            // Tanggal dibatalkan
            $table->timestamp('tanggal_dibatalkan')->nullable();
            
            $table->timestamps();
            
            // Indexes untuk performa
            $table->index(['peminta_karyawan_id', 'status'], 'idx_peminta_status');
            $table->index(['target_karyawan_id', 'status'], 'idx_target_status');
            $table->index(['project_id', 'status'], 'idx_project_status');
            $table->index(['status'], 'idx_status_tukar');
            $table->index(['tanggal_pengajuan'], 'idx_tanggal_pengajuan');
            
            // Constraint: tidak boleh tukar shift yang sama 2x dalam status pending
            $table->unique(
                ['jadwal_peminta_id', 'jadwal_target_id', 'status'],
                'unique_pending_tukar'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tukar_shifts');
    }
};