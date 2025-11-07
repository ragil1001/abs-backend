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
        Schema::create('karyawans', function (Blueprint $table) {
            $table->id();
            $table->string('nik')->unique();
            $table->string('nama');
            $table->string('no_telepon', 30);
            $table->foreignId('divisi_id')->constrained('divisis')->onDelete('cascade');
            $table->foreignId('jabatan_id')->constrained('jabatans')->onDelete('cascade');
            $table->enum('jenis_kelamin', ['L', 'P']);
            $table->string('tempat_lahir');
            $table->date('tanggal_lahir');
            $table->date('tanggal_bergabung');
            $table->date('tanggal_keluar')->nullable();
            $table->string('username')->unique();
            $table->string('password');
            $table->enum('status', ['aktif', 'tidak_aktif'])->default('aktif');
            $table->timestamps();

            // Additional indexes for better query performance
            $table->index(['nama']); // For search by name
            $table->index(['status']); // For filtering by status
            $table->index(['no_telepon']);
            $table->index(['jenis_kelamin']); // For filtering by gender
            $table->index(['divisi_id', 'status']); // Composite index for division + status queries
            $table->index(['jabatan_id', 'status']); // Composite index for position + status queries
            $table->index(['tanggal_bergabung']); // For sorting/filtering by join date
            $table->index(['tanggal_keluar']); // For filtering active/inactive employees
            $table->index(['created_at']); // For sorting by creation date
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('karyawans');
    }
};