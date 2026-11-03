<?php

use App\Support\Akses\Akses;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Grup sidebar "Absensi dan Penggajian" (data gaji/absensi) sekarang hanya untuk Super Admin
// (lihat Akses::MENU_ADMIN). Role bawaan Koordinator dan Lainnya sudah terlanjur mendapat hak
// "lihat" dari RoleBawaan::pasang() sebelum menu ini dimasukkan ke daftar; migrasi ini mencabutnya
// di data yang sudah ada.
return new class extends Migration
{
    private const MENU = [
        'absensi_sales', 'absensi_sales_terlambat', 'absensi_koordinator', 'absensi_ssk_supervisor', 'permintaan_izin',
        'parameter_pemotong_gaji', 'potongan_gaji', 'penggajian_sales', 'penggajian_koordinator',
        'lap_absensi_sales', 'lap_absensi_koordinator', 'lap_izin_sakit', 'lap_insentif_sales', 'lap_insentif_koordinator',
        'lap_penggajian_sales', 'lap_penggajian_koordinator',
    ];

    public function up(): void
    {
        $role = DB::table('roles')->whereIn('kode', ['koordinator', 'sales', 'lainnya'])->where('bawaan', true)->pluck('id');

        DB::table('role_hak')->whereIn('role_id', $role)->whereIn('menu', self::MENU)->delete();
        Akses::lupakan();
    }

    public function down(): void
    {
        foreach (DB::table('roles')->whereIn('kode', ['koordinator', 'lainnya'])->where('bawaan', true)->pluck('id') as $roleId) {
            DB::table('role_hak')->insertOrIgnore(array_map(fn ($menu) => [
                'role_id' => $roleId, 'menu' => $menu, 'lihat' => true, 'tambah' => false, 'ubah' => false, 'hapus' => false, 'ekspor' => true,
            ], self::MENU));
        }

        Akses::lupakan();
    }
};
