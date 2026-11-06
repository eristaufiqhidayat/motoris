<?php

namespace Tests\Feature\Penggajian;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminTestCase;

// Membuat draf untuk semua motoris sekaligus: depo dikosongkan, sistem membuat satu draf per depo yang punya motoris aktif.
// Depo 46 (Sidoarjo): sales Rudi (1) dan Tegar (3), koordinator Koor Satu (137). Depo 22 (Balaraja): sales Sinta (2).
class PenggajianSemuaDepoTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-06 10:00:00');

        DB::table('mk_karyawan')->whereIn('id', [1, 2, 3, 137])->update(['gaji_pokok' => 2500000]);
        DB::table('rumus_insentif_sales')->insert(['tot_ec_lower_bound' => '0', 'tot_ec_upper_bound' => '100', 'total_insentif' => '50000']);
    }

    private function semua(string $jenis = 'sales', string $kategori = 'gaji', string $mulai = '2026-10-20', array $tambah = [])
    {
        return $this->post("/penggajian_{$jenis}", ['kategori' => $kategori, 'periode_mulai' => $mulai] + $tambah);
    }

    public function test_depo_kosong_membuat_satu_draf_per_depo_yang_punya_motoris_aktif(): void
    {
        $this->semua()->assertRedirect('/penggajian_sales?tab=gaji')->assertSessionHasNoErrors();

        $this->assertSame([22, 46], DB::table('penggajian')->orderBy('depo_id')->pluck('depo_id')->map(fn ($d) => (int) $d)->all());
        $this->assertSame(['Rudi Hartono', 'Sinta Wulandari', 'Tegar Mahendra'], DB::table('penggajian_detail')->orderBy('nama')->pluck('nama')->all());
        $this->assertSame(['sales', 'gaji', 'draf'], [DB::table('penggajian')->value('jenis'), DB::table('penggajian')->value('kategori'), DB::table('penggajian')->value('status')]);
        $this->assertSame(2, DB::table('riwayat_admin')->where('objek', 'penggajian')->where('aksi', 'buat')->count());
        $this->assertSame('Draf penggajian dibuat untuk 2 depo (3 orang).', session('sukses'));
        $this->assertNull(session('galat'));
    }

    public function test_depo_yang_periodenya_sudah_punya_penggajian_dilewati_dan_dilaporkan(): void
    {
        $this->post('/penggajian_sales', ['depo_id' => 46, 'periode_mulai' => '2026-10-20']);

        $this->semua()->assertSessionHasNoErrors();

        $this->assertSame(2, DB::table('penggajian')->count()); // 46 yang lama + 22 yang baru
        $this->assertSame('Draf penggajian dibuat untuk 1 depo (1 orang).', session('sukses'));
        $this->assertStringContainsString('Dilewati 1 depo', session('galat'));
        $this->assertStringContainsString('Sidoarjo', session('galat'));
    }

    public function test_bila_semua_depo_dilewati_ditolak_tanpa_membuat_apa_pun(): void
    {
        $this->semua();
        $sebelum = DB::table('penggajian')->count();

        $this->from('/penggajian_sales/tambah')->semua()->assertSessionHasErrors('periode_mulai');

        $this->assertSame($sebelum, DB::table('penggajian')->count());
        $this->assertStringContainsString('Tidak ada draf baru', session('errors')->first('periode_mulai'));
    }

    public function test_hanya_depo_dalam_cakupan_akun_yang_dibuat(): void
    {
        $role = $this->role('Admin Depo', 'depo', ['penggajian_sales' => ['lihat', 'tambah']]);
        $this->actingAs($this->akun('depo46', 'koordinator', 137, ['role_id' => $role]));

        $this->semua()->assertSessionHasNoErrors();

        $this->assertSame([46], DB::table('penggajian')->pluck('depo_id')->map(fn ($d) => (int) $d)->all());
    }

    public function test_insentif_semua_depo_memakai_bulan_kalender(): void
    {
        $this->semua('sales', 'insentif', '2026-10-01')->assertRedirect('/penggajian_sales?tab=insentif');

        $this->assertSame(2, DB::table('penggajian')->where('kategori', 'insentif')->count());
        $this->assertSame(['2026-10-01', '2026-10-31'], [DB::table('penggajian')->value('periode_mulai'), DB::table('penggajian')->value('periode_selesai')]);
    }

    public function test_gaji_koordinator_semua_depo_hanya_depo_yang_punya_koordinator_aktif(): void
    {
        $this->semua('koordinator')->assertRedirect('/penggajian_koordinator?tab=gaji');

        $this->assertSame([46], DB::table('penggajian')->pluck('depo_id')->map(fn ($d) => (int) $d)->all());
        $this->assertSame(['Koor Satu'], DB::table('penggajian_detail')->pluck('nama')->all());
        $this->assertSame('Draf penggajian dibuat untuk 1 depo (1 orang).', session('sukses'));
    }

    public function test_insentif_koordinator_semua_depo_tetap_ditolak(): void
    {
        $this->semua('koordinator', 'insentif', '2026-10-01')->assertSessionHasErrors('kategori');

        $this->assertSame(0, DB::table('penggajian')->count());
    }

    public function test_tanpa_motoris_aktif_di_depo_mana_pun_ditolak(): void
    {
        DB::table('mk_karyawan')->where('kategori', 'sales')->update(['aktif' => false]);

        $this->semua()->assertSessionHasErrors('depo_id');

        $this->assertSame(0, DB::table('penggajian')->count());
        $this->assertStringContainsString('motoris', session('errors')->first('depo_id'));
    }

    public function test_memilih_depo_tetap_membuat_satu_draf_seperti_sebelumnya(): void
    {
        $this->semua('sales', 'gaji', '2026-10-20', ['depo_id' => 22])->assertRedirect();

        $this->assertSame([22], DB::table('penggajian')->pluck('depo_id')->map(fn ($d) => (int) $d)->all());
        $this->assertSame('Draf penggajian dibuat.', session('sukses'));
    }

    public function test_selain_super_admin_tanpa_hak_ditolak(): void
    {
        $this->actingAs($this->akun('koor', 'koordinator', 137));

        $this->semua()->assertForbidden();

        $this->assertSame(0, DB::table('penggajian')->count());
    }
}
