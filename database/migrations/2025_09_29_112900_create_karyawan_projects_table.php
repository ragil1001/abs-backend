<?php
// database/migrations/xxxx_xx_xx_create_karyawan_projects_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('karyawan_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained('karyawans')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->date('tanggal_assign'); // Tanggal karyawan di-assign ke project
            $table->date('tanggal_selesai')->nullable(); // Tanggal karyawan berhenti/dinonaktifkan dari project
            $table->enum('status', ['aktif', 'tidak_aktif'])->default('aktif');
            $table->text('keterangan')->nullable(); // Optional: catatan tambahan
            $table->timestamps();

            // Indexes untuk performa query
            $table->index(['karyawan_id', 'status']); // Cek karyawan aktif di project mana
            $table->index(['project_id', 'status']); // Ambil semua karyawan aktif di project
            $table->index(['tanggal_assign']); // Sort by assign date
            $table->index(['tanggal_selesai']); // Filter by end date
            
            // Unique constraint: 1 karyawan hanya bisa aktif di 1 project
            // Tapi boleh punya banyak record tidak aktif di berbagai project
            $table->unique(['karyawan_id', 'project_id', 'status'], 'unique_active_assignment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karyawan_projects');
    }
};