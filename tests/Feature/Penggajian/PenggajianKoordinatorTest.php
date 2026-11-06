<?php

namespace Tests\Feature\Penggajian;

use App\Support\Penggajian\HitungGaji;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\AdminTestCase;

// Penggajian koordinator: alur gaji yang sama dengan sales (draf, izin, manual, hitung ulang, final, batal, riwayat)
// di /penggajian_koordinator, terpisah dari sales. Insentif koordinator menunggu aturan rumus untuk tim bukan 10 sales.
// Depo 46: koordinator Koor Satu (137), sales Rudi (1) dan Tegar (3). Depo 22: koordinator Koor Dua (138), sales Sinta (2).
class PenggajianKoordinatorTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-25 10:00:00');

        DB::table('mk_karyawan')->insert(['id' => 138, 'kategori' => 'koordinator', 'nama' => 'Koor Dua', 'depo_id' => 22, 'koordinator_id' => null, 'aktif' => true]);
        DB::table('mk_karyawan')->whereIn('id', [1, 2, 3, 137, 138])->update(['gaji_pokok' => 4000000, 'tunjangan_internet' => 100000, 'tunjangan_jabatan' => 500000, 'tunjangan_bbm_makan' => 540000]);
        DB::table('parameter_pemotong_gaji')->insert(['id' => 5, 'nama' => 'Potongan BPJS Kesehatan']);
    }

    private function buat(string $jenis, int $depo = 46, string $mulai = '2026-10-20', string $kategori = 'gaji')
    {
        return $this->post("/penggajian_{$jenis}", ['kategori' => $kategori, 'depo_id' => $depo, 'periode_mulai' => $mulai]);
    }

    private function id(): int
    {
        return (int) DB::table('penggajian')->orderByDesc('id')->value('id');
    }

    public function test_gaji_koordinator_hanya_memuat_koordinator_aktif_di_depo_itu(): void
    {
        $this->buat('koordinator')->assertSessionHasNoErrors();

        $p = DB::table('penggajian')->first();
        $this->assertSame(['koordinator', 'gaji', 'draf', 46], [$p->jenis, $p->kategori, $p->status, (int) $p->depo_id]);
        $this->assertSame(['2026-10-20', '2026-11-19', '2026-11-25'], [$p->periode_mulai, $p->periode_selesai, $p->tanggal_bayar]);
        $this->assertStringStartsWith('GJK-202611-046-', $p->no_penggajian);
        $this->assertSame(['Koor Satu'], DB::table('penggajian_detail')->pluck('nama')->all()); // bukan Rudi dan Tegar
    }

    public function test_komponen_gaji_koordinator_penuh_dan_prorata_dengan_asal_angka(): void
    {
        foreach (['2026-10-21', '2026-10-22', '2026-10-23'] as $t) {
            DB::table('trx_absensi')->insert(['karyawan_id' => 137, 'tanggal' => $t, 'masuk_at' => "{$t} 08:00:00", 'terlambat' => false]);
        }

        $this->buat('koordinator');
        $detail = (int) DB::table('penggajian_detail')->value('id');
        $nominal = fn (string $kode) => (float) DB::table('penggajian_komponen')->where('detail_id', $detail)->where('kode', $kode)->value('nominal');

        $this->assertSame([4000000.0, 100000.0, 500000.0], [$nominal('gaji_pokok'), $nominal('tunjangan_internet'), $nominal('tunjangan_jabatan')]);
        $this->assertSame(60000.0, $nominal('tunjangan_bbm_makan')); // 540.000 × 3/27
        $this->assertEquals(
            ['nilai_bulanan' => 540000, 'hari_hadir' => 3, 'hari_izin' => 0, 'hari_kerja' => 27],
            json_decode(DB::table('penggajian_komponen')->where('detail_id', $detail)->where('kode', 'tunjangan_bbm_makan')->value('rincian'), true),
        );
    }

    public function test_tingkat_kehadiran_koordinator_dibagi_jumlah_koordinator_karena_tidak_ada_kuota(): void
    {
        DB::table('master_kuota_motoris')->insert(['depo_id' => 46, 'berlaku_mulai' => '2026-11', 'kuota' => 50]); // kuota sales, tidak dipakai
        foreach (['2026-10-21', '2026-10-22', '2026-10-23'] as $t) {
            DB::table('trx_absensi')->insert(['karyawan_id' => 137, 'tanggal' => $t, 'masuk_at' => "{$t} 08:00:00", 'terlambat' => false]);
        }

        $this->buat('koordinator');
        $id = $this->id();

        $this->get("/penggajian_koordinator/{$id}")->assertInertia(fn (Assert $p) => $p
            ->where('penggajian.total_hadir', 3)
            ->where('penggajian.dasar_hadir', 'orang')
            ->where('penggajian.pembagi_hadir', 1)
            ->where('penggajian.tingkat_hadir', 11.1)); // 3 ÷ (27 × 1 koordinator)
    }

    public function test_koordinator_yang_sering_terlambat_tidak_dikenai_potongan_terlambat(): void
    {
        foreach (['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-26', '2026-10-27', '2026-10-28'] as $t) {
            DB::table('trx_absensi')->insert(['karyawan_id' => 137, 'tanggal' => $t, 'masuk_at' => "{$t} 08:00:00", 'terlambat' => true]);
        }

        $this->buat('koordinator');
        $detail = (int) DB::table('penggajian_detail')->value('id');

        $this->assertSame(6, (int) DB::table('penggajian_detail')->find($detail)->hari_terlambat);
        $this->assertSame(0, DB::table('penggajian_komponen')->where('detail_id', $detail)->where('tipe', 'potongan')->count());
    }

    public function test_halaman_menampilkan_hanya_penggajian_berjenis_sendiri(): void
    {
        $this->buat('sales');
        $this->buat('koordinator');

        $this->get('/penggajian_koordinator')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Penggajian/Index')
            ->where('tab', 'gaji')
            ->where('baris.total', 1)
            ->where('baris.data.0.no_penggajian', fn ($v) => str_starts_with($v, 'GJK-'))
            ->where('orangLabel', 'koordinator')
            ->where('slug', 'penggajian_koordinator'));

        $this->get('/penggajian_sales')->assertInertia(fn (Assert $p) => $p
            ->where('baris.total', 1)
            ->where('baris.data.0.no_penggajian', fn ($v) => str_starts_with($v, 'GJS-'))
            ->where('orangLabel', 'motoris')
            ->where('slug', 'penggajian_sales'));
    }

    public function test_detail_hanya_bisa_dibuka_lewat_halaman_jenisnya(): void
    {
        $this->buat('sales');
        $sales = $this->id();
        $this->buat('koordinator');
        $koor = $this->id();

        $this->get("/penggajian_koordinator/{$koor}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('Penggajian/Detail')->where('detail.0.nama', 'Koor Satu'));
        $this->get("/penggajian_koordinator/{$sales}")->assertNotFound();
        $this->get("/penggajian_sales/{$koor}")->assertNotFound();
        $this->post("/penggajian_sales/{$koor}/finalkan")->assertNotFound();
        $this->post("/penggajian_koordinator/{$sales}/finalkan")->assertNotFound();
        $this->assertSame('draf', DB::table('penggajian')->find($koor)->status);
    }

    public function test_gaji_sales_dan_koordinator_periode_sama_tidak_saling_menghalangi_tetapi_sesama_jenis_ya(): void
    {
        $this->buat('sales')->assertSessionHasNoErrors();
        $this->buat('koordinator')->assertSessionHasNoErrors();
        $no = DB::table('penggajian')->where('jenis', 'koordinator')->value('no_penggajian');

        $this->buat('koordinator', 46, '2026-11-01')->assertSessionHasErrors('periode_mulai');
        $this->assertStringContainsString($no, session('errors')->first('periode_mulai'));
        $this->assertSame(2, DB::table('penggajian')->count());
    }

    public function test_alur_draf_izin_manual_hitung_ulang_final_dan_terkunci(): void
    {
        $this->buat('koordinator');
        $id = $this->id();
        $url = "/penggajian_koordinator/{$id}";
        $detail = (int) DB::table('penggajian_detail')->value('id');

        $this->put("{$url}/detail/{$detail}/izin", ['hari_izin' => 3])->assertSessionHasNoErrors();
        $this->assertSame(60000.0, (float) DB::table('penggajian_komponen')->where('detail_id', $detail)->where('kode', 'tunjangan_bbm_makan')->value('nominal'));

        $this->post("{$url}/detail/{$detail}/komponen", ['tipe' => 'potongan', 'parameter_pemotong_id' => 5, 'nominal' => 200000])->assertSessionHasNoErrors();
        $d = DB::table('penggajian_detail')->find($detail);
        $this->assertSame(200000.0, (float) $d->total_potongan);
        $this->assertSame((float) $d->total_pendapatan - 200000, (float) $d->total_bersih);

        DB::table('mk_karyawan')->where('id', 137)->update(['gaji_pokok' => 4500000]);
        $this->post("{$url}/hitung-ulang")->assertSessionHasNoErrors();
        $this->assertSame(4500000.0, (float) DB::table('penggajian_komponen')->where('detail_id', $detail)->where('kode', 'gaji_pokok')->value('nominal'));
        $this->assertSame(1, DB::table('penggajian_komponen')->where('detail_id', $detail)->where('sumber', 'manual')->count());
        $this->assertSame(3, (int) DB::table('penggajian_detail')->find($detail)->hari_izin);

        $this->post("{$url}/finalkan")->assertSessionHasNoErrors();
        $this->assertSame('final', DB::table('penggajian')->find($id)->status);

        DB::table('mk_karyawan')->where('id', 137)->update(['gaji_pokok' => 1]);
        $this->assertSame(4500000.0, (float) DB::table('penggajian_komponen')->where('detail_id', $detail)->where('kode', 'gaji_pokok')->value('nominal'));
        $this->post("{$url}/hitung-ulang")->assertSessionHasErrors('penggajian');
        $this->post("{$url}/batalkan")->assertSessionHasErrors('penggajian');
    }

    public function test_finalkan_ditolak_selama_gaji_pokok_koordinator_kosong_dan_draf_bisa_dibatalkan(): void
    {
        DB::table('mk_karyawan')->where('id', 137)->update(['gaji_pokok' => null]);
        $this->buat('koordinator');
        $id = $this->id();

        $this->get("/penggajian_koordinator/{$id}")->assertInertia(fn (Assert $p) => $p
            ->has('kekurangan', 1)->where('kekurangan.0.nama', 'Koor Satu'));
        $this->post("/penggajian_koordinator/{$id}/finalkan")->assertSessionHasErrors('penggajian');

        $this->post("/penggajian_koordinator/{$id}/batalkan")->assertRedirect('/penggajian_koordinator');
        $this->assertSame('batal', DB::table('penggajian')->find($id)->status);
        $this->buat('koordinator')->assertSessionHasNoErrors(); // draf batal tidak menghalangi
    }

    public function test_riwayat_koordinator_dan_sales_tidak_saling_tampil(): void
    {
        $this->buat('sales');
        $this->post('/penggajian_sales/'.$this->id().'/finalkan');
        $this->buat('koordinator');
        $this->post('/penggajian_koordinator/'.$this->id().'/finalkan');

        $this->get('/penggajian_koordinator?tab=riwayat')->assertInertia(fn (Assert $p) => $p
            ->where('baris.total', 1)->where('baris.data.0.nama', 'Koor Satu')->where('tab', 'riwayat'));
        $this->get('/penggajian_sales?tab=riwayat')->assertInertia(fn (Assert $p) => $p
            ->where('baris.total', 2)->where('baris.data.0.nama', fn ($n) => $n !== 'Koor Satu'));
        $this->get('/penggajian_koordinator?tab=riwayat&q=rudi')->assertInertia(fn (Assert $p) => $p->where('baris.total', 0));
    }

    public function test_insentif_koordinator_ditolak_sampai_rumus_tim_bukan_10_sales_diputuskan(): void
    {
        $this->buat('koordinator', 46, '2026-10-01', 'insentif')->assertSessionHasErrors('kategori');

        $this->assertStringContainsString('Rumus Insentif Koordinator', session('errors')->first('kategori'));
        $this->assertSame(0, DB::table('penggajian')->count());

        $this->get('/penggajian_koordinator?tab=insentif')->assertInertia(fn (Assert $p) => $p
            ->where('tab', 'insentif')->where('bisaTambah', false)->where('catatan', fn ($c) => str_contains($c, 'Rumus Insentif Koordinator')));
        $this->get('/penggajian_koordinator/tambah?kategori=insentif')->assertInertia(fn (Assert $p) => $p
            ->where('kategori', 'insentif')->where('catatan', fn ($c) => str_contains($c, 'Rumus Insentif Koordinator')));
        $this->get('/penggajian_koordinator/tambah')->assertInertia(fn (Assert $p) => $p->where('catatan', null));
        $this->get('/penggajian_sales?tab=insentif')->assertInertia(fn (Assert $p) => $p->where('catatan', null)->where('bisaTambah', true));
    }

    public function test_depo_koordinator_di_luar_cakupan_dan_depo_tanpa_koordinator_ditolak(): void
    {
        $role = $this->role('Admin Depo', 'depo', ['penggajian_koordinator' => ['lihat', 'tambah']]);
        $this->actingAs($this->akun('depo46', 'koordinator', 137, ['role_id' => $role]));

        $this->buat('koordinator', 22)->assertSessionHasErrors('depo_id');
        $this->assertSame(0, DB::table('penggajian')->count());

        $this->actingAs($this->admin);
        DB::table('mk_depo')->insert(['id' => 99, 'nama' => 'Tanpa Koordinator']);
        $this->buat('koordinator', 99)->assertSessionHasErrors('depo_id');
        $this->assertStringContainsString('koordinator', session('errors')->first('depo_id'));
    }

    public function test_selain_super_admin_ditolak_dari_halaman_dan_aksi_koordinator(): void
    {
        $this->buat('koordinator');
        $id = $this->id();

        $this->actingAs($this->akun('koor', 'koordinator', 137));
        $this->get('/penggajian_koordinator')->assertForbidden();
        $this->get("/penggajian_koordinator/{$id}")->assertForbidden();
        $this->buat('koordinator')->assertForbidden();
        $this->post("/penggajian_koordinator/{$id}/finalkan")->assertForbidden();
        $this->assertSame('draf', DB::table('penggajian')->find($id)->status);
    }

    public function test_rumus_gaji_koordinator_memakai_komponen_yang_sama_dengan_sales(): void
    {
        $k = (object) ['gaji_pokok' => 4000000, 'tunjangan_internet' => 100000, 'tunjangan_jabatan' => 500000, 'tunjangan_bbm_makan' => 540000, 'sewa_motor' => 0];

        $this->assertSame(
            HitungGaji::sales($k, ['hadir' => 9, 'terlambat' => 1], 27, 2),
            HitungGaji::koordinator($k, ['hadir' => 9, 'terlambat' => 1], 27, 2),
        );
    }
}
