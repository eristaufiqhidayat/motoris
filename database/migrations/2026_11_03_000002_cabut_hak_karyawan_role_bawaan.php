<?php

use App\Support\Akses\Akses;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Menu Karyawan (dan Lap. Karyawan/User) ikut dipindah ke grup menu User (popup footer) dan sekarang
// hanya untuk Super Admin, sama seperti menu akun lain di grup itu (Akses::MENU_ADMIN). Role bawaan
// Koordinator dan Lainnya sudah terlanjur mendapat hak "lihat" dari RoleBawaan::pasang() sebelum menu ini
// dimasukkan ke daftar; migrasi ini mencabutnya di data yang sudah ada.
return new class extends Migration
{
    private const MENU = [
        'karyawan_supervisor', 'karyawan_koordinator', 'karyawan_sales', 'karyawan_lainnya',
        'kategori_surat_peringatan', 'surat_peringatan', 'lap_karyawan', 'lap_user',
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
