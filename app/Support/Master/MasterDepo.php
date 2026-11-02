<?php

namespace App\Support\Master;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Definisi field form "Tambah Warehouse / Depo" (meniru /master_depo/add di Marketama) + helper
 * dropdown wilayah berjenjang (Provinsi → Kota/Kab → Kecamatan → Kelurahan), Project, dan City Group.
 * Dipakai MasterDepoController dan halaman Vue MasterDepo/Tambah.
 *
 * Disimpan ke mk_depo (tabel master Marketama di Enesis), bukan ke "master_depo" (tabel hasil impor CSV
 * lama yang dipakai menu daftar Master Warehouse / Depo yang sudah ada) — lihat catatan di migrasi kolom.
 */
class MasterDepo
{
    public const TABEL = 'mk_depo';

    /**
     * Jenis field: text, textarea, angka, rupiah, pilihan:<project|city_group|ya_tidak|aktif>, foto, wilayah.
     *
     * @return list<array{0:string,1:string,2:string,3:bool,4:?int,5:?string}>
     */
    public static function field(): array
    {
        return [
            ['kode', 'Kode Warehouse', 'text', true, 50, null],
            ['nama', 'Nama Warehouse', 'text', true, 150, null],
            ['provinsi', 'Provinsi', 'wilayah', true, 255, null],
            ['kota_kabupaten', 'Kota / Kabupaten', 'wilayah', true, 255, null],
            ['kecamatan', 'Kecamatan', 'wilayah', true, 255, null],
            ['kelurahan_desa', 'Kelurahan / Desa', 'wilayah', true, 255, null],
            ['alamat', 'Alamat', 'textarea', true, 1000, null],
            ['latitude', 'Latitude', 'koordinat', true, null, null],
            ['longitude', 'Longitude', 'koordinat', true, null, null],
            ['foto', 'Foto', 'foto', true, null, '.jpg, .jpeg, .png, maksimal 2 MB.'],
            ['project_id', 'Project', 'pilihan:project', false, null, 'Opsional.'],
            ['city_group_id', 'City Group', 'pilihan:city_group', false, null, 'Opsional.'],
            ['minimum_sku', 'Minimum SKU', 'angka', false, null, 'Contoh: 5'],
            ['kuota_koordinator', 'Kuota Koordinator', 'angka', false, null, 'Contoh: 3'],
            ['kuota_sales_motoris', 'Kuota Sales Motoris', 'angka', false, null, 'Contoh: 10'],
            ['target_call', 'Target Call', 'angka', false, null, 'Target kunjungan outlet per sales per hari. Contoh: 45'],
            ['target_ec', 'Target EC', 'angka', false, null, 'Target transaksi (Effective Call) per sales per hari. Contoh: 35'],
            ['durasi_tracking_gps', 'Durasi Tracking GPS', 'angka', false, null, 'Satuan detik. Contoh: 180'],
            ['insentif_per_poin', 'Insentif per Poin', 'rupiah', false, null, 'Contoh: 250'],
            ['outlet_1_sku', 'Outlet 1 SKU', 'angka', false, null, 'Contoh: 3'],
            ['minimal_value_transaksi', 'Minimal Value Transaksi', 'rupiah', false, null, 'Contoh: 35000'],
            ['multi_polygon_sales', 'Multi Area / Polygon Sales', 'pilihan:ya_tidak', false, null, null],
            ['aktif', 'Status', 'pilihan:aktif', false, null, null],
        ];
    }

    public static function idBaru(): int
    {
        return (int) DB::table(self::TABEL)->max('id') + 1;
    }

    /** @return list<array{value:string,label:string}> */
    public static function opsi(string $sumber): array
    {
        $baris = match ($sumber) {
            'project' => DB::table('master_project')->orderBy('nama_project')->pluck('nama_project', 'id'),
            'city_group' => DB::table('city_group')->orderBy('nama_city_group')->pluck('nama_city_group', 'id'),
            'ya_tidak' => collect(['1' => 'Ya', '0' => 'Tidak']),
            'aktif' => collect(['1' => 'Aktif', '0' => 'Tidak aktif']),
        };

        return $baris->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values()->all();
    }

    /** @return list<string> */
    public static function idOpsi(string $sumber): array
    {
        return array_column(self::opsi($sumber), 'value');
    }

    /** @return list<string> daftar nama provinsi (dipakai pilihan pertama, dikirim langsung ke halaman). */
    public static function provinsiList(): array
    {
        return DB::table('provinsi')->whereNotNull('nama_provinsi')->distinct()
            ->orderBy('nama_provinsi')->pluck('nama_provinsi')->all();
    }

    /** @return list<string> */
    public static function kotaList(string $provinsi): array
    {
        return DB::table('kota_kabupaten')->where('provinsi', $provinsi)->whereNotNull('nama_kota_kabupaten')
            ->distinct()->orderBy('nama_kota_kabupaten')->pluck('nama_kota_kabupaten')->all();
    }

    /** @return list<string> */
    public static function kecamatanList(string $provinsi, string $kotaKabupaten): array
    {
        return DB::table('kecamatan')->where('provinsi', $provinsi)->where('kota_kabupaten', $kotaKabupaten)
            ->whereNotNull('nama_kecamatan')->distinct()->orderBy('nama_kecamatan')->pluck('nama_kecamatan')->all();
    }

    /** @return list<string> */
    public static function kelurahanList(string $provinsi, string $kotaKabupaten, string $kecamatan): array
    {
        return DB::table('kelurahan')->where('provinsi', $provinsi)->where('kota_kabupaten', $kotaKabupaten)
            ->where('kecamatan', $kecamatan)->whereNotNull('nama_kelurahan')
            ->distinct()->orderBy('nama_kelurahan')->pluck('nama_kelurahan')->all();
    }

    /** Depo yang dibuat/diubah lewat form Enesis (diubah_oleh terisi), terbaru dulu. */
    public static function terbaru(int $limit = 10): Collection
    {
        return DB::table(self::TABEL)
            ->whereNotNull('diubah_oleh')
            ->orderByDesc('diubah_pada')
            ->limit($limit)
            ->get(['id', 'kode', 'nama', 'provinsi', 'kota_kabupaten', 'target_call', 'target_ec', 'aktif', 'foto', 'diubah_oleh', 'diubah_pada']);
    }
}
