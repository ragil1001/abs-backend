<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('excluded_jabatan_ids')->nullable()->after('waktu_toleransi')
                  ->comment('Array of jabatan IDs yang dikecualikan dari pengecekan radius');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('excluded_jabatan_ids');
        });
    }
};