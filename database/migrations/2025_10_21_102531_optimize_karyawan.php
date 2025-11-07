<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Migrasi untuk optimasi bulk import:
     * - Tambah UNIQUE constraint pada divisis & jabatans untuk UPSERT
     * - Tambah index untuk foreign keys
     * - Optimize untuk PostgreSQL
     */
    public function up(): void
    {
        // ===== 1. DIVISIS TABLE =====
        Schema::table('divisis', function (Blueprint $table) {
            // Tambah unique constraint jika belum ada
            if (!Schema::hasColumn('divisis', 'nama')) {
                return;
            }
            
            try {
                // Check if constraint exists, jika ada skip
                $table->unique('nama', 'unique_divisi_nama');
            } catch (\Exception $e) {
                // Already exists
            }
        });

        // ===== 2. JABATANS TABLE =====
        Schema::table('jabatans', function (Blueprint $table) {
            try {
                $table->unique('nama', 'unique_jabatan_nama');
            } catch (\Exception $e) {
                // Already exists
            }
        });

        // ===== 3. KARYAWANS TABLE - Add missing indexes =====
        Schema::table('karyawans', function (Blueprint $table) {
            // Ensure NIK is indexed (untuk fast lookup)
            if (!Schema::hasIndex('karyawans', 'karyawans_nik_index')) {
                $table->index('nik', 'karyawans_nik_index');
            }

            // Ensure username is indexed
            if (!Schema::hasIndex('karyawans', 'karyawans_username_index')) {
                $table->index('username', 'karyawans_username_index');
            }

            // Composite index untuk bulk operations
            if (!Schema::hasIndex('karyawans', 'karyawans_divisi_jabatan_status_index')) {
                $table->index(['divisi_id', 'jabatan_id', 'status'], 'karyawans_divisi_jabatan_status_index');
            }

            // Index untuk created_at (sering query recent imports)
            if (!Schema::hasIndex('karyawans', 'karyawans_created_at_index')) {
                $table->index('created_at', 'karyawans_created_at_index');
            }
        });

        // ===== 4. KARYAWAN_PROJECTS TABLE - Optimize indexes =====
        Schema::table('karyawan_projects', function (Blueprint $table) {
            // Ensure FK indexes exist
            if (!Schema::hasIndex('karyawan_projects', 'karyawan_projects_karyawan_id_index')) {
                $table->index('karyawan_id', 'karyawan_projects_karyawan_id_index');
            }

            if (!Schema::hasIndex('karyawan_projects', 'karyawan_projects_project_id_index')) {
                $table->index('project_id', 'karyawan_projects_project_id_index');
            }

            // Composite index untuk bulk queries
            if (!Schema::hasIndex('karyawan_projects', 'karyawan_projects_project_status_index')) {
                $table->index(['project_id', 'status'], 'karyawan_projects_project_status_index');
            }

            // Index untuk created_at
            if (!Schema::hasIndex('karyawan_projects', 'karyawan_projects_created_at_index')) {
                $table->index('created_at', 'karyawan_projects_created_at_index');
            }
        });

        // ===== 5. PROJECTS TABLE - Ensure indexes =====
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasIndex('projects', 'projects_status_index')) {
                $table->index('status', 'projects_status_index');
            }

            if (!Schema::hasIndex('projects', 'projects_nama_index')) {
                $table->index('nama', 'projects_nama_index');
            }
        });

        // ===== 6. DATABASE CONFIGURATION =====
        // Set PostgreSQL specific settings untuk optimal performance
        // DB::statement('SET work_mem = \'256MB\'');
        // DB::statement('SET maintenance_work_mem = \'512MB\'');
        
        // // Enable parallel query execution
        // DB::statement('SET max_parallel_workers_per_gather = 4');
        // DB::statement('SET max_parallel_workers = 8');

        Log::info('Database migration optimization completed');
    }

    public function down(): void
    {
        Schema::table('divisis', function (Blueprint $table) {
            $table->dropUnique('unique_divisi_nama');
        });

        Schema::table('jabatans', function (Blueprint $table) {
            $table->dropUnique('unique_jabatan_nama');
        });

        Schema::table('karyawans', function (Blueprint $table) {
            $table->dropIndexIfExists('karyawans_nik_index');
            $table->dropIndexIfExists('karyawans_username_index');
            $table->dropIndexIfExists('karyawans_divisi_jabatan_status_index');
            $table->dropIndexIfExists('karyawans_created_at_index');
        });

        Schema::table('karyawan_projects', function (Blueprint $table) {
            $table->dropIndexIfExists('karyawan_projects_karyawan_id_index');
            $table->dropIndexIfExists('karyawan_projects_project_id_index');
            $table->dropIndexIfExists('karyawan_projects_project_status_index');
            $table->dropIndexIfExists('karyawan_projects_created_at_index');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndexIfExists('projects_status_index');
            $table->dropIndexIfExists('projects_nama_index');
        });
    }
};