<?php

use App\Support\Akses\Akses;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Verifikasi outlet baru dari HP (menyetujui/menolak NOO) adalah tugas koordinator untuk motoris timnya, bukan admin: role bawaan
// Koordinator mendapat hak Ubah pada outlet_all. Cakupan tim dijaga OutletBaruController (Akses::batasiKaryawan). Baris hak
// dibuat bila belum ada; hak yang sudah ada hanya ditambah Ubah.
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->where('kode', 'koordinator')->where('bawaan', true)->pluck('id') as $id) {
            $ada = DB::table('role_hak')->where('role_id', $id)->where('menu', 'outlet_all')->exists();

            if ($ada) {
                // Hanya Ubah yang dinyalakan: Lihat yang sengaja dicabut admin dari role ini tidak dikembalikan.
                DB::table('role_hak')->where('role_id', $id)->where('menu', 'outlet_all')->update(['ubah' => true]);
            } else {
                DB::table('role_hak')->insert(['role_id' => $id, 'menu' => 'outlet_all', 'lihat' => true, 'tambah' => true, 'ubah' => true, 'hapus' => false, 'ekspor' => false]);
            }
        }

        Akses::lupakan();
    }

    public function down(): void
    {
        DB::table('role_hak')->whereIn('role_id', DB::table('roles')->where('kode', 'koordinator')->where('bawaan', true)->pluck('id'))
            ->where('menu', 'outlet_all')->update(['ubah' => false]);
        Akses::lupakan();
    }
};
