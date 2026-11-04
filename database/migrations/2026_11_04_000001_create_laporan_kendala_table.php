<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Laporan Kendala Lapangan (spec 064): koordinator mencatat kendala operasional (mis. stok canvassing depo habis)
// supaya penurunan kinerja depo bisa dijelaskan. pelapor_nama dan depo_id disalin saat dibuat agar laporan lama
// tetap benar walau karyawan pindah depo. Laporan tidak dihapus; salah input ditutup sebagai Selesai dengan catatan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laporan_kendala', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('karyawan_id');
            $table->string('pelapor_nama');
            $table->unsignedBigInteger('depo_id')->nullable();
            $table->string('kategori', 30);
            $table->date('tanggal_kejadian');
            $table->string('judul', 120);
            $table->text('uraian');
            $table->string('status', 10)->default('terbuka');
            $table->text('catatan_selesai')->nullable();
            $table->timestamp('selesai_at')->nullable();
            $table->timestamps();

            $table->index(['karyawan_id']);
            $table->index(['depo_id', 'status']);
            $table->index(['status', 'tanggal_kejadian']);
        });

        // Siapa (selain pelapor) yang sudah membuka laporan dan kapan; satu baris per pasangan laporan-akun.
        Schema::create('laporan_kendala_dibaca', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('laporan_kendala_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('dibaca_at');

            $table->unique(['laporan_kendala_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laporan_kendala_dibaca');
        Schema::dropIfExists('laporan_kendala');
    }
};
