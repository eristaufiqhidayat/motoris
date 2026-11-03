<?php

use App\Support\Akses\Akses;
use App\Support\Akses\RoleBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Koordinator tidak seharusnya mengelola Manajemen Produk, Konfigurasi, dan Master Data (menu admin/kantor,
// bukan untuk pemantauan tim di lapangan). Role bawaan Koordinator sudah terlanjur mendapat hak "lihat" dari
// RoleBawaan::pasang() sebelum daftar ini dikeluarkan; migrasi ini mencabutnya di data yang sudah ada.
return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('roles')->where('kode', 'koordinator')->where('bawaan', true)->pluck('id');

        DB::table('role_hak')->whereIn('role_id', $role)->whereIn('menu', RoleBawaan::MENU_TERTUTUP_KOORDINATOR)->delete();
        Akses::lupakan();
    }

    public function down(): void
    {
        foreach (DB::table('roles')->where('kode', 'koordinator')->where('bawaan', true)->pluck('id') as $roleId) {
            DB::table('role_hak')->insertOrIgnore(array_map(fn ($menu) => [
                'role_id' => $roleId, 'menu' => $menu, 'lihat' => true, 'tambah' => false, 'ubah' => false, 'hapus' => false, 'ekspor' => true,
            ], RoleBawaan::MENU_TERTUTUP_KOORDINATOR));
        }

        Akses::lupakan();
    }
};
