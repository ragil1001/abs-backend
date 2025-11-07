<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('informasis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('judul');
            $table->text('konten');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_type')->nullable();
            $table->integer('file_size')->nullable(); // in bytes
            $table->enum('target_type', ['semua', 'divisi', 'jabatan', 'project', 'karyawan']);
            $table->json('target_ids')->nullable(); // Array of IDs based on target_type
            $table->integer('total_penerima')->default(0);
            $table->integer('total_dibaca')->default(0);
            $table->enum('status', ['draft', 'terkirim'])->default('draft');
            $table->timestamp('dikirim_at')->nullable();
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('status');
            $table->index('dikirim_at');
        });

        Schema::create('informasi_karyawan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('informasi_id')->constrained('informasis')->onDelete('cascade');
            $table->foreignId('karyawan_id')->constrained('karyawans')->onDelete('cascade');
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            
            $table->unique(['informasi_id', 'karyawan_id']);
            $table->index('karyawan_id');
            $table->index('is_read');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('informasi_karyawan');
        Schema::dropIfExists('informasis');
    }
};