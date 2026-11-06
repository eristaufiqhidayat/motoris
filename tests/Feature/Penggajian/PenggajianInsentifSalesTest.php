<?php

namespace Tests\Feature\Penggajian;

use App\Support\Penggajian\PeriodeGaji;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\AdminTestCase;

// Penggajian insentif sales: bulan kalender, tanggal bayar dari pengaturan, insentif EC dan nilai poin dengan asal
// angkanya, terpisah dari gaji. Depo 46: Rudi (1) dan Tegar (3) aktif. Data penjualan Oktober Rudi: 2 EC dan 15 poin.
class PenggajianInsentifSalesTest extends AdminTestCase
{
    private int $id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-06 10:00:00');

        DB::table('mk_karyawan')->whereIn('id', [1, 3])->update(['gaji_pokok' => 2500000]);
        DB::table('rumus_insentif_sales')->insert([
            ['tot_ec_lower_bound' => '0', 'tot_ec_upper_bound' => '1', 'total_insentif' => '0'],
            ['tot_ec_lower_bound' => '2', 'tot_ec_upper_bound' => '100', 'total_insentif' => '50000'],
        ]);
        DB::table('sales_point_conversion_per_warehouse')->insert(['depo' => 'Sidoarjo', 'poin_insentif_rp' => 100]);
        DB::table('parameter_pemotong_gaji')->insert(['id' => 5, 'nama' => 'Potongan BPJS Kesehatan']);

