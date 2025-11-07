<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('bagian'); // posisi/jabatan dari project
            $table->string('lokasi_nama');
            $table->decimal('lokasi_latitude', 10, 8);
            $table->decimal('lokasi_longitude', 11, 8);
            $table->date('tanggal_mulai');
            $table->integer('radius');
            $table->integer('waktu_toleransi')->nullable(); // in days
            $table->enum('status', ['aktif', 'tidak_aktif'])->default('aktif');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};