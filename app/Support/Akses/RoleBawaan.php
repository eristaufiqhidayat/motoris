<?php

namespace App\Support\Akses;

use Illuminate\Support\Facades\DB;

// Role bawaan, satu per kategori akun lama (kode = users.kategori). Hak awal sengaja longgar
// untuk lihat/ekspor dan tertutup untuk ubah, supaya admin tinggal mempersempit dari menu Role.
class RoleBawaan
{
    /** Menu yang tidak diberikan ke role bawaan Koordinator: Manajemen Produk, Konfigurasi, Master Data. */
    public const MENU_TERTUTUP_KOORDINATOR = [
        'produk', 'produk_kompetitor', 'kategori_produk', 'merk_produk', 'satuan_produk',
        'master_area', 'master_depo', 'kuota_motoris', 'wilayah_depo', 'master_project', 'holiday_dates', 'waktu_aktif_app', 'target_setting',
        'provinsi', 'city_group', 'kota_kabupaten', 'kecamatan', 'kelurahan', 'kategori_user', 'kategori_karyawan', 'tipe_outlet', 'kategori_outlet',
        'rumus_insentif_point_sales', 'sales_point_conversion_per_warehouse', 'rumus_insentif_sales', 'rumus_insentif_koordinator',
    ];

    /** @return array<string, array<string, mixed>> */
    public static function daftar(): array
    {
        return [
            'super_admin' => [
                'nama' => 'Super Admin', 'keterangan' => 'Semua menu dan pengaturan.',
                'cakupan' => 'semua', 'login_web' => true, 'login_mobile' => false, 'hak' => 'penuh',
            ],
            'koordinator' => [
                'nama' => 'Koordinator', 'keterangan' => 'Pantau tim, setoran, join visit.',
                'cakupan' => 'downline', 'login_web' => true, 'login_mobile' => true, 'hak' => 'lihat',
            ],
            'sales' => [
                'nama' => 'Sales Motoris', 'keterangan' => 'Aplikasi HP: absen, kunjungan, order.',
                'cakupan' => 'diri', 'login_web' => false, 'login_mobile' => true, 'hak' => 'beranda',
            ],
            'lainnya' => [
                'nama' => 'Lainnya', 'keterangan' => 'Staf depo / kantor (role lama "user lainnya").',
                'cakupan' => 'depo', 'login_web' => true, 'login_mobile' => false, 'hak' => 'lihat',
            ],
        ];
    }

    /** Buat role bawaan yang belum ada dan pasang role_id akun sesuai kategori. Aman diulang. */
    public static function pasang(): void
    {
        $sekarang = now();
        $slug = Akses::semuaSlug();

        foreach (self::daftar() as $kode => $r) {
            if (DB::table('roles')->where('kode', $kode)->exists()) {
                continue;
            }

            $id = DB::table('roles')->insertGetId([
                'kode' => $kode,
                'nama' => $r['nama'],
                'keterangan' => $r['keterangan'],
                'bawaan' => true,
                'cakupan' => $r['cakupan'],
                'login_web' => $r['login_web'],
                'login_mobile' => $r['login_mobile'],
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ]);

            $menu = match ($r['hak']) {
                'lihat' => array_diff($slug, Akses::MENU_ADMIN, $kode === 'koordinator' ? self::MENU_TERTUTUP_KOORDINATOR : []),
                'beranda' => ['home'],
                default => [],
            };

            // Koordinator boleh memberi tanda motoris (tracking_sales) dan tanda outlet (outlet_all) dari drawer Riwayat Lacak Motoris.
            $tambahKoordinator = ['tracking_sales', 'outlet_all'];
            DB::table('role_hak')->insert(array_map(fn ($m) => [
                'role_id' => $id, 'menu' => $m, 'lihat' => true, 'tambah' => $kode === 'koordinator' && in_array($m, $tambahKoordinator, true), 'ubah' => false, 'hapus' => false,
                'ekspor' => $r['hak'] === 'lihat',
            ], array_values($menu)));
        }

        foreach (DB::table('roles')->where('bawaan', true)->pluck('id', 'kode') as $kode => $id) {
            DB::table('users')->whereNull('role_id')->where('kategori', $kode)->update(['role_id' => $id]);
        }
    }
}
