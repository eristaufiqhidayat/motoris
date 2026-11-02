<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kolom tambahan di mk_depo supaya menu "Tambah Warehouse / Depo" (MasterDepoController) bisa menyimpan
// depo baru langsung ke tabel master (mk_depo), meniru field form Marketama (/master_depo/add).
// mk_depo sebelumnya hanya tarikan ringkas (id, kode, nama, aktif, data JSON) dari php artisan marketama:tarik;
// baris yang dibuat lewat Enesis punya diubah_oleh terisi, jadi dibedakan dari hasil tarikan (ditarik_pada terisi).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mk_depo', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->after('nama');
            $table->unsignedBigInteger('city_group_id')->nullable()->after('project_id');
            $table->string('provinsi')->nullable()->after('city_group_id');
            $table->string('kota_kabupaten')->nullable()->after('provinsi');
            $table->string('kecamatan')->nullable()->after('kota_kabupaten');
            $table->string('kelurahan_desa')->nullable()->after('kecamatan');
            $table->text('alamat')->nullable()->after('kelurahan_desa');
            $table->decimal('latitude', 10, 7)->nullable()->after('alamat');
            $table->decimal('longitude', 11, 7)->nullable()->after('latitude');
            $table->string('foto')->nullable()->after('longitude'); // path di disk "local"
            $table->unsignedInteger('minimum_sku')->nullable()->after('foto');
            $table->unsignedInteger('kuota_koordinator')->nullable()->after('minimum_sku');
            $table->unsignedInteger('kuota_sales_motoris')->nullable()->after('kuota_koordinator');
            $table->unsignedInteger('target_call')->nullable()->after('kuota_sales_motoris');
            $table->unsignedInteger('target_ec')->nullable()->after('target_call');
            $table->unsignedInteger('durasi_tracking_gps')->nullable()->after('target_ec'); // detik
            $table->decimal('insentif_per_poin', 18, 2)->nullable()->after('durasi_tracking_gps'); // Rp
            $table->unsignedInteger('outlet_1_sku')->nullable()->after('insentif_per_poin');
            $table->decimal('minimal_value_transaksi', 18, 2)->nullable()->after('outlet_1_sku'); // Rp
            $table->boolean('multi_polygon_sales')->default(false)->after('minimal_value_transaksi');
            $table->string('diubah_oleh')->nullable()->after('multi_polygon_sales');
            $table->timestamp('diubah_pada')->nullable()->after('diubah_oleh');
            $table->index('project_id');
            $table->index('city_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('mk_depo', function (Blueprint $table) {
            $table->dropIndex(['project_id']);
            $table->dropIndex(['city_group_id']);
            $table->dropColumn([
                'project_id', 'city_group_id', 'provinsi', 'kota_kabupaten', 'kecamatan', 'kelurahan_desa',
                'alamat', 'latitude', 'longitude', 'foto', 'minimum_sku', 'kuota_koordinator', 'kuota_sales_motoris',
                'target_call', 'target_ec', 'durasi_tracking_gps', 'insentif_per_poin', 'outlet_1_sku',
                'minimal_value_transaksi', 'multi_polygon_sales', 'diubah_oleh', 'diubah_pada',
            ]);
        });
    }
};
