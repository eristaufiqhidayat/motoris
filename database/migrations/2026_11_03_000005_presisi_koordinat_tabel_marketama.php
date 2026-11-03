<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kolom koordinat tabel Marketama (lap_*) dibuat decimal(20, 4), padahal CSV Marketama menyimpan koordinat sampai ~14
// desimal: impor memotongnya ke grid ~11 m, dan MigrasiTransaksi menyalin angka terpotong itu ke trx_kunjungan dkk.
// Akibatnya jarak antar kunjungan lama hanya bisa 0, 11, 16, 22 m, ... (spec 062). Diperlebar ke 7 desimal (~1 cm),
// sama dengan kolom lat/lng tabel trx_*. Data yang sudah terimpor tetap terpotong sampai diimpor ulang
// (marketama:import lalu migrasi transaksi).
return new class extends Migration
{
    private const KOLOM = [
        'lap_outlet_noo' => ['location_lat', 'location_long'],
        'lap_outlet_fiktif' => ['location_lat', 'location_long'],
        'lap_checkin_checkout' => ['location_lat', 'location_long'],
        'lap_outlet_oss' => ['location_lat', 'location_long'],
        'lap_outlet_ac' => ['location_lat', 'location_long'],
        'lap_outlet_ec' => ['location_lat', 'location_long'],
        'lap_outlet_all' => ['location_lat', 'location_long'],
        'lap_effective_call' => ['outlet_longitude', 'outlet_latitude', 'sales_longitude', 'sales_latitude'],
        'lap_effective_call_barang' => ['outlet_longitude', 'outlet_latitude', 'sales_longitude', 'sales_latitude'],
        'lap_penjualan_barang' => ['outlet_longitude', 'outlet_latitude', 'sales_longitude', 'sales_latitude'],
        'lap_penjualan_barang_detail' => ['outlet_longitude', 'outlet_latitude', 'sales_longitude', 'sales_latitude'],
        'lap_checkin_outlet' => ['outlet_longitude', 'outlet_latitude', 'sales_longitude', 'sales_latitude'],
    ];

    public function up(): void
    {
        $this->ubah(7);
    }

    public function down(): void
    {
        $this->ubah(4);
    }

    private function ubah(int $desimal): void
    {
        foreach (self::KOLOM as $tabel => $kolom) {
            if (! Schema::hasTable($tabel)) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $table) use ($kolom, $desimal) {
                foreach ($kolom as $k) {
                    $table->decimal($k, 20, $desimal)->nullable()->change();
                }
            });
        }
    }
};
