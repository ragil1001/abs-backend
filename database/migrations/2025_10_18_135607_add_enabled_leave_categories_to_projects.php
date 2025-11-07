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
    Schema::table('projects', function (Blueprint $table) {
        $table->json('enabled_izin_categories')->nullable()->after('excluded_jabatan_ids')
              ->comment('Array kategori izin yang diaktifkan: sakit, izin, cuti_tahunan, cuti_khusus');
        
        $table->json('enabled_sub_kategori_izin')->nullable()->after('enabled_izin_categories')
              ->comment('Array sub kategori cuti khusus yang diaktifkan');
    });
}

public function down(): void
{
    Schema::table('projects', function (Blueprint $table) {
        $table->dropColumn(['enabled_izin_categories', 'enabled_sub_kategori_izin']);
    });
}
};
