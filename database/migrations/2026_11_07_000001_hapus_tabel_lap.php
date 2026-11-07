<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// Hapus semua tabel "lap_*" (salinan laporan aplikasi Marketama lama, hasil marketama:tarik /
// marketama:import). Transaksi yang masih dipakai menu sudah disalin ke tabel motoris (trx_*, mk_*)
// lewat enesis:migrasi-transaksi / enesis:sambung-transaksi, jadi tabel arsip ini tidak lagi dibaca
// oleh menu yang sudah dialihkan (lihat kelas App\Support\Menu\...\Sumber* dan KehadiranController).
//
// Menu yang BELUM dialihkan dan akan ikut kosong setelah migration ini: Lap. Join Visit, Lap. Karyawan,
// Lap. User, Lap. Insentif Koordinator, Lap. Penggajian Sales, Lap. Penggajian Koordinator,
// Lap. Loading Barang (+ Detail), Lap. Retur Barang (+ Detail), Lap. Setoran, Lap. Setor Margin,
// Lap. Outlet Fiktif, Lap. Izin/Sakit — belum ada tindak lanjut untuk menu-menu tersebut di migration ini.
//
// Perintah `marketama:tarik`, `marketama:import --only=lap_*`, `enesis:migrasi-transaksi`,
// `enesis:sambung-transaksi`, `enesis:perbaiki-koordinat-lama`, dan `enesis:isi-gaji-karyawan` tidak
// bisa dipakai lagi setelah ini karena sumber datanya (tabel lap_*) sudah tidak ada.
return new class extends Migration
{
    /** Urutan tidak penting: tidak ada foreign key antar tabel lap_*. */
    private const TABEL = [
        'lap_absensi_koordinator',
        'lap_absensi_sales',
        'lap_barang_canvassing',
        'lap_barang_habis_terjual',
        'lap_checkin_checkout',
        'lap_checkin_outlet',
        'lap_effective_call',
        'lap_effective_call_barang',
        'lap_insentif_koordinator',
        'lap_insentif_sales',
        'lap_izin_sakit',
        'lap_join_visit',
        'lap_karyawan',
        'lap_loading_barang',
        'lap_loading_barang_detail',
        'lap_outlet_ac',
        'lap_outlet_all',
        'lap_outlet_ec',
        'lap_outlet_fiktif',
        'lap_outlet_noo',
        'lap_outlet_oss',
        'lap_outlet_ro',
        'lap_pembayaran_tagihan',
        'lap_pengembalian_barang',
        'lap_penggajian_koordinator',
        'lap_penggajian_sales',
        'lap_penjualan_barang',
        'lap_penjualan_barang_detail',
        'lap_penjualan_barang_terbaik_bulanan',
        'lap_penjualan_barang_terbaik_harian',
        'lap_penjualan_barang_terbaik_mingguan',
        'lap_retur_barang',
        'lap_retur_barang_detail',
        'lap_setor_margin',
        'lap_stok_kompetitor',
        'lap_stok_outlet',
        'lap_user',
    ];

    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::dropIfExists($tabel);
        }
    }

    public function down(): void
    {
        // Skema & datanya tidak bisa dikembalikan (lihat 2026_10_22_000001_create_marketama_tables.php
        // untuk skema asli bila perlu dibuat ulang secara manual).
    }
};
