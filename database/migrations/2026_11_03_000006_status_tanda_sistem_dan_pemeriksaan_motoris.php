<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tanda otomatis (spec 062): tanda motoris & outlet tidak lagi diberikan manual oleh koordinator, tetapi dibuat sistem
// lewat tombol "Sinkronisasi Data" di Beranda Koordinator (App\Support\Tracking\SinkronTemuan) sebagai saran untuk dicek.
// Koordinator menutupnya dengan hasil Terbukti / Wajar beserta catatan.
//
// - status: perlu_dicek (dibuat sistem) | terbukti | wajar. Wajar juga mengisi dicabut_at, supaya query "tanda aktif"
//   yang sudah ada (lencana Ditandai, rekap, peta outlet bertanda) otomatis tidak lagi menghitungnya.
// - dibuat_oleh boleh kosong: tanda sistem tidak punya akun pemberi (dibuat_nama "Sistem").
// - tanda_outlet.kelompok: satu titik lokasi (outlet-outlet berkoordinat identik dari pendaftar yang sama) diperiksa
//   sekaligus; skor = prioritas titik itu di antrean.
// - pemeriksaan_motoris: motoris-hari yang sudah diperiksa, supaya hari yang sudah lengkap tidak dihitung ulang.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['tanda_motoris', 'tanda_outlet'] as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->unsignedBigInteger('dibuat_oleh')->nullable()->change();
                $table->string('status', 12)->default('perlu_dicek')->after('catatan')->index();
                $table->text('hasil_catatan')->nullable()->after('status');
                $table->timestamp('dicek_at')->nullable()->after('hasil_catatan');
                $table->unsignedBigInteger('dicek_oleh')->nullable()->after('dicek_at');
                $table->string('dicek_nama')->nullable()->after('dicek_oleh');
            });
        }

        Schema::table('tanda_outlet', function (Blueprint $table) {
            $table->string('kelompok', 32)->nullable()->after('outlet_id')->index();
            $table->unsignedInteger('skor')->default(0)->after('kelompok');
        });

        Schema::create('pemeriksaan_motoris', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('karyawan_id');
            $table->date('tanggal');
            // true bila diperiksa setelah tanggal itu lewat (datanya tidak bertambah lagi): dilewati saat sinkron berikutnya.
            $table->boolean('lengkap')->default(false);
            $table->timestamp('diperiksa_at');
            $table->unsignedBigInteger('diperiksa_oleh')->nullable();

            $table->unique(['karyawan_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pemeriksaan_motoris');

        Schema::table('tanda_outlet', function (Blueprint $table) {
            $table->dropIndex(['kelompok']);
            $table->dropColumn(['kelompok', 'skor']);
        });

        foreach (['tanda_motoris', 'tanda_outlet'] as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->dropIndex(['status']);
                $table->dropColumn(['status', 'hasil_catatan', 'dicek_at', 'dicek_oleh', 'dicek_nama']);
            });
        }
    }
};
