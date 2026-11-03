<?php

namespace Tests\Feature\Admin;

use App\Support\Motoris\DetailMotoris;
use Illuminate\Support\Facades\DB;

// Modal Detail Motoris di Lacak Motoris: profil, ringkasan & harian per periode, transaksi per tanggal, produk terlaris, hak & cakupan.
class DetailMotorisTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 15 Nov 2026 10:00 WIB.
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(3, 0)->utc());

        DB::table('mk_karyawan')->where('id', 1)->update(['no_hp' => '0812', 'tanggal_mulai' => '01/08/2024']);
        DB::table('mk_area')->insert([['id' => 9, 'nama' => 'Sidoarjo Kota', 'depo_id' => 46], ['id' => 10, 'nama' => 'Waru', 'depo_id' => 46]]);
        DB::table('mk_karyawan_area')->insert([['karyawan_id' => 1, 'area_id' => 9, 'aktif' => true], ['karyawan_id' => 1, 'area_id' => 10, 'aktif' => false]]);
        DB::table('mk_outlet')->insert([['id' => 70, 'nama' => 'Toko Makmur'], ['id' => 71, 'nama' => 'Warung Bu Sri']]);
        DB::table('mk_produk')->insert([['id' => 5, 'nama' => 'Kopi Sachet'], ['id' => 6, 'nama' => 'Teh Botol']]);

        // 3 Nov: hadir terlambat, 2 kunjungan, 1 penjualan EC. 4 Nov: hadir, 1 kunjungan, 1 penjualan batal.
        // 20 Okt: di luar periode bawaan.
        DB::table('trx_absensi')->insert([
            ['karyawan_id' => 1, 'tanggal' => '2026-11-03', 'masuk_at' => '2026-11-03 01:15:00', 'pulang_at' => '2026-11-03 10:05:00', 'terlambat' => true],
            ['karyawan_id' => 1, 'tanggal' => '2026-11-04', 'masuk_at' => '2026-11-04 00:55:00', 'pulang_at' => null, 'terlambat' => false],
            ['karyawan_id' => 1, 'tanggal' => '2026-10-20', 'masuk_at' => '2026-10-20 00:50:00', 'pulang_at' => null, 'terlambat' => false],
            ['karyawan_id' => 3, 'tanggal' => '2026-11-03', 'masuk_at' => '2026-11-03 00:50:00', 'pulang_at' => null, 'terlambat' => false],
        ]);
        $kunjungan = fn (string $tgl, int $outlet) => DB::table('trx_kunjungan')->insertGetId([
            'karyawan_id' => 1, 'outlet_id' => $outlet, 'tanggal' => $tgl, 'checkin_at' => "{$tgl} 02:00:00",
        ]);
        $k1 = $kunjungan('2026-11-03', 70);
        $kunjungan('2026-11-03', 71);
        $k3 = $kunjungan('2026-11-04', 71);
        $k4 = $kunjungan('2026-10-20', 70);

        $jual = fn (string $kode, int $kj, string $tgl, int $total, array $lain = []) => DB::table('trx_penjualan')->insertGetId($lain + [
            'kode' => $kode, 'kunjungan_id' => $kj, 'karyawan_id' => 1, 'outlet_id' => 70, 'tanggal' => $tgl, 'jumlah_sku' => 2,
            'total_qty' => 5, 'total' => $total, 'total_diskon' => 1000, 'is_effective_call' => true, 'recorded_at' => "{$tgl} 03:30:00",
        ]);
        $p1 = $jual('PJ-1', $k1, '2026-11-03', 150000);
        $p2 = $jual('PJ-2', $k3, '2026-11-04', 90000, ['outlet_id' => 71, 'dibatalkan_at' => '2026-11-04 05:00:00']);
        $p3 = $jual('PJ-OLD', $k4, '2026-10-20', 40000);

        DB::table('trx_penjualan_item')->insert([
            ['penjualan_id' => $p1, 'produk_id' => 5, 'qty' => 3, 'harga' => 30000, 'total' => 90000],
            ['penjualan_id' => $p1, 'produk_id' => 6, 'qty' => 2, 'harga' => 30000, 'total' => 60000],
            ['penjualan_id' => $p2, 'produk_id' => 6, 'qty' => 9, 'harga' => 10000, 'total' => 90000],
            ['penjualan_id' => $p3, 'produk_id' => 6, 'qty' => 4, 'harga' => 10000, 'total' => 40000],
        ]);
    }

    private function url(int $id = 1, array $query = []): string
    {
        return "/tracking_sales/{$id}/detail".($query ? '?'.http_build_query($query) : '');
    }

    private function urlTransaksi(int $id, string $tanggal): string
    {
        return "/tracking_sales/{$id}/detail/transaksi?tanggal={$tanggal}";
    }

    public function test_profil_motoris(): void
    {
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('profil.id', 1)
            ->assertJsonPath('profil.nama', 'Rudi Hartono')
            ->assertJsonPath('profil.no_hp', '0812')
            ->assertJsonPath('profil.depo', 'Sidoarjo')
            ->assertJsonPath('profil.koordinator', 'Koor Satu')
            ->assertJsonPath('profil.area', ['Sidoarjo Kota'])
            ->assertJsonPath('profil.tanggal_masuk', '2024-08-01')
            ->assertJsonPath('profil.masa_kerja', '2 tahun 3 bulan')
            ->assertJsonPath('profil.status', 'Aktif');
    }

    public function test_tanggal_masuk_tak_terbaca_jadi_null(): void
    {
        DB::table('mk_karyawan')->where('id', 1)->update(['tanggal_mulai' => '-']);

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('profil.tanggal_masuk', null)
            ->assertJsonPath('profil.masa_kerja', null);
    }

    public function test_periode_bawaan_awal_bulan_sampai_hari_ini(): void
    {
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('periode.dari', '2026-11-01')
            ->assertJsonPath('periode.sampai', '2026-11-15');
    }

    public function test_ringkasan_mengikuti_periode_dan_mengabaikan_transaksi_batal(): void
    {
        $res = $this->getJson($this->url())->assertOk();

        // 1–15 Nov 2026: 15 hari - 3 Minggu (1, 8, 15) = 12.
        $res->assertJsonPath('ringkasan.hari_kerja', 12)
            ->assertJsonPath('ringkasan.hadir', 2)
            ->assertJsonPath('ringkasan.terlambat', 1)
            ->assertJsonPath('ringkasan.kunjungan', 3)
            ->assertJsonPath('ringkasan.transaksi', 1)
            ->assertJsonPath('ringkasan.ec', 1)
            ->assertJsonPath('ringkasan.ipt', 2)
            ->assertJsonPath('ringkasan.target_ipt', 3)
            ->assertJsonPath('ringkasan.penjualan', 150000)
            ->assertJsonPath('ringkasan.qty', 5)
            ->assertJsonPath('ringkasan.rata_penjualan', 75000);

        // Periode Oktober hanya memuat data 20 Okt.
        $this->getJson($this->url(1, ['dari' => '2026-10-01', 'sampai' => '2026-10-31']))->assertOk()
            ->assertJsonPath('ringkasan.hadir', 1)
            ->assertJsonPath('ringkasan.penjualan', 40000)
            ->assertJsonPath('harian.0.tanggal', '2026-10-20');
    }

    public function test_ipt_adalah_rata_rata_sku_per_transaksi_aktif(): void
    {
        // Tambah transaksi 3 Nov dengan 5 SKU: (2 + 5) / 2 = 3,5; transaksi batal (PJ-2, 4 Nov) tidak dihitung.
        DB::table('trx_penjualan')->insert([
            'kode' => 'PJ-3', 'kunjungan_id' => 1, 'karyawan_id' => 1, 'outlet_id' => 70, 'tanggal' => '2026-11-03', 'jumlah_sku' => 5,
            'total_qty' => 5, 'total' => 1000, 'total_diskon' => 0, 'is_effective_call' => false, 'recorded_at' => '2026-11-03 04:30:00',
        ]);

        $res = $this->getJson($this->url())->assertOk()->assertJsonPath('ringkasan.ipt', 3.5)->assertJsonPath('target.ipt', 3);

        $this->assertSame(3.5, $res->json('harian.0.ipt'));
    }

    public function test_hari_kerja_tidak_menghitung_minggu_dan_libur_nasional(): void
    {
        // Oktober 2026: 31 hari - 4 Minggu (4, 11, 18, 25) = 27; libur 20 Okt (Selasa) = 26; libur 25 Okt jatuh di Minggu, tidak dihitung dua kali.
        DB::table('holiday_dates')->insert([
            ['nama_libur' => 'Libur Contoh', 'tanggal' => '2026-10-20'],
            ['nama_libur' => 'Libur Minggu', 'tanggal' => '2026-10-25'],
        ]);

        $this->assertSame(26, DetailMotoris::hariKerja('2026-10-01', '2026-10-31'));
    }

    public function test_rekap_harian(): void
    {
        $harian = $this->getJson($this->url())->assertOk()->json('harian');

        $this->assertSame(['2026-11-03', '2026-11-04'], array_column($harian, 'tanggal'));
        $this->assertSame('08:15', $harian[0]['masuk']);
        $this->assertSame('17:05', $harian[0]['pulang']);
        $this->assertNull($harian[1]['pulang']);
        $this->assertTrue($harian[0]['terlambat']);
        $this->assertSame([2, 1, 1, 150000], [$harian[0]['kunjungan'], $harian[0]['ec'], $harian[0]['transaksi'], $harian[0]['penjualan']]);
        $this->assertEquals([2, 0], [$harian[0]['ipt'], $harian[1]['ipt']]);
        $this->assertSame([1, 0, 0, 0], [$harian[1]['kunjungan'], $harian[1]['ec'], $harian[1]['transaksi'], $harian[1]['penjualan']]);
    }

    public function test_target_call_dan_ec_dari_pengaturan_depo_dikali_hari_kerja(): void
    {
        $hk = 12;
        $res = $this->getJson($this->url())->assertOk();

        // Depo 46 tanpa pengaturan target: memakai bawaan config('mobile.target_default').
        $res->assertJsonPath('target.call', 40)->assertJsonPath('target.ec', 25)
            ->assertJsonPath('ringkasan.target_call', 40 * $hk)->assertJsonPath('ringkasan.target_ec', 25 * $hk);

        DB::table('mk_depo')->where('id', 46)->update(['data' => json_encode(['target_call' => 30, 'target_ec' => 12])]);

        $this->getJson($this->url())->assertOk()->assertJsonPath('target.call', 30)->assertJsonPath('target.ec', 12)
            ->assertJsonPath('ringkasan.target_call', 30 * $hk);
    }

    public function test_tanda_motoris_dalam_periode_termasuk_yang_sudah_diperiksa(): void
    {
        $baris = fn (string $tgl, string $status = 'perlu_dicek', ?string $cabut = null) => [
            'karyawan_id' => 1, 'tanggal' => $tgl, 'kategori' => 'lokasi_palsu', 'catatan' => 'Mock location', 'status' => $status,
            'hasil_catatan' => $status === 'perlu_dicek' ? null : 'Sudah dicek', 'dibuat_oleh' => null,
            'dibuat_nama' => 'Sistem', 'created_at' => "{$tgl} 05:00:00", 'dicabut_at' => $cabut,
        ];
        DB::table('tanda_motoris')->insert([
            $baris('2026-11-03'), $baris('2026-11-04', 'wajar', '2026-11-05 01:00:00'),
            $baris('2026-10-20'), ['karyawan_id' => 3] + $baris('2026-11-03'),
        ]);

        $res = $this->getJson($this->url())->assertOk()->assertJsonCount(2, 'tanda_motoris');

        $res->assertJsonPath('tanda_motoris.0.tanggal', '2026-11-04')
            ->assertJsonPath('tanda_motoris.0.kategori_label', 'Diduga lokasi / GPS palsu')
            ->assertJsonPath('tanda_motoris.0.status', 'wajar')
            ->assertJsonPath('tanda_motoris.0.status_label', 'Wajar')
            ->assertJsonPath('tanda_motoris.0.hasil_catatan', 'Sudah dicek')
            ->assertJsonPath('tanda_motoris.1.status', 'perlu_dicek')
            ->assertJsonPath('tanda_motoris.1.oleh', 'Sistem');
    }

    public function test_tanda_outlet_pada_outlet_yang_didaftarkan_motoris_ini(): void
    {
        // Outlet 70 didaftarkan Rudi lewat HP; 71 oleh pendaftar Marketama bernama sama; 72 oleh orang lain.
        DB::table('mk_outlet')->where('id', 71)->update(['dibuat_oleh' => 'Rudi Hartono']);
        DB::table('mk_outlet')->insert(['id' => 72, 'nama' => 'Toko Lain', 'dibuat_oleh' => 'Sinta Wulandari']);
        DB::table('trx_outlet_baru')->insert(['karyawan_id' => 1, 'nama' => 'Toko Makmur', 'lat' => -7.4, 'lng' => 112.7, 'status' => 'disetujui', 'outlet_id' => 70]);

        $tanda = fn (int $outlet) => [
            'outlet_id' => $outlet, 'kategori' => 'outlet_fiktif', 'catatan' => 'Tidak ada toko', 'status' => 'perlu_dicek', 'dibuat_oleh' => null,
            'dibuat_nama' => 'Sistem', 'created_at' => now()->utc(),
        ];
        foreach ([$tanda(70), $tanda(71), $tanda(72), ['dicabut_at' => now()->utc(), 'status' => 'wajar'] + $tanda(70)] as $baris) {
            DB::table('tanda_outlet')->insert($baris);
        }

        $res = $this->getJson($this->url())->assertOk()->assertJsonCount(2, 'tanda_outlet');

        $this->assertEqualsCanonicalizing(['Toko Makmur', 'Warung Bu Sri'], array_column($res->json('tanda_outlet'), 'outlet'));
        $res->assertJsonPath('tanda_outlet.0.kategori_label', 'Diduga outlet fiktif')->assertJsonPath('tanda_outlet.0.oleh', 'Sistem');
    }

    public function test_payload_utama_tidak_memuat_daftar_transaksi(): void
    {
        $this->getJson($this->url())->assertOk()->assertJsonMissingPath('transaksi')->assertJsonMissingPath('transaksi_total');
    }

    public function test_transaksi_satu_tanggal_termasuk_yang_batal(): void
    {
        $res = $this->getJson($this->urlTransaksi(1, '2026-11-03'))->assertOk()->assertJsonPath('tanggal', '2026-11-03');

        $this->assertSame(['PJ-1'], array_column($res->json('transaksi'), 'kode'));
        $res->assertJsonPath('transaksi.0.status', 'Sah')
            ->assertJsonPath('transaksi.0.outlet', 'Toko Makmur')
            ->assertJsonPath('transaksi.0.waktu', '2026-11-03 10:30')
            ->assertJsonPath('transaksi.0.total', 150000)
            ->assertJsonPath('transaksi.0.diskon', 1000);

        $this->getJson($this->urlTransaksi(1, '2026-11-04'))->assertOk()
            ->assertJsonPath('transaksi.0.kode', 'PJ-2')->assertJsonPath('transaksi.0.status', 'Batal')
            ->assertJsonPath('transaksi.0.outlet', 'Warung Bu Sri');
        $this->getJson($this->urlTransaksi(1, '2026-11-10'))->assertOk()->assertJsonCount(0, 'transaksi');
    }

    public function test_transaksi_satu_tanggal_butuh_tanggal_valid_dan_cakupan_role(): void
    {
        $this->getJson('/tracking_sales/1/detail/transaksi')->assertUnprocessable()->assertJsonValidationErrors('tanggal');
        $this->getJson($this->urlTransaksi(137, '2026-11-03'))->assertNotFound();

        $koor = $this->akun('koor', 'koordinator', 137);
        $this->actingAs($koor)->getJson($this->urlTransaksi(1, '2026-11-03'))->assertOk();
        $this->actingAs($koor)->getJson($this->urlTransaksi(2, '2026-11-03'))->assertNotFound();

        $role = $this->role('Tanpa Lacak', 'semua', ['karyawan_sales' => ['lihat']]);
        $this->actingAs($this->akun('lain', 'koordinator', 137, ['role_id' => $role]))
            ->getJson($this->urlTransaksi(1, '2026-11-03'))->assertForbidden();
    }

    public function test_produk_terlaris_dari_penjualan_tidak_batal(): void
    {
        $this->getJson($this->url())->assertOk()
            ->assertJsonCount(2, 'produk')
            ->assertJsonPath('produk.0.nama', 'Kopi Sachet')
            ->assertJsonPath('produk.0.qty', 3)
            ->assertJsonPath('produk.0.nilai', 90000)
            ->assertJsonPath('produk.1.nama', 'Teh Botol')
            ->assertJsonPath('produk.1.qty', 2);
    }

    public function test_validasi_periode(): void
    {
        $this->getJson($this->url(1, ['dari' => '2026-11-10', 'sampai' => '2026-11-01']))->assertUnprocessable()->assertJsonValidationErrors('sampai');
        $this->getJson($this->url(1, ['dari' => '2025-01-01', 'sampai' => '2026-11-01']))->assertUnprocessable()->assertJsonValidationErrors('sampai');
    }

    public function test_pilihan_motoris_hanya_sales_aktif_dengan_depo(): void
    {
        $res = $this->getJson('/tracking_sales/pilihan-motoris')->assertOk();

        // Super admin: Rudi, Sinta, Tegar (aktif); Joko (tidak aktif), koordinator, dan staf lain tidak ikut. Urut nama.
        $this->assertSame(
            [['value' => '1', 'label' => 'Rudi Hartono · Sidoarjo'], ['value' => '2', 'label' => 'Sinta Wulandari · Balaraja'], ['value' => '3', 'label' => 'Tegar Mahendra · Sidoarjo']],
            $res->json('motoris')
        );
    }

    public function test_pilihan_motoris_menyertakan_motoris_yang_sedang_dibuka_walau_tidak_aktif(): void
    {
        $nilai = fn ($res) => array_column($res->json('motoris'), 'value');

        $this->assertNotContains('4', $nilai($this->getJson('/tracking_sales/pilihan-motoris')));
        $this->assertContains('4', $nilai($this->getJson('/tracking_sales/pilihan-motoris?id=4')));
    }

    public function test_pilihan_motoris_mengikuti_cakupan_role_dan_hak(): void
    {
        $koor = $this->akun('koor', 'koordinator', 137);
        $this->assertSame(['1', '3'], array_column($this->actingAs($koor)->getJson('/tracking_sales/pilihan-motoris')->json('motoris'), 'value'));

        $role = $this->role('Tanpa Lacak', 'semua', ['karyawan_sales' => ['lihat']]);
        $this->actingAs($this->akun('lain', 'koordinator', 137, ['role_id' => $role]))->getJson('/tracking_sales/pilihan-motoris')->assertForbidden();
    }

    public function test_bukan_sales_atau_tidak_ada_404(): void
    {
        $this->getJson($this->url(137))->assertNotFound();
        $this->getJson($this->url(999))->assertNotFound();
    }

    public function test_koordinator_hanya_bisa_membuka_motoris_bawahannya(): void
    {
        $koor = $this->akun('koor', 'koordinator', 137);

        $this->actingAs($koor)->getJson($this->url(1))->assertOk();
        $this->actingAs($koor)->getJson($this->url(2))->assertNotFound();
    }

    public function test_tanpa_hak_lacak_motoris_ditolak(): void
    {
        $role = $this->role('Tanpa Lacak', 'semua', ['karyawan_sales' => ['lihat']]);
        $user = $this->akun('lain', 'koordinator', 137, ['role_id' => $role]);

        $this->actingAs($user)->getJson($this->url())->assertForbidden();
    }
}
