<?php

namespace Tests\Feature\Admin;

use App\Support\Motoris\LaporanMotoris;
use Illuminate\Support\Facades\DB;

// Modal Detail Motoris di Lacak Motoris: profil, ringkasan & harian per periode, transaksi, produk terlaris, hak & cakupan.
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
            ['karyawan_id' => 1, 'tanggal' => '2026-11-03', 'masuk_at' => '2026-11-03 01:15:00', 'terlambat' => true],
            ['karyawan_id' => 1, 'tanggal' => '2026-11-04', 'masuk_at' => '2026-11-04 00:55:00', 'terlambat' => false],
            ['karyawan_id' => 1, 'tanggal' => '2026-10-20', 'masuk_at' => '2026-10-20 00:50:00', 'terlambat' => false],
            ['karyawan_id' => 3, 'tanggal' => '2026-11-03', 'masuk_at' => '2026-11-03 00:50:00', 'terlambat' => false],
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

        $res->assertJsonPath('ringkasan.hari_kerja', LaporanMotoris::hariKerja('2026-11-01', '2026-11-15'))
            ->assertJsonPath('ringkasan.hadir', 2)
            ->assertJsonPath('ringkasan.terlambat', 1)
            ->assertJsonPath('ringkasan.kunjungan', 3)
            ->assertJsonPath('ringkasan.transaksi', 1)
            ->assertJsonPath('ringkasan.ec', 1)
            ->assertJsonPath('ringkasan.rasio_ec', 33.3)
            ->assertJsonPath('ringkasan.penjualan', 150000)
            ->assertJsonPath('ringkasan.qty', 5)
            ->assertJsonPath('ringkasan.rata_penjualan', 75000);

        // Periode Oktober hanya memuat data 20 Okt.
        $this->getJson($this->url(1, ['dari' => '2026-10-01', 'sampai' => '2026-10-31']))->assertOk()
            ->assertJsonPath('ringkasan.hadir', 1)
            ->assertJsonPath('ringkasan.penjualan', 40000)
            ->assertJsonPath('transaksi.0.kode', 'PJ-OLD');
    }

    public function test_rekap_harian(): void
    {
        $harian = $this->getJson($this->url())->assertOk()->json('harian');

        $this->assertSame(['2026-11-03', '2026-11-04'], array_column($harian, 'tanggal'));
        $this->assertSame('08:15', $harian[0]['masuk']);
        $this->assertTrue($harian[0]['terlambat']);
        $this->assertSame([2, 1, 1, 150000], [$harian[0]['kunjungan'], $harian[0]['ec'], $harian[0]['transaksi'], $harian[0]['penjualan']]);
        $this->assertSame([1, 0, 0, 0], [$harian[1]['kunjungan'], $harian[1]['ec'], $harian[1]['transaksi'], $harian[1]['penjualan']]);
    }

    public function test_daftar_transaksi_termasuk_yang_batal(): void
    {
        $res = $this->getJson($this->url())->assertOk()->assertJsonPath('transaksi_total', 2);

        $this->assertSame(['PJ-2', 'PJ-1'], array_column($res->json('transaksi'), 'kode'));
        $res->assertJsonPath('transaksi.0.status', 'Batal')
            ->assertJsonPath('transaksi.0.outlet', 'Warung Bu Sri')
            ->assertJsonPath('transaksi.1.status', 'Sah')
            ->assertJsonPath('transaksi.1.waktu', '2026-11-03 10:30')
            ->assertJsonPath('transaksi.1.total', 150000)
            ->assertJsonPath('transaksi.1.diskon', 1000);
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
