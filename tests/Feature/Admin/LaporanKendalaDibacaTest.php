<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Status dibaca Laporan Kendala (spec 064): pencatatan pembaca, lencana Belum dibaca / Diperbarui, daftar pembaca.
class LaporanKendalaDibacaTest extends AdminTestCase
{
    private User $koor;

    private User $spv;

    private User $spv2;

    private int $laporan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->koor = $this->akun('koor', 'koordinator', 137);
        $role = $this->role('Supervisor', 'semua', ['laporan_kendala' => ['lihat']]);
        $this->spv = $this->akun('spv', 'lainnya', 500, ['role_id' => $role]);
        $this->spv2 = $this->akun('spv2', 'lainnya', 500, ['role_id' => $role]);

        $this->laporan = DB::table('laporan_kendala')->insertGetId([
            'user_id' => $this->koor->id, 'karyawan_id' => 137, 'pelapor_nama' => 'Koor Satu', 'depo_id' => 46,
            'kategori' => 'stok_habis', 'tanggal_kejadian' => '2026-11-03', 'judul' => 'Stok habis', 'uraian' => 'Uraian',
            'status' => 'terbuka', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
        ]);
    }

    private function baris(User $user, string $query = ''): array
    {
        $this->actingAs($user);

        return collect($this->inertiaProps($this->get('/laporan_kendala?status=semua'.$query), 'LaporanKendala/Index')['baris']['data'])
            ->firstWhere('id', $this->laporan) ?? [];
    }

    public function test_pelapor_membuka_laporannya_sendiri_tidak_tercatat_dibaca(): void
    {
        $this->actingAs($this->koor);

        $this->postJson("/laporan_kendala/{$this->laporan}/dibaca")->assertOk();

        $this->assertSame(0, DB::table('laporan_kendala_dibaca')->count());
    }

    public function test_pembaca_lain_tercatat_dan_lencana_belum_dibaca_hilang_untuknya_saja(): void
    {
        $this->assertTrue($this->baris($this->spv)['belum_dibaca']);
        $this->assertTrue($this->baris($this->spv2)['belum_dibaca']);

        $this->actingAs($this->spv);
        $this->postJson("/laporan_kendala/{$this->laporan}/dibaca")->assertOk();

        $this->assertFalse($this->baris($this->spv)['belum_dibaca']);
        $this->assertTrue($this->baris($this->spv2)['belum_dibaca']);
        $this->assertSame(1, DB::table('laporan_kendala_dibaca')->count());
    }

    public function test_membuka_dua_kali_memperbarui_baris_yang_sama(): void
    {
        $this->actingAs($this->spv);

        $this->postJson("/laporan_kendala/{$this->laporan}/dibaca")->assertOk();
        $this->postJson("/laporan_kendala/{$this->laporan}/dibaca")->assertOk();

        $this->assertSame(1, DB::table('laporan_kendala_dibaca')->count());
    }

    public function test_lencana_diperbarui_setelah_pelapor_mengubah(): void
    {
        DB::table('laporan_kendala_dibaca')->insert(['laporan_kendala_id' => $this->laporan, 'user_id' => $this->spv->id, 'dibaca_at' => now()->subMinutes(30)]);

        $baris = $this->baris($this->spv);
        $this->assertFalse($baris['belum_dibaca']);
        $this->assertFalse($baris['diperbarui_setelah_dibaca']);

        DB::table('laporan_kendala')->where('id', $this->laporan)->update(['updated_at' => now()]);

        $this->assertTrue($this->baris($this->spv)['diperbarui_setelah_dibaca']);

        $this->actingAs($this->spv);
        $this->postJson("/laporan_kendala/{$this->laporan}/dibaca")->assertOk();
        $this->assertFalse($this->baris($this->spv)['diperbarui_setelah_dibaca']);
    }

    public function test_pelapor_tidak_dapat_lencana_dan_melihat_jumlah_pembaca(): void
    {
        DB::table('laporan_kendala_dibaca')->insert([
            ['laporan_kendala_id' => $this->laporan, 'user_id' => $this->spv->id, 'dibaca_at' => now()],
            ['laporan_kendala_id' => $this->laporan, 'user_id' => $this->spv2->id, 'dibaca_at' => now()],
        ]);

        $baris = $this->baris($this->koor);

        $this->assertFalse($baris['belum_dibaca']);
        $this->assertFalse($baris['diperbarui_setelah_dibaca']);
        $this->assertSame(2, $baris['jumlah_pembaca']);
    }

    public function test_pelapor_melihat_daftar_pembaca_dengan_waktu_tetapi_pembaca_lain_tidak(): void
    {
        DB::table('laporan_kendala_dibaca')->insert(['laporan_kendala_id' => $this->laporan, 'user_id' => $this->spv->id, 'dibaca_at' => now()]);

        $this->actingAs($this->koor);
        $pembaca = $this->postJson("/laporan_kendala/{$this->laporan}/dibaca")->assertOk()->json('pembaca');
        $this->assertCount(1, $pembaca);
        $this->assertSame('Spv', $pembaca[0]['nama']);
        $this->assertNotEmpty($pembaca[0]['dibaca']);

        $this->actingAs($this->spv2);
        $this->postJson("/laporan_kendala/{$this->laporan}/dibaca")->assertOk()->assertJson(['pembaca' => []]);
    }

    public function test_filter_belum_dibaca(): void
    {
        $this->assertSame($this->laporan, $this->baris($this->spv, '&belum_dibaca=1')['id']);

        DB::table('laporan_kendala_dibaca')->insert(['laporan_kendala_id' => $this->laporan, 'user_id' => $this->spv->id, 'dibaca_at' => now()]);

        $this->assertSame([], $this->baris($this->spv, '&belum_dibaca=1'));
        // Laporan sendiri tidak pernah "belum dibaca" bagi pelapor.
        $this->assertSame([], $this->baris($this->koor, '&belum_dibaca=1'));
    }

    public function test_laporan_di_luar_cakupan_tidak_bisa_dibuka(): void
    {
        DB::table('mk_karyawan')->insert(['id' => 138, 'kategori' => 'koordinator', 'nama' => 'Koor Dua', 'depo_id' => 22, 'koordinator_id' => null, 'aktif' => true]);
        $koor2 = $this->akun('koor2', 'koordinator', 138);
        $this->actingAs($koor2);

        $this->postJson("/laporan_kendala/{$this->laporan}/dibaca")->assertNotFound();

        $this->assertSame(0, DB::table('laporan_kendala_dibaca')->count());
    }

    public function test_tanpa_hak_lihat_ditolak(): void
    {
        $role = $this->role('Tanpa Kendala', 'semua', ['home' => ['lihat']]);
        $this->actingAs($this->akun('biasa', 'lainnya', 500, ['role_id' => $role]));

        $this->postJson("/laporan_kendala/{$this->laporan}/dibaca")->assertForbidden();
    }
}
