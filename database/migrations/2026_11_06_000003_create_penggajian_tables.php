<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Penggajian motoris dan koordinator (spec 070): header per depo dan periode, detail per orang, dan komponen sebagai
// baris. Satu set tabel untuk sales dan koordinator (kolom jenis) dan untuk gaji dan insentif (kolom kategori).
// Tabel impor lama penggajian_sales / penggajian_koordinator dibiarkan sebagai arsip.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penggajian', function (Blueprint $table) {
            $table->id();
            $table->string('jenis', 20); // sales | koordinator
            $table->string('kategori', 20); // gaji | insentif
            $table->string('no_penggajian', 50)->unique();
            $table->date('periode_mulai');
            $table->date('periode_selesai');
            $table->date('tanggal_bayar')->nullable();
            $table->unsignedBigInteger('depo_id')->index();
            $table->unsignedBigInteger('koordinator_id')->nullable();
            $table->string('status', 10)->default('draf'); // draf | final | batal
            $table->unsignedBigInteger('dibuat_oleh')->nullable();
            $table->unsignedBigInteger('difinalkan_oleh')->nullable();
            $table->timestamp('difinalkan_pada')->nullable();
            $table->timestamps();

            $table->index(['kategori', 'status', 'periode_mulai']);
        });

        Schema::create('penggajian_detail', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('penggajian_id');
            $table->unsignedBigInteger('karyawan_id')->index();
            $table->string('nama');
            // Hanya untuk gaji; kosong pada insentif.
            $table->unsignedSmallInteger('hari_kerja')->nullable();
            $table->unsignedSmallInteger('hari_hadir')->nullable();
            $table->unsignedSmallInteger('hari_terlambat')->nullable();
            $table->unsignedSmallInteger('hari_izin')->nullable();
            $table->decimal('total_pendapatan', 18, 2)->default(0);
            $table->decimal('total_potongan', 18, 2)->default(0);
            $table->decimal('total_bersih', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['penggajian_id', 'karyawan_id']);
        });

        Schema::create('penggajian_komponen', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('detail_id')->index();
            $table->string('tipe', 12); // pendapatan | potongan
            $table->string('kode', 50);
            $table->string('nama');
            $table->decimal('nominal', 18, 2);
            $table->string('keterangan')->nullable();
            $table->unsignedBigInteger('parameter_pemotong_id')->nullable();
            $table->string('sumber', 10)->default('otomatis'); // otomatis | manual
            $table->json('rincian')->nullable(); // asal angka: nilai bulanan, jumlah hari, EC, tingkat rumus
            $table->timestamps();
        });

        Schema::table('mk_karyawan', function (Blueprint $table) {
            $table->decimal('tunjangan_jabatan', 18, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mk_karyawan', function (Blueprint $table) {
            $table->dropColumn('tunjangan_jabatan');
        });

        Schema::dropIfExists('penggajian_komponen');
        Schema::dropIfExists('penggajian_detail');
        Schema::dropIfExists('penggajian');
    }
};
