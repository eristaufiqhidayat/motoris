<?php

use App\Support\Akses\Akses;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Tambah Loading Barang adalah tugas Koordinator: role bawaan Koordinator diberi hak Tambah pada loading_barang
// (sebelumnya hanya Lihat, sehingga /loading_barang/add menjawab 403).
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->where('kode', 'koordinator')->where('bawaan', true)->pluck('id') as $id) {
            $ada = DB::table('role_hak')->where('role_id', $id)->where('menu', 'loading_barang')->exists();

            if ($ada) {
                DB::table('role_hak')->where('role_id', $id)->where('menu', 'loading_barang')->update(['lihat' => true, 'tambah' => true]);
            } else {
                DB::table('role_hak')->insert(['role_id' => $id, 'menu' => 'loading_barang', 'lihat' => true, 'tambah' => true, 'ubah' => false, 'hapus' => false, 'ekspor' => true]);
            }
        }

        Akses::lupakan();
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('kode', 'koordinator')->where('bawaan', true)->pluck('id');

        DB::table('role_hak')->whereIn('role_id', $role)->where('menu', 'loading_barang')->update(['tambah' => false]);
        Akses::lupakan();
    }
};
