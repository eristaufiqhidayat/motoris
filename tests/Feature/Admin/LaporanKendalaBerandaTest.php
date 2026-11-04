<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Kartu Beranda dan angka Belum dibaca di sidebar untuk Laporan Kendala (spec 064).
class LaporanKendalaBerandaTest extends AdminTestCase
{
    private User $koor;

    private User $spv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->koor = $this->akun('koor', 'koordinator', 137);
        $role = $this->role('Supervisor', 'semua', ['home' => ['lihat'], 'laporan_kendala' => ['lihat']]);
        $this->spv = $this->akun('spv', 'lainnya', 500, ['role_id' => $role]);

        $this->laporan('Stok habis A');
        $this->laporan('Stok habis B');
        $this->laporan('Sudah beres', ['status' => 'selesai']);
    }

    private function laporan(string $judul, array $lain = []): int
    {
        return DB::table('laporan_kendala')->insertGetId($lain + [
            'user_id' => $this->koor->id, 'karyawan_id' => 137, 'pelapor_nama' => 'Koor Satu', 'depo_id' => 46,
            'kategori' => 'stok_habis', 'tanggal_kejadian' => '2026-11-03', 'judul' => $judul, 'uraian' => 'Uraian',
            'status' => 'terbuka', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function props(User $user, string $komponen): array
    {
        $this->actingAs($user);

        return $this->inertiaProps($this->get('/home'), $komponen);
    }

    public function test_kartu_koordinator_menghitung_terbuka_tim_dan_tombol_buat(): void
    {
        $kendala = $this->props($this->koor, 'Home/DashboardKoordinator')['kendala'];

        $this->assertTrue($kendala['tim']);
        $this->assertSame(2, $kendala['terbuka']);
        $this->assertNull($kendala['belum_dibaca']);
        $this->assertCount(2, $kendala['terbaru']);
        $this->assertTrue($kendala['bisa_tambah']);
    }

    public function test_kartu_supervisor_menghitung_terbuka_dan_belum_dibaca_dan_berkurang_setelah_dibuka(): void
    {
        $kendala = $this->props($this->spv, 'Home/Dashboard')['kendala'];
        $this->assertFalse($kendala['tim']);
        $this->assertSame(2, $kendala['terbuka']);
        $this->assertSame(2, $kendala['belum_dibaca']);
        $this->assertCount(2, $kendala['terbaru']);
        $this->assertFalse($kendala['bisa_tambah']);

        $id = DB::table('laporan_kendala')->where('judul', 'Stok habis A')->value('id');
        $this->actingAs($this->spv);
        $this->postJson("/laporan_kendala/{$id}/dibaca")->assertOk();

        $kendala = $this->props($this->spv, 'Home/Dashboard')['kendala'];
        $this->assertSame(2, $kendala['terbuka']);
        $this->assertSame(1, $kendala['belum_dibaca']);
        $this->assertSame(['Stok habis B'], array_column($kendala['terbaru'], 'judul'));
    }

    public function test_kartu_hilang_tanpa_hak_lihat(): void
    {
        $role = $this->role('Tanpa Kendala', 'semua', ['home' => ['lihat']]);
        $biasa = $this->akun('biasa', 'lainnya', 500, ['role_id' => $role]);

        $this->assertNull($this->props($biasa, 'Home/Dashboard')['kendala']);
    }

    public function test_angka_sidebar_hanya_untuk_cakupan_selain_downline_dan_turun_setelah_dibuka(): void
    {
        $this->actingAs($this->spv);
        $this->assertSame(2, $this->get('/home')->viewData('page')['props']['kendalaBelumDibaca']);

        $id = DB::table('laporan_kendala')->where('judul', 'Stok habis A')->value('id');
        $this->postJson("/laporan_kendala/{$id}/dibaca")->assertOk();
        $this->assertSame(1, $this->get('/home')->viewData('page')['props']['kendalaBelumDibaca']);

        // Koordinator (downline) dan akun tanpa hak tidak mendapat angka.
        $this->actingAs($this->koor);
        $this->assertNull($this->get('/home')->viewData('page')['props']['kendalaBelumDibaca']);

        $role = $this->role('Tanpa Kendala', 'semua', ['home' => ['lihat']]);
        $this->actingAs($this->akun('biasa', 'lainnya', 500, ['role_id' => $role]));
        $this->assertNull($this->get('/home')->viewData('page')['props']['kendalaBelumDibaca']);
    }
}
