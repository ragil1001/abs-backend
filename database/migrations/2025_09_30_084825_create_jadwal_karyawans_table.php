<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop and recreate table to ensure correct type
        Schema::dropIfExists('jadwal_karyawans');
        
        Schema::create('jadwal_karyawans', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('karyawan_project_id')
                  ->constrained('karyawan_projects')
                  ->onDelete('cascade');
            
            // CRITICAL: Use date() not timestamp()
            $table->date('tanggal')->comment('Tanggal jadwal - DATE type only');
            
            $table->string('bulan', 7)->comment('YYYY-MM');
            
            $table->string('shift_code', 10)->nullable();
            
            $table->enum('status', ['scheduled', 'completed', 'absent'])
                  ->default('scheduled')->nullable();
            
            $table->text('keterangan')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['karyawan_project_id', 'tanggal'], 'idx_karyawan_tanggal');
            $table->index(['bulan'], 'idx_bulan');
            $table->index(['tanggal'], 'idx_tanggal');
            $table->index(['shift_code'], 'idx_shift');
            $table->index(['status'], 'idx_status');
            
            $table->unique(['karyawan_project_id', 'tanggal'], 'unique_jadwal_harian');
        });

        // EXTRA: Set timezone ke UTC untuk kolom DATE di PostgreSQL
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("COMMENT ON COLUMN jadwal_karyawans.tanggal IS 'DATE only - no time component'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwal_karyawans');
    }
};