<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Diskusi Laporan Kendala (spec 065): komentar dan peristiwa (selesai / dibuka kembali) dalam satu linimasa per laporan.
// aktivitas_at = waktu aktivitas terakhir, dasar lencana "Ada balasan baru"; laporan lama diisi created_at.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laporan_kendala_komentar', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('laporan_kendala_id');
            $table->unsignedBigInteger('user_id');
            $table->string('penulis_nama');
            $table->string('jenis', 20)->default('komentar');
            $table->text('isi')->nullable();
            $table->timestamp('disunting_at')->nullable();
            $table->timestamps();

            $table->index(['laporan_kendala_id', 'created_at']);
        });

        Schema::table('laporan_kendala', function (Blueprint $table) {
            $table->timestamp('aktivitas_at')->nullable();
        });

        DB::table('laporan_kendala')->update(['aktivitas_at' => DB::raw('created_at')]);

        // Laporan yang sudah Selesai sebelum fitur ini: catatan penyelesaiannya jadi peristiwa 'selesai' supaya tetap
        // terbaca di linimasa setelah laporan dibuka kembali (kolom catatan_selesai dikosongkan saat itu).
        foreach (DB::table('laporan_kendala')->where('status', 'selesai')->get() as $l) {
            DB::table('laporan_kendala_komentar')->insert([
                'laporan_kendala_id' => $l->id,
                'user_id' => $l->user_id,
                'penulis_nama' => $l->pelapor_nama,
                'jenis' => 'selesai',
                'isi' => $l->catatan_selesai,
                'created_at' => $l->selesai_at ?? $l->updated_at,
                'updated_at' => $l->selesai_at ?? $l->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('laporan_kendala', function (Blueprint $table) {
            $table->dropColumn('aktivitas_at');
        });
        Schema::dropIfExists('laporan_kendala_komentar');
    }
};