        $kj = DB::table('trx_kunjungan')->insertGetId(['karyawan_id' => 1, 'tanggal' => '2026-10-06', 'checkin_at' => '2026-10-06 02:00:00']);
        $jual = fn ($kode, $tgl, $ec, $poin) => ['kode' => $kode, 'kunjungan_id' => $kj, 'karyawan_id' => 1, 'tanggal' => $tgl,
            'jumlah_sku' => 1, 'total_qty' => 1, 'total' => 1000, 'is_effective_call' => $ec, 'poin' => $poin];
        DB::table('trx_penjualan')->insert([$jual('A', '2026-10-01', true, 10), $jual('B', '2026-10-06', true, 5), $jual('C', '2026-10-06', false, 0), $jual('D', '2026-09-30', true, 99), $jual('E', '2026-11-01', true, 7)]);
    }

    private function buat(string $mulai = '2026-10-01', int $depo = 46)
    {
        return $this->post('/penggajian_sales', ['kategori' => 'insentif', 'depo_id' => $depo, 'periode_mulai' => $mulai]);
    }

    private function buatId(string $mulai = '2026-10-01'): int
    {
        $this->buat($mulai);

        return $this->id = (int) DB::table('penggajian')->where('kategori', 'insentif')->orderByDesc('id')->value('id');
    }

    private function detail(int $karyawan): int
    {
        return (int) DB::table('penggajian_detail')->where('penggajian_id', $this->id)->where('karyawan_id', $karyawan)->value('id');
    }

    private function komponen(int $karyawan, string $kode): ?object
    {
        return DB::table('penggajian_komponen')->where('detail_id', $this->detail($karyawan))->where('kode', $kode)->first();
    }

    public function test_insentif_periode_bulan_kalender_dengan_tanggal_bayar_dari_pengaturan(): void
    {
        $this->buat('2026-11-10')->assertSessionHasNoErrors(); // tanggal mana pun di bulan itu

        $p = DB::table('penggajian')->first();
        $this->assertSame(['sales', 'insentif', 'draf'], [$p->jenis, $p->kategori, $p->status]);
        $this->assertSame(['2026-11-01', '2026-11-30', '2026-12-15'], [$p->periode_mulai, $p->periode_selesai, $p->tanggal_bayar]);
        $this->assertStringStartsWith('INS-202611-046-', $p->no_penggajian);
    }

    public function test_tanggal_bayar_tersimpan_sehingga_perubahan_pengaturan_tidak_mengubahnya(): void
    {
        $id = $this->buatId('2026-11-01');

        PeriodeGaji::ubahPengaturan('bayar_insentif', 5, '2026-11-06', $this->admin);

        $this->assertSame('2026-12-05', PeriodeGaji::insentif('2026-11-10')['tanggal_bayar']); // pengaturan baru berlaku
        $this->assertSame('2026-12-15', DB::table('penggajian')->find($id)->tanggal_bayar); // penggajian lama tetap
        $this->get("/penggajian_sales/{$id}")->assertInertia(fn (Assert $p) => $p->where('penggajian.tanggal_bayar', '2026-12-15'));
    }

    public function test_rincian_memuat_insentif_ec_dan_nilai_poin_dengan_asal_angka(): void
    {
        $this->buatId('2026-10-01');

        $ec = $this->komponen(1, 'insentif_ec');
        $this->assertSame(50000.0, (float) $ec->nominal);
        $this->assertSame(
            ['total_ec' => 2, 'tingkat' => ['bawah' => 2, 'atas' => 100, 'insentif' => 50000]],
            json_decode($ec->rincian, true),
        );

        $poin = $this->komponen(1, 'insentif_poin');
        $this->assertSame(1500.0, (float) $poin->nominal);
        $this->assertSame(['total_poin' => 15, 'rupiah_per_poin' => 100], json_decode($poin->rincian, true));

        $d = DB::table('penggajian_detail')->find($this->detail(1));
        $this->assertSame([51500.0, 0.0, 51500.0], [(float) $d->total_pendapatan, (float) $d->total_potongan, (float) $d->total_bersih]);
        $this->assertNull($d->hari_kerja);
        $this->assertNull($d->hari_hadir);
        $this->assertNull($d->hari_izin);

        // Tegar tanpa penjualan: insentif EC bertingkat 0, dan tetap ada barisnya.
        $this->assertSame(0.0, (float) $this->komponen(3, 'insentif_ec')->nominal);
    }

    public function test_komponen_dan_gaji_pokok_tidak_masuk_insentif(): void
    {
        $this->buatId('2026-10-01');

        $this->assertNull($this->komponen(1, 'gaji_pokok'));
        $this->assertNull($this->komponen(1, 'tunjangan_bbm_makan'));
    }

    public function test_angka_insentif_sama_dengan_lap_insentif_sales_bulan_yang_sama(): void
    {
        $this->buatId('2026-10-01');
        $kita = (float) DB::table('penggajian_detail')->find($this->detail(1))->total_pendapatan;

        $this->assertSame('Rp '.number_format($kita, 0, ',', '.'), 'Rp 51.500');
        $this->get('/lap_insentif_sales?tanggal=2026-10-15')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps(fn (Assert $p) => $p
                ->where('tables.0.rows.data.0.nama_sales', 'Rudi Hartono')
                ->where('tables.0.rows.data.0.insentif_ec', 'Rp 50.000')
                ->where('tables.0.rows.data.0.insentif_poin', 'Rp 1.500')
                ->where('tables.0.rows.data.0.total_insentif', 'Rp '.number_format($kita, 0, ',', '.'))));
    }

    public function test_gaji_dan_insentif_pada_periode_bertumpuk_tidak_saling_menghalangi(): void
    {
        $this->post('/penggajian_sales', ['depo_id' => 46, 'periode_mulai' => '2026-10-20'])->assertSessionHasNoErrors();
        $this->buat('2026-10-01')->assertSessionHasNoErrors();
        $this->buat('2026-11-01')->assertSessionHasNoErrors();

        $this->assertSame(3, DB::table('penggajian')->count());
        $this->assertSame(['gaji', 'insentif', 'insentif'], DB::table('penggajian')->orderBy('id')->pluck('kategori')->all());
    }

    public function test_dua_insentif_yang_tumpang_tindih_untuk_motoris_yang_sama_ditolak(): void
    {
        $this->buatId('2026-10-01');
        $no = DB::table('penggajian')->value('no_penggajian');

        $this->buat('2026-10-20')->assertSessionHasErrors('periode_mulai'); // bulan yang sama

        $this->assertStringContainsString($no, session('errors')->first('periode_mulai'));
        $this->assertSame(1, DB::table('penggajian')->count());
    }

    public function test_draf_insentif_bisa_komponen_manual_hitung_ulang_final_dan_final_terkunci(): void
    {
        $this->buatId('2026-10-01');
        $url = "/penggajian_sales/{$this->id}";
        $detail = $this->detail(1);

        $this->post("{$url}/detail/{$detail}/komponen", ['tipe' => 'pendapatan', 'nama' => 'Bonus', 'nominal' => 25000])->assertSessionHasNoErrors();
        $this->assertSame(76500.0, (float) DB::table('penggajian_detail')->find($detail)->total_bersih);

        // Penjualan baru lalu Hitung Ulang: komponen otomatis diperbarui, manual dipertahankan.
        DB::table('trx_penjualan')->insert(['kode' => 'F', 'kunjungan_id' => DB::table('trx_kunjungan')->value('id'), 'karyawan_id' => 1, 'tanggal' => '2026-10-20',
            'jumlah_sku' => 1, 'total_qty' => 1, 'total' => 1, 'is_effective_call' => true, 'poin' => 5]);
        $this->post("{$url}/hitung-ulang")->assertSessionHasNoErrors();
        $this->assertSame(2000.0, (float) $this->komponen(1, 'insentif_poin')->nominal); // 20 poin × 100
        $this->assertSame(3, json_decode($this->komponen(1, 'insentif_ec')->rincian, true)['total_ec']);
        $this->assertSame(1, DB::table('penggajian_komponen')->where('detail_id', $detail)->where('sumber', 'manual')->count());
        $this->assertSame(77000.0, (float) DB::table('penggajian_detail')->find($detail)->total_bersih);

        $this->post("{$url}/finalkan")->assertSessionHasNoErrors();
        $this->assertSame('final', DB::table('penggajian')->find($this->id)->status);

        $this->post("{$url}/hitung-ulang")->assertSessionHasErrors('penggajian');
        $this->post("{$url}/batalkan")->assertSessionHasErrors('penggajian');
    }

    public function test_draf_insentif_bisa_dibatalkan_dan_tidak_menghalangi_penggajian_baru(): void
    {
        $lama = $this->buatId('2026-10-01');

        $this->post("/penggajian_sales/{$lama}/batalkan")->assertSessionHasNoErrors();
        $this->assertSame('batal', DB::table('penggajian')->find($lama)->status);

        $this->buat('2026-10-01')->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('penggajian')->count());
    }

    public function test_hari_izin_hanya_untuk_gaji(): void
    {
        $this->buatId('2026-10-01');

        $this->put("/penggajian_sales/{$this->id}/detail/{$this->detail(1)}/izin", ['hari_izin' => 1])->assertSessionHasErrors();
        $this->assertNull(DB::table('penggajian_detail')->find($this->detail(1))->hari_izin);
    }

    public function test_finalkan_insentif_tidak_meminta_gaji_pokok(): void
    {
        DB::table('mk_karyawan')->whereIn('id', [1, 3])->update(['gaji_pokok' => null]);
        $this->buatId('2026-10-01');

        $this->get("/penggajian_sales/{$this->id}")->assertInertia(fn (Assert $p) => $p->where('penggajian.kategori', 'insentif')->where('detail.0.hari_tidak_hadir', null)->where('penggajian.total_hadir', null)->where('penggajian.rata_hadir', null)->where('penggajian.tingkat_hadir', null)->has('kekurangan', 0));
        $this->post("/penggajian_sales/{$this->id}/finalkan")->assertSessionHasNoErrors();
    }

    public function test_daftar_punya_tab_gaji_dan_insentif(): void
    {
        $this->post('/penggajian_sales', ['depo_id' => 46, 'periode_mulai' => '2026-10-20']);
        $this->buat('2026-10-01');
        $this->buat('2026-11-01');

        $this->get('/penggajian_sales')->assertInertia(fn (Assert $p) => $p
            ->component('Penggajian/Index')->where('tab', 'gaji')->where('baris.total', 1));
        $this->get('/penggajian_sales?tab=insentif')->assertInertia(fn (Assert $p) => $p
            ->where('tab', 'insentif')->where('baris.total', 2)->where('baris.data.0.periode', fn ($v) => str_starts_with($v, 'November 2026')));
        $this->get('/penggajian_sales?tab=lain')->assertInertia(fn (Assert $p) => $p->where('tab', 'gaji')->where('baris.total', 1));
    }

    public function test_form_tambah_insentif_menawarkan_bulan_kalender(): void
    {
        $this->get('/penggajian_sales/tambah?kategori=insentif')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Penggajian/Tambah')
            ->where('kategori', 'insentif')
            ->where('periode.0.mulai', '2026-11-01')
            ->where('periode.0.selesai', '2026-11-30')
            ->where('periode.0.label', 'November 2026')
            ->where('periode.0.tanggal_bayar', '2026-12-15')
            ->has('periode', 6));

        $this->get('/penggajian_sales/tambah')->assertInertia(fn (Assert $p) => $p->where('kategori', 'gaji')->where('periode.0.mulai', '2026-10-20'));
    }

    public function test_kategori_tidak_dikenal_ditolak(): void
    {
        $this->post('/penggajian_sales', ['kategori' => 'bonus', 'depo_id' => 46, 'periode_mulai' => '2026-10-01'])->assertSessionHasErrors('kategori');
        $this->assertSame(0, DB::table('penggajian')->count());
    }

    public function test_depo_di_luar_cakupan_dan_selain_super_admin_ditolak(): void
    {
        $role = $this->role('Admin Depo', 'depo', ['penggajian_sales' => ['lihat', 'tambah']]);
        $this->actingAs($this->akun('depo46', 'koordinator', 137, ['role_id' => $role]));
        $this->buat('2026-10-01', 22)->assertSessionHasErrors('depo_id');

        $this->actingAs($this->akun('staf', 'lainnya', 500));
        $this->buat()->assertForbidden();
        $this->assertSame(0, DB::table('penggajian')->count());
    }
}
