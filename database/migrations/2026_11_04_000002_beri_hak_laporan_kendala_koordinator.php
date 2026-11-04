<?php

use App\Support\Akses\Akses;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Role bawaan Koordinator membuat dan menutup Laporan Kendala sendiri: hak Lihat + Tambah + Ubah pada slug
// laporan_kendala. Role lain (mis. Supervisor) diberi hak Lihat oleh admin dari menu Role.
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->where('kode', 'koordinator')->where('bawaan', true)->pluck('id') as $id) {
            DB::table('role_hak')->updateOrInsert(
                ['role_id' => $id, 'menu' => 'laporan_kendala'],
                ['lihat' => true, 'tambah' => true, 'ubah' => true, 'hapus' => false, 'ekspor' => false],
            );
        }

        Akses::lupakan();
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('kode', 'koordinator')->where('bawaan', true)->pluck('id');

        DB::table('role_hak')->whereIn('role_id', $role)->where('menu', 'laporan_kendala')->delete();
        Akses::lupakan();
    }
};
