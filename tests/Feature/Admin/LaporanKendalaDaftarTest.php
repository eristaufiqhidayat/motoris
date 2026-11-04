<?php

namespace Tests\Feature\Admin;

use App\Support\Akses\Akses;
use Illuminate\Support\Facades\DB;

// Daftar Laporan Kendala (spec 064): hak menu, cakupan lewat karyawan pelapor, dan penyaringan.
class LaporanKendalaDaftarTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('mk_karyawan')->insert([
            ['id' => 138, 'kategori' => 'koordinator', 'nama' => 'Koor Dua', 'depo_id' => 22, 'koordinator_id' => null, 'aktif' => true],
            ['id' => 139, 'kategori' => 'koordinator', 'nama' => 'Koor Tiga', 'depo_id' => 46, 'koordinator_id' => null, 'aktif' => true],
        ]);

        $this->laporan(137, 'Stok canvassing habis', ['kategori' => 'stok_habis']);
        $this->laporan(138, 'Motor mogok', ['kategori' => 'kendaraan']);
        $this->laporan(139, 'Jalan banjir', ['kategori' => 'akses_cuaca', 'status' => 'selesai']);
    }

    private function laporan(int $karyawan, string $judul, array $lain = []): int
    {
        $k = DB::table('mk_karyawan')->find($karyawan);

        return DB::table('laporan_kendala')->insertGetId($lain + [
            'user_id' => 1, 'karyawan_id' => $karyawan, 'pelapor_nama' => $k->nama, 'depo_id' => $k->depo_id,
            'kategori' => 'lainnya', 'tanggal_kejadian' => '2026-11-03', 'judul' => $judul, 'uraian' => 'Uraian '.$judul,
            'status' => 'terbuka', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function judul(array $props): array
    {
        return collect($props['baris']['data'])->pluck('judul')->sort()->values()->all();
    }

    private function daftar(string $query = ''): array
    {
        return $this->judul($this->inertiaProps($this->get('/laporan_kendala'.$query), 'LaporanKendala/Index'));
    }

    public function test_koordinator_bawaan_punya_hak_lihat_tambah_ubah(): void
    {
        $koor = $this->akun('koor', 'koordinator', 137);

        $this->assertTrue(Akses::bisa($koor, 'laporan_kendala', 'lihat'));
        $this->assertTrue(Akses::bisa($koor, 'laporan_kendala', 'tambah'));
        $this->assertTrue(Akses::bisa($koor, 'laporan_kendala', 'ubah'));
        $this->assertFalse(Akses::bisa($koor, 'laporan_kendala', 'hapus'));
    }

    public function test_koordinator_hanya_melihat_laporannya_dan_downline(): void
    {
        // Koor Tiga di bawah Koor Satu → ikut terlihat; Koor Dua tidak.
        DB::table('mk_karyawan')->where('id', 139)->update(['koordinator_id' => 137]);
        $this->actingAs($this->akun('koor', 'koordinator', 137));

        $this->assertSame(['Jalan banjir', 'Stok canvassing habis'], $this->daftar('?status=semua'));
    }

    public function test_cakupan_depo_hanya_depo_akun(): void
    {
        $role = $this->role('Supervisor Depo', 'depo', ['laporan_kendala' => ['lihat']]);
        $this->actingAs($this->akun('spv', 'lainnya', 500, ['role_id' => $role]));

        // Depo 46 = Koor Satu dan Koor Tiga.
        $this->assertSame(['Jalan banjir', 'Stok canvassing habis'], $this->daftar('?status=semua'));
    }

    public function test_super_admin_melihat_semua_dan_bawaan_hanya_terbuka(): void
    {
        $this->assertSame(['Motor mogok', 'Stok canvassing habis'], $this->daftar());
        $this->assertSame(['Jalan banjir', 'Motor mogok', 'Stok canvassing habis'], $this->daftar('?status=semua'));
        $this->assertSame(['Jalan banjir'], $this->daftar('?status=selesai'));
    }

    public function test_filter_kategori_depo_tanggal_dan_cari(): void
    {
        $this->laporan(137, 'Rak gudang kosong', ['kategori' => 'stok_habis', 'tanggal_kejadian' => '2026-10-20']);

        $this->assertSame(['Rak gudang kosong', 'Stok canvassing habis'], $this->daftar('?status=semua&kategori=stok_habis'));
        $this->assertSame(['Motor mogok'], $this->daftar('?status=semua&depo=22'));
        $this->assertSame(['Rak gudang kosong'], $this->daftar('?status=semua&sampai=2026-10-31'));
        $this->assertSame(['Jalan banjir', 'Motor mogok', 'Stok canvassing habis'], $this->daftar('?status=semua&dari=2026-11-01'));
        $this->assertSame(['Motor mogok'], $this->daftar('?status=semua&q=mogok'));
        $this->assertSame(['Motor mogok'], $this->daftar('?status=semua&q=koor+dua'));
    }

    public function test_akun_tanpa_hak_lihat_ditolak(): void
    {
        $role = $this->role('Tanpa Kendala', 'semua', ['home' => ['lihat']]);
        $this->actingAs($this->akun('biasa', 'lainnya', 500, ['role_id' => $role]));

        $this->get('/laporan_kendala')->assertForbidden();
    }

    public function test_menu_sidebar_hanya_untuk_yang_berhak(): void
    {
        $slug = fn ($user) => collect(Akses::menu($user))->pluck('items')->flatten(1)->pluck('slug')->all();

        $this->assertContains('laporan_kendala', $slug($this->akun('koor', 'koordinator', 137)));

        $role = $this->role('Tanpa Kendala', 'semua', ['home' => ['lihat']]);
        $this->assertNotContains('laporan_kendala', $slug($this->akun('biasa', 'lainnya', 500, ['role_id' => $role])));
    }
}
