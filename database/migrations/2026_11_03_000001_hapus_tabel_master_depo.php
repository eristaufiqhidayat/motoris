<?php

use App\Support\Master\MasterDepo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hapus "master_depo" (tabel hasil impor CSV lama): menu daftar Master Warehouse / Depo, aplikasi HP
// (App\Support\Mobile\Pengaturan::depo()), Kuota Motoris, dan Tracking Koordinator sekarang semuanya
// membaca mk_depo langsung (lihat PR "Satukan daftar Master Warehouse/Depo ke mk_depo"), jadi tabel ini
// sudah tidak dipakai di mana pun.
//
// Jaring pengaman: panggil MasterDepo::satukanDariMasterDepo() dulu sebelum tabel dihapus, supaya depo
// hasil marketama:tarik yang kolom mk_depo-nya belum pernah diisi (mis. karena lupa menjalankan
// `php artisan enesis:satukan-master-depo` setelah PR sebelumnya) tetap kebagian datanya sebelum sumbernya
// hilang. Aman walau sudah pernah dijalankan manual (satukanDariMasterDepo tidak menimpa yang sudah terisi).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('master_depo')) {
            MasterDepo::satukanDariMasterDepo();
        }

        Schema::dropIfExists('master_depo');
    }

    public function down(): void
    {
        // Skema lama persis seperti 2026_10_22_000001_create_marketama_tables.php; datanya tidak bisa dikembalikan.
        Schema::create('master_depo', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 255)->nullable();
            $table->string('nama', 255)->nullable();
            $table->string('projek', 255)->nullable();
            $table->string('kota_besar', 255)->nullable();
            $table->string('minimum_sku', 255)->nullable();
            $table->string('multi_polygon_sales', 255)->nullable();
            $table->string('provinsi', 255)->nullable();
            $table->string('kota_kab', 255)->nullable();
            $table->string('kecamatan', 255)->nullable();
            $table->string('kelurahan_desa', 255)->nullable();
            $table->string('alamat', 255)->nullable();
            $table->decimal('longitude', 20, 4)->nullable();
            $table->decimal('latitude', 20, 4)->nullable();
            $table->bigInteger('total_koordinator')->nullable();
            $table->bigInteger('jumlah_sales_aktif')->nullable();
            $table->bigInteger('jumlah_wilayah_depo')->nullable();
            $table->bigInteger('kuota_koordinator')->nullable();
            $table->bigInteger('kuota_sales_motoris')->nullable();
            $table->bigInteger('target_call')->nullable();
            $table->bigInteger('target_ec')->nullable();
            $table->bigInteger('durasi_tracking_gps')->nullable();
            $table->bigInteger('insentif_per_poin')->nullable();
            $table->bigInteger('outlet_1_sku')->nullable();
            $table->bigInteger('minimal_value_transaksi')->nullable();
            $table->string('diupdate_oleh', 255)->nullable();
            $table->string('diupdate_pada', 255)->nullable();
            $table->string('status', 255)->nullable();
        });
    }
};
