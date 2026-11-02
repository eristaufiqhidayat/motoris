<?php

namespace App\Support\Master;

use App\Support\Marketama\HalamanParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Definisi field form "Tambah Warehouse / Depo" (meniru /master_depo/add di Marketama) + helper
 * dropdown wilayah berjenjang (Provinsi → Kota/Kab → Kecamatan → Kelurahan), Project, dan City Group.
 * Dipakai MasterDepoController dan halaman Vue MasterDepo/Tambah.
 *
 * Disimpan ke mk_depo (tabel master Marketama di Enesis) — menu daftar Master Warehouse / Depo juga
 * membaca mk_depo lewat App\Support\Menu\SumberMasterDepo. satukanDariMasterDepo() dulu mengisi
 * kolom-kolom mk_depo dari "master_depo" (tabel hasil impor CSV lama, sudah dihapus — lihat migrasi
 * 2026_11_03_000001_hapus_tabel_master_depo) untuk depo lama hasil `marketama:tarik`; method ini jadi
 * no-op begitu tabel itu tidak ada, dipertahankan hanya sebagai jejak sejarah/jaring pengaman migrasi.
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

    /**
     * Isi kolom-kolom baru mk_depo (target call/EC, kuota, lokasi, dll.) dari "master_depo" (tabel hasil
     * impor CSV lama) untuk depo hasil `marketama:tarik` yang kolom barunya masih kosong — dicocokkan lewat
     * nama (sama seperti cadangan yang sudah dipakai App\Support\Mobile\Pengaturan::depo() untuk aplikasi
     * HP), karena kode belum tentu terisi sama di kedua tabel. Aman diulang: depo yang target_call-nya
     * sudah terisi (baik dari sini atau diisi admin lewat form) tidak ditimpa.
     *
     * @return int jumlah depo yang diisi
     */
    public static function satukanDariMasterDepo(): int
    {
        if (! Schema::hasTable(self::TABEL) || ! Schema::hasTable('master_depo')) {
            return 0;
        }

        $lamaPerNama = DB::table('master_depo')->get()->keyBy(fn ($b) => HalamanParser::kunciNama($b->nama));
        $projectIdPerNama = DB::table('master_project')->pluck('id', 'nama_project');
        $cityGroupIdPerNama = DB::table('city_group')->pluck('id', 'nama_city_group');

        $angkaBulat = fn (?string $t) => ($n = HalamanParser::angka($t)) !== null ? (int) round($n) : null;
        $angkaDesimal = fn (?string $t) => HalamanParser::angka($t);
        $koordinat = fn (?string $t) => HalamanParser::angka($t, desimalTitik: true);
        $teks = fn (?string $t) => $t !== null && $t !== '-' ? $t : null;

        $jumlah = 0;

        DB::table(self::TABEL)->whereNull('target_call')->orderBy('id')->get(['id', 'nama'])->each(
            function ($depo) use ($lamaPerNama, $projectIdPerNama, $cityGroupIdPerNama, $angkaBulat, $angkaDesimal, $koordinat, $teks, &$jumlah) {
                $lama = $lamaPerNama->get(HalamanParser::kunciNama($depo->nama));

                if (! $lama) {
                    return;
                }

                DB::table(self::TABEL)->where('id', $depo->id)->update([
                    'project_id' => $teks($lama->projek) !== null ? ($projectIdPerNama[$lama->projek] ?? null) : null,
                    'city_group_id' => $teks($lama->kota_besar) !== null ? ($cityGroupIdPerNama[$lama->kota_besar] ?? null) : null,
                    'provinsi' => $teks($lama->provinsi),
                    'kota_kabupaten' => $teks($lama->kota_kab),
                    'kecamatan' => $teks($lama->kecamatan),
                    'kelurahan_desa' => $teks($lama->kelurahan_desa),
                    'alamat' => $teks($lama->alamat),
                    'latitude' => $koordinat($lama->latitude),
                    'longitude' => $koordinat($lama->longitude),
                    'minimum_sku' => $angkaBulat($lama->minimum_sku),
                    'kuota_koordinator' => $angkaBulat($lama->kuota_koordinator),
                    'kuota_sales_motoris' => $angkaBulat($lama->kuota_sales_motoris),
                    'target_call' => $angkaBulat($lama->target_call),
                    'target_ec' => $angkaBulat($lama->target_ec),
                    'durasi_tracking_gps' => $angkaBulat($lama->durasi_tracking_gps),
                    'insentif_per_poin' => $angkaDesimal($lama->insentif_per_poin),
                    'outlet_1_sku' => $angkaBulat($lama->outlet_1_sku),
                    'minimal_value_transaksi' => $angkaDesimal($lama->minimal_value_transaksi),
                    'multi_polygon_sales' => HalamanParser::aktif($lama->multi_polygon_sales) ?? false,
                    'aktif' => HalamanParser::aktif($lama->status) ?? true,
                ]);

                $jumlah++;
            }
        );

        return $jumlah;
    }
}
