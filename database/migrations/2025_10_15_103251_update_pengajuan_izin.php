<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pengajuan_izins', function (Blueprint $table) {
            // Ubah jenis_izin menjadi lebih spesifik
            $table->string('kategori_izin')->after('jadwal_karyawan_id')
                  ->comment('Kategori: sakit, izin, cuti_tahunan, cuti_khusus');
            
            $table->string('sub_kategori_izin')->nullable()->after('kategori_izin')
                  ->comment('Sub kategori untuk cuti khusus');
            
            $table->integer('durasi_otomatis')->nullable()->after('sub_kategori_izin')
                  ->comment('Durasi hari otomatis berdasarkan jenis cuti khusus');
            
            // Rename kolom jenis_izin ke deskripsi untuk backward compatibility
            $table->renameColumn('jenis_izin', 'deskripsi_izin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pengajuan_izins', function (Blueprint $table) {
            $table->renameColumn('deskripsi_izin', 'jenis_izin');
            $table->dropColumn(['kategori_izin', 'sub_kategori_izin', 'durasi_otomatis']);
        });
    }
};