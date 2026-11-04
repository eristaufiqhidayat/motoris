<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\TandaMotorisController;
use App\Models\User;
use App\Support\Akses\Akses;
use App\Support\Menu\JamLokal;
use Illuminate\Support\Facades\DB;

// Tanda motoris di drawer Riwayat Lacak Motoris (spec 062): tanda dibuat sistem (Perlu dicek); koordinator hanya melihat
// dan memeriksa (Terbukti / Wajar + catatan). Hak, cakupan role, validasi, audit. Tidak ada lagi tanda manual.
class TandaMotorisTest extends AdminTestCase
{
    private const URL = '/tracking_sales/tanda';

    private string $kemarin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kemarin = now()->subDay()->toDateString();
    }

    /** Koordinator bawaan (hak Tambah pada tracking_sales), downline: Rudi (1) dan Tegar (3); Sinta (2) bukan. */
    private function koordinator(string $username = 'koor'): User
    {
        return $this->akun($username, 'koordinator', 137);
    }

    private function akunDenganHak(string $username, array $aksi, string $cakupan = 'semua'): User
    {
        $role = $this->role('Peran '.$username, $cakupan, ['tracking_sales' => $aksi]);

        return $this->akun($username, 'koordinator', 137, ['role_id' => $role]);
    }

    /** Tanda sistem (Perlu dicek) untuk satu motoris pada satu tanggal. */
    private function tanda(int $sales = 1, ?string $tanggal = null, array $lain = []): int
    {
        return DB::table('tanda_motoris')->insertGetId($lain + [
            'karyawan_id' => $sales, 'tanggal' => $tanggal ?? $this->kemarin, 'kategori' => 'lokasi_palsu', 'catatan' => 'Mock location pada 3 titik GPS.',
            'status' => 'perlu_dicek', 'dibuat_oleh' => null, 'dibuat_nama' => 'Sistem', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function periksa(User $user, int $tanda, array $lain = [])
    {
        return $this->actingAs($user)->patchJson(self::URL."/{$tanda}/periksa", $lain + ['status' => 'terbukti', 'catatan' => 'Sudah dicek ke lokasi.']);
    }

    public function test_tidak_ada_lagi_endpoint_tanda_manual(): void
    {
        $koor = $this->koordinator();
        $tanda = $this->tanda();

        // Rute tambah/ubah/cabut sudah dihapus: tidak ada yang menjawab 2xx (405 bila path-nya masih dikenal, 404 selebihnya).
        foreach ([
            $this->actingAs($koor)->postJson(self::URL, ['sales_id' => 1, 'tanggal' => $this->kemarin, 'kategori' => 'lokasi_palsu']),
            $this->actingAs($koor)->patchJson(self::URL."/{$tanda}", ['catatan' => 'x']),
            $this->actingAs($koor)->deleteJson(self::URL."/{$tanda}"),
        ] as $res) {
            $this->assertContains($res->status(), [404, 405]);
        }
    }

    public function test_role_bawaan_koordinator_boleh_memeriksa_tanda(): void
    {
        $this->assertTrue(Akses::bisa($this->koordinator(), 'tracking_sales', 'tambah'));
    }

    public function test_daftar_memuat_tanda_perlu_dicek_dan_terbukti_tapi_tidak_yang_wajar(): void
    {
        $this->tanda(1);
        $this->tanda(1, null, ['kategori' => 'lokasi_palsu', 'status' => 'terbukti', 'hasil_catatan' => 'Benar', 'dicek_nama' => 'koor']);
        $this->tanda(1, null, ['status' => 'wajar', 'dicabut_at' => now()]);
        $this->tanda(1, now()->subDays(3)->toDateString()); // tanggal lain
        $this->tanda(2); // Sinta

        $res = $this->actingAs($this->koordinator())->getJson(self::URL.'?sales_id=1&tanggal='.$this->kemarin)->assertOk();

        $this->assertSame(['perlu_dicek', 'terbukti'], array_column($res->json('tanda'), 'status'));
        $res->assertJsonPath('tanda.0.oleh', 'Sistem')->assertJsonPath('tanda.0.status_label', 'Perlu dicek')->assertJsonPath('tanda.0.bisa_periksa', true)
            ->assertJsonPath('tanda.1.bisa_periksa', false)->assertJsonPath('tanda.1.hasil_catatan', 'Benar')
            ->assertJsonCount(1, 'lainnya');
    }

    public function test_akun_hanya_lihat_bisa_melihat_tapi_tidak_memeriksa(): void
    {
        $lihat = $this->akunDenganHak('pemantau', ['lihat']);
        $tanda = $this->tanda();

        $this->actingAs($lihat)->getJson(self::URL.'?sales_id=1&tanggal='.$this->kemarin)->assertOk()->assertJsonPath('tanda.0.bisa_periksa', false);
        $this->periksa($lihat, $tanda)->assertForbidden();
        $this->assertSame('perlu_dicek', DB::table('tanda_motoris')->find($tanda)->status);
    }

    public function test_akun_tanpa_hak_menu_ditolak(): void
    {
        $role = $this->role('Tanpa Tracking', 'semua', ['home' => ['lihat']]);
        $user = $this->akun('tanpa', 'koordinator', 137, ['role_id' => $role]);

        $this->actingAs($user)->getJson(self::URL.'?sales_id=1&tanggal='.$this->kemarin)->assertForbidden();
        $this->periksa($user, $this->tanda())->assertForbidden();
    }

    public function test_periksa_terbukti_menyimpan_hasil_dan_riwayat_admin_tanpa_mencabut_tanda(): void
    {
        $koor = $this->koordinator();
        $tanda = $this->tanda();

        $this->periksa($koor, $tanda)->assertOk()
            ->assertJsonPath('status', 'terbukti')->assertJsonPath('hasil_catatan', 'Sudah dicek ke lokasi.')->assertJsonPath('bisa_periksa', false);

        $baris = DB::table('tanda_motoris')->find($tanda);
        $this->assertSame(['terbukti', 'koor'], [$baris->status, $baris->dicek_nama]);
        $this->assertNull($baris->dicabut_at); // tetap berlencana Ditandai
        $this->assertSame(1, DB::table('riwayat_admin')->where('objek', 'tanda_motoris')->where('objek_id', $tanda)->where('aksi', 'ubah')->count());
    }

    public function test_periksa_wajar_mencabut_tanda_dan_tidak_dibuat_ulang_sinkronisasi(): void
    {
        $koor = $this->koordinator();
        $tanda = $this->tanda();

        $this->periksa($koor, $tanda, ['status' => 'wajar', 'catatan' => 'Ternyata HP tester.'])->assertOk();

        $baris = DB::table('tanda_motoris')->find($tanda);
        $this->assertSame('wajar', $baris->status);
        $this->assertNotNull($baris->dicabut_at);
        $this->assertSame(0, DB::table('tanda_motoris')->where('karyawan_id', 1)->whereNull('dicabut_at')->count());
    }

    public function test_validasi_periksa_dan_tanda_yang_sudah_diperiksa_atau_di_luar_cakupan(): void
    {
        $koor = $this->koordinator();
        $tanda = $this->tanda();

        $this->periksa($koor, $tanda, ['status' => 'curang'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->periksa($koor, $tanda, ['catatan' => ''])->assertUnprocessable()->assertJsonValidationErrors('catatan');
        $this->periksa($koor, $tanda, ['catatan' => str_repeat('a', 501)])->assertUnprocessable()->assertJsonValidationErrors('catatan');

        $this->periksa($koor, $tanda)->assertOk();
        $this->periksa($koor, $tanda)->assertNotFound(); // sudah diperiksa

        $luar = $this->tanda(2); // Sinta, di luar downline koordinator
        $this->periksa($koor, $luar)->assertNotFound();
        $this->assertSame('perlu_dicek', DB::table('tanda_motoris')->find($luar)->status);
        $this->periksa($koor, 99999)->assertNotFound();
    }

    public function test_koordinator_tidak_bisa_melihat_motoris_di_luar_cakupan_atau_bukan_sales(): void
    {
        $koor = $this->koordinator();

        $this->actingAs($koor)->getJson(self::URL.'?sales_id=2&tanggal='.$this->kemarin)->assertNotFound();
        $this->actingAs($koor)->getJson(self::URL.'?sales_id=137&tanggal='.$this->kemarin)->assertNotFound();
    }

    public function test_tanda_outlet_yang_didaftarkan_motoris_ikut_dalam_daftar_drawer(): void
    {
        $koor = $this->koordinator();

        // Outlet didaftarkan Rudi (nama di mk_outlet), outlet lain lewat HP (trx_outlet_baru), outlet milik orang lain, dan tanda dicabut.
        DB::table('mk_outlet')->insert([
            ['id' => 9001, 'nama' => 'Toko Rudi', 'dibuat_oleh' => 'Rudi Hartono', 'dibuat_pada' => '02 Mar 2026 09:23'],
            ['id' => 9002, 'nama' => 'Toko HP', 'dibuat_oleh' => 'Orang Lain', 'dibuat_pada' => null],
            ['id' => 9003, 'nama' => 'Toko Sinta', 'dibuat_oleh' => 'Sinta Wulandari', 'dibuat_pada' => null],
            ['id' => 9004, 'nama' => 'Toko Rudi Dicabut', 'dibuat_oleh' => 'Rudi Hartono', 'dibuat_pada' => null],
        ]);
        DB::table('trx_outlet_baru')->insert(['karyawan_id' => 1, 'outlet_id' => 9002, 'nama' => 'Toko HP', 'lat' => -7.4, 'lng' => 112.7, 'status' => 'disetujui', 'recorded_at' => '2026-10-01 01:15:00']);
        $tanda = fn (int $outlet, array $lain = []) => DB::table('tanda_outlet')->insert($lain + [
            'outlet_id' => $outlet, 'kategori' => 'outlet_fiktif', 'catatan' => 'Koordinat sama persis', 'status' => 'perlu_dicek',
            'dibuat_nama' => 'Sistem', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tanda(9001);
        $tanda(9002);
        $tanda(9003);
        $tanda(9004, ['dicabut_at' => now(), 'status' => 'wajar']);

        $res = $this->actingAs($koor)->getJson(self::URL.'?sales_id=1&tanggal='.$this->kemarin)->assertOk();
        $this->assertEqualsCanonicalizing(['Toko Rudi', 'Toko HP'], array_column($res->json('outlet'), 'outlet'));
        $res->assertJsonPath('outlet.0.oleh', 'Sistem');
        // Waktu daftar NOO: outlet lewat HP memakai recorded_at (UTC -> lokal), outlet Marketama memakai dibuat_pada apa adanya.
        $didaftarkan = collect($res->json('outlet'))->pluck('didaftarkan', 'outlet');
        $this->assertSame('02 Mar 2026 09:23', $didaftarkan['Toko Rudi']);
        $this->assertNotNull($didaftarkan['Toko HP']);
    }

    public function test_outlet_terdekat_menampilkan_outlet_bertanda_di_sekitar_terurut_jarak(): void
    {
        DB::table('mk_outlet')->insert([
            ['id' => 9101, 'nama' => 'Acuan', 'dibuat_oleh' => 'x', 'latitude' => -7.4000, 'longitude' => 112.7000],
            ['id' => 9102, 'nama' => 'Dekat', 'dibuat_oleh' => 'x', 'latitude' => -7.4020, 'longitude' => 112.7000],
            ['id' => 9103, 'nama' => 'Agak Jauh', 'dibuat_oleh' => 'x', 'latitude' => -7.4100, 'longitude' => 112.7000],
            ['id' => 9104, 'nama' => 'Terlalu Jauh', 'dibuat_oleh' => 'x', 'latitude' => -7.5000, 'longitude' => 112.7000],
            ['id' => 9105, 'nama' => 'Tanpa Tanda', 'dibuat_oleh' => 'x', 'latitude' => -7.4010, 'longitude' => 112.7000],
            ['id' => 9106, 'nama' => 'Tanda Dicabut', 'dibuat_oleh' => 'x', 'latitude' => -7.4015, 'longitude' => 112.7000],
        ]);
        $tanda = fn (int $outlet, array $lain = []) => DB::table('tanda_outlet')->insert($lain + [
            'outlet_id' => $outlet, 'kategori' => 'outlet_fiktif', 'status' => 'perlu_dicek', 'dibuat_nama' => 'Sistem', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tanda(9101); // acuan sendiri tidak ikut
        $tanda(9103);
        $tanda(9102);
        $tanda(9102);
        $tanda(9104);
        $tanda(9106, ['dicabut_at' => now(), 'status' => 'wajar']);

        $koor = $this->koordinator();
        $res = $this->actingAs($koor)->getJson(self::URL.'/outlet-terdekat?outlet_id=9101&radius_m=2000')->assertOk();

        $this->assertSame(['Dekat', 'Agak Jauh'], array_column($res->json('sekitar'), 'outlet'));
        $res->assertJsonPath('sekitar.0.jumlah_tanda', 2)->assertJsonPath('radius_m', 2000);
        $this->assertGreaterThan(150, $res->json('sekitar.0.jarak_m'));
        $this->assertLessThan(300, $res->json('sekitar.0.jarak_m'));

        // Radius lebih kecil menyaring yang lebih jauh; tanpa radius_m dipakai bawaan config (ambang jarak dekat).
        $this->actingAs($koor)->getJson(self::URL.'/outlet-terdekat?outlet_id=9101&radius_m=500')
            ->assertOk()->assertJsonCount(1, 'sekitar')->assertJsonPath('sekitar.0.outlet', 'Dekat')->assertJsonPath('radius_m', 500);
        $this->actingAs($koor)->getJson(self::URL.'/outlet-terdekat?outlet_id=9101')
            ->assertOk()->assertJsonCount(0, 'sekitar')->assertJsonPath('radius_m', config('marketama.deteksi_jarak_kunjungan.jarak_dekat_meter'));
        $this->actingAs($koor)->getJson(self::URL.'/outlet-terdekat?outlet_id=9101&radius_m=0')->assertUnprocessable();
        $this->actingAs($koor)->getJson(self::URL.'/outlet-terdekat?outlet_id=9101&radius_m=10001')->assertUnprocessable();
    }

    public function test_outlet_terdekat_mengirim_seluruh_outlet_satu_titik_tanpa_batas_dan_tidak_mengulangnya_di_sekitar(): void
    {
        $jumlah = TandaMotorisController::MAKS_SEKITAR + 5;
        $tanda = fn (int $outlet, ?string $kelompok) => [
            'outlet_id' => $outlet, 'kelompok' => $kelompok, 'kategori' => 'outlet_fiktif', 'status' => 'perlu_dicek', 'dibuat_nama' => 'Sistem', 'created_at' => now(), 'updated_at' => now(),
        ];

        foreach (range(1, $jumlah) as $i) {
            DB::table('mk_outlet')->insert(['id' => 9300 + $i, 'nama' => sprintf('Titik %02d', $i), 'dibuat_oleh' => 'x', 'latitude' => -7.4, 'longitude' => 112.7]);
            DB::table('tanda_outlet')->insert($tanda(9300 + $i, 'kelompok-a'));
        }
        DB::table('mk_outlet')->insert(['id' => 9400, 'nama' => 'Tetangga', 'dibuat_oleh' => 'y', 'latitude' => -7.401, 'longitude' => 112.7]);
        DB::table('tanda_outlet')->insert($tanda(9400, null));

        $koor = $this->koordinator();
        $res = $this->actingAs($koor)->getJson(self::URL.'/outlet-terdekat?outlet_id=9301&radius_m=2000')->assertOk();

        $res->assertJsonCount($jumlah - 1, 'titik')->assertJsonPath('titik.0.outlet', 'Titik 02');
        $this->assertNotContains(9301, array_column($res->json('titik'), 'outlet_id'));
        $this->assertSame(['Tetangga'], array_column($res->json('sekitar'), 'outlet'));

        // Outlet tanpa kelompok: titik kosong.
        $this->actingAs($koor)->getJson(self::URL.'/outlet-terdekat?outlet_id=9400&radius_m=10')->assertOk()->assertJsonCount(0, 'titik');
    }

    /** Satu transaksi (dengan item) di outlet oleh motoris; mengembalikan id penjualan. */
    private function transaksi(int $outlet, int $sales, string $kode, int $total, array $lain = []): int
    {
        $id = DB::table('trx_penjualan')->insertGetId($lain + [
            'kode' => $kode, 'kunjungan_id' => 1, 'karyawan_id' => $sales, 'outlet_id' => $outlet, 'tanggal' => $this->kemarin,
            'jumlah_sku' => 1, 'total_qty' => 2, 'total' => $total, 'is_effective_call' => true, 'recorded_at' => now()->subDay(),
        ]);
        DB::table('trx_penjualan_item')->insert(['penjualan_id' => $id, 'produk_id' => 1, 'qty' => 2, 'harga' => $total / 2, 'diskon' => 0, 'total' => $total]);

        return $id;
    }

    public function test_outlet_transaksi_menampilkan_transaksi_dan_item_dalam_cakupan_serta_ringkasan_di_outlet_terdekat(): void
    {
        DB::table('mk_outlet')->insert([
            ['id' => 9501, 'nama' => 'Titik A', 'dibuat_oleh' => 'x', 'dibuat_pada' => '02 Mar 2026 09:23', 'latitude' => -7.4, 'longitude' => 112.7],
            ['id' => 9502, 'nama' => 'Titik B', 'dibuat_oleh' => 'x', 'dibuat_pada' => null, 'latitude' => -7.4, 'longitude' => 112.7],
        ]);
        // Titik B didaftarkan dari HP: waktu HP (UTC -> lokal) didahulukan daripada teks Marketama.
        DB::table('trx_outlet_baru')->insert([
            'karyawan_id' => 1, 'nama' => 'Titik B', 'lat' => -7.4, 'lng' => 112.7, 'outlet_id' => 9502, 'recorded_at' => '2026-10-20 03:00:00',
        ]);
        foreach ([9501, 9502] as $o) {
            DB::table('tanda_outlet')->insert([
                'outlet_id' => $o, 'kelompok' => 'k1', 'kategori' => 'outlet_fiktif', 'status' => 'perlu_dicek', 'dibuat_nama' => 'Sistem', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->transaksi(9501, 1, 'T-1', 50000);
        $this->transaksi(9501, 1, 'T-2', 70000, ['tanggal' => now()->subDays(2)->toDateString()]);
        $this->transaksi(9501, 1, 'T-BATAL', 99000, ['dibatalkan_at' => now()]);
        $this->transaksi(9501, 2, 'T-LUAR', 10000); // Sinta bukan downline koordinator

        $koor = $this->koordinator();
        $res = $this->actingAs($koor)->getJson(self::URL.'/outlet-transaksi?outlet_id=9501')->assertOk();

        $this->assertSame(['T-1', 'T-2'], array_column($res->json('transaksi'), 'kode'));
        $res->assertJsonPath('jumlah', 2)->assertJsonPath('transaksi.0.motoris', 'Rudi Hartono')
            ->assertJsonPath('transaksi.0.total', 50000)->assertJsonPath('transaksi.0.item.0.qty', 2)->assertJsonCount(1, 'transaksi.0.item');

        // Ringkasan: acuan 9501 (2 transaksi, 2 hari, 120.000); outlet lain di titik tanpa transaksi = null.
        $res = $this->actingAs($koor)->getJson(self::URL.'/outlet-terdekat?outlet_id=9501')->assertOk();
        $res->assertJsonPath('ringkasan', ['transaksi' => 2, 'hari' => 2, 'total' => 120000])->assertJsonPath('titik.0.ringkasan', null)
            ->assertJsonPath('pendaftar', 'x')->assertJsonPath('titik.0.pendaftar', 'x')
            ->assertJsonPath('didaftarkan', '02 Mar 2026 09:23')->assertJsonPath('titik.0.didaftarkan', JamLokal::format('2026-10-20 03:00:00', 'Y-m-d H:i'));

        $this->actingAs($koor)->getJson(self::URL.'/outlet-transaksi?outlet_id=99999')->assertNotFound();
        $this->actingAs($koor)->getJson(self::URL.'/outlet-transaksi')->assertUnprocessable();
    }

    public function test_outlet_terdekat_kosong_bila_acuan_tanpa_koordinat_dan_404_bila_tidak_ada(): void
    {
        DB::table('mk_outlet')->insert(['id' => 9201, 'nama' => 'Tanpa Koordinat', 'dibuat_oleh' => 'x']);
        $koor = $this->koordinator();

        $this->actingAs($koor)->getJson(self::URL.'/outlet-terdekat?outlet_id=9201')->assertOk()->assertJsonCount(0, 'sekitar');
        $this->actingAs($koor)->getJson(self::URL.'/outlet-terdekat?outlet_id=99999')->assertNotFound();
        $this->actingAs($koor)->getJson(self::URL.'/outlet-terdekat')->assertUnprocessable();
    }

    public function test_hanya_pengguna_yang_login_yang_bisa_mengakses(): void
    {
        auth()->logout();

        $this->getJson(self::URL.'?sales_id=1&tanggal='.$this->kemarin)->assertUnauthorized();
    }

    public function test_migration_memberi_hak_tambah_ke_role_bawaan_koordinator_saja(): void
    {
        DB::table('role_hak')->where('menu', 'tracking_sales')->update(['tambah' => false]);
        $lain = $this->role('Pemantau Biasa', 'semua', ['tracking_sales' => ['lihat']]);
        $tanpaMenu = $this->role('Tanpa Menu Tracking', 'semua', ['home' => ['lihat']]);

        (require database_path('migrations/2026_11_01_000002_beri_hak_tanda_motoris_koordinator.php'))->up();

        $this->assertTrue((bool) DB::table('role_hak')->where('role_id', $this->roleId('koordinator'))->where('menu', 'tracking_sales')->value('tambah'));
        $this->assertFalse((bool) DB::table('role_hak')->where('role_id', $lain)->where('menu', 'tracking_sales')->value('tambah'));
        $this->assertFalse(DB::table('role_hak')->where('role_id', $tanpaMenu)->where('menu', 'tracking_sales')->exists());
        // Role bawaan lain (lainnya) tidak ikut berubah.
        $this->assertFalse((bool) DB::table('role_hak')->where('role_id', $this->roleId('lainnya'))->where('menu', 'tracking_sales')->value('tambah'));
    }
}
