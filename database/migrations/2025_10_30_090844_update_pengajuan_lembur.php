<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_lemburs', function (Blueprint $table) {
            // Tambah kolom untuk lembur di hari libur
            $table->enum('kode_hari', ['K', 'L'])->after('tanggal')
                  ->comment('K = Hari Kerja, L = Hari Libur');
            
            $table->time('jam_mulai')->nullable()->after('kode_hari')
                  ->comment('Jam mulai lembur (wajib untuk hari libur)');
            
            $table->time('jam_selesai')->nullable()->after('jam_mulai')
                  ->comment('Jam selesai lembur (wajib untuk hari libur)');
            
            $table->text('keterangan_karyawan')->nullable()->after('file_skl')
                  ->comment('Keterangan dari karyawan (opsional)');
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_lemburs', function (Blueprint $table) {
            $table->dropColumn([
                'kode_hari',
                'jam_mulai', 
                'jam_selesai',
                'keterangan_karyawan'
            ]);
        });
    }
};