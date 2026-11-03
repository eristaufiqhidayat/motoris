<?php

namespace Tests\Feature\Admin;

use App\Support\Tracking\AnomaliGps;
use App\Support\Tracking\TitikGps;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Tombol "Sinkronisasi Data" di Beranda Koordinator (spec 062): satu-satunya sumber tanda. Menambahkan tanda Perlu dicek
// untuk tim — diduga lokasi palsu (mock location HP, per motoris-tanggal) dan diduga outlet fiktif (titik berkoordinat
// identik dari pendaftar yang sama, bertransaksi, diurutkan skor dan dibatasi antrean). Hanya menambah.
// Tim koordinator 137 = Rudi (1) dan Tegar (3); Sinta (2) di luar tim.
class SinkronTemuanTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 3 Okt 2026 = hari ini; 1–2 Okt sudah lewat.
        $this->travelTo(now()->setTimezone('Asia/Jakarta')->setDate(2026, 10, 3)->setTime(12, 0));
    }

    private function kunjunganMock(int $karyawan, string $tanggal): void
    {
        DB::table('trx_kunjungan')->insert([
            'karyawan_id' => $karyawan, 'tanggal' => $tanggal, 'checkin_at' => "{$tanggal} 02:00:00", 'checkout_at' => "{$tanggal} 02:05:00",
            'lat' => -7.4, 'lng' => 112.7, 'mock_location' => true,
        ]);
    }

    /**
     * Titik outlet: $n outlet dari $pendaftar di koordinat sama; outlet pertama dikunjungi $karyawan, dan $transaksi outlet
     * pertama bertransaksi (satu transaksi, $total rupiah).
     *
     * @return array<int, int> id outlet
     */
    private function titik(string $pendaftar, float $lat, int $n, int $karyawan = 1, int $transaksi = 1, int $total = 50000): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $id = DB::table('mk_outlet')->insertGetId(['nama' => "Warung {$pendaftar} {$i}", 'dibuat_oleh' => $pendaftar, 'latitude' => $lat, 'longitude' => 112.7]);
            $ids[] = $id;

            if ($i === 0) {
                DB::table('trx_kunjungan')->insert(['karyawan_id' => $karyawan, 'tanggal' => '2026-10-02', 'outlet_id' => $id, 'checkin_at' => '2026-10-02 02:00:00']);
            }
            if ($i < $transaksi) {
                // Transaksi (outlet yang sama di $i = 0 sudah punya kunjungan di atas; selebihnya kunjungan sendiri yang tidak ke rute).
                $kunjungan = DB::table('trx_kunjungan')->insertGetId(['karyawan_id' => $karyawan, 'tanggal' => '2026-10-02', 'outlet_id' => $id, 'checkin_at' => '2026-10-02 03:00:00']);
                DB::table('trx_penjualan')->insert([
                    'kode' => "T{$id}", 'kunjungan_id' => $kunjungan, 'karyawan_id' => $karyawan, 'outlet_id' => $id, 'tanggal' => '2026-10-02', 'jumlah_sku' => 2,
                    'total_qty' => 2, 'total' => $total, 'poin' => 0, 'is_effective_call' => true,
                ]);
            }
        }

        return $ids;
    }

    private function sinkron(array $data = [])
    {
        return $this->post('/home/sinkronisasi-temuan', $data + ['dari' => '2026-10-01', 'sampai' => '2026-10-03']);
    }

    public function test_mock_location_menjadi_tanda_motoris_perlu_dicek_untuk_tim_saja(): void
    {
        $this->kunjunganMock(3, '2026-10-01'); // Tegar: mock location dari HP
        $this->kunjunganMock(2, '2026-10-02'); // Sinta, di luar tim — tidak diperiksa

        $this->actingAs($this->akun('koor_s1', 'koordinator', 137));
        $this->sinkron()->assertRedirect('/home?temuan_dari=2026-10-01&temuan_sampai=2026-10-03')
            ->assertSessionHas('sukses', fn ($pesan) => str_contains($pesan, 'Perlu dicek baru: 1 motoris'));

        $tanda = DB::table('tanda_motoris')->get();
        $this->assertSame([[3, '2026-10-01', 'lokasi_palsu', 'perlu_dicek']], $tanda->map(fn ($t) => [(int) $t->karyawan_id, substr($t->tanggal, 0, 10), $t->kategori, $t->status])->all());
        $this->assertNull($tanda[0]->dibuat_oleh);
        $this->assertSame('Sistem', $tanda[0]->dibuat_nama);
        $this->assertStringContainsString('mock location', $tanda[0]->catatan);
        $this->assertSame(1, DB::table('riwayat_admin')->where('objek', 'tanda_motoris')->where('keterangan', 'like', 'Sinkronisasi sistem:%')->count());
    }

    public function test_indikasi_gps_tanpa_mock_location_tidak_dijadikan_tanda(): void
    {
        // Lompatan bolak-balik Surabaya ↔ Jakarta tiap 10 menit (pola "indikasi"), tanpa penanda mock dari HP.
        foreach (range(0, 5) as $i) {
            DB::table('trx_tracking')->insert([
                'karyawan_id' => 1, 'recorded_at' => sprintf('2026-10-02 02:%02d:00', $i * 10),
                'lat' => $i % 2 ? -6.2 : -7.25, 'lng' => $i % 2 ? 106.8 : 112.75, 'akurasi_m' => 5,
            ]);
        }
        $this->assertSame('indikasi', AnomaliGps::analisis(TitikGps::untuk(1, '2026-10-02'))['tingkat'] ?? null);
        $this->actingAs($this->akun('koor_s2', 'koordinator', 137));

        $this->sinkron();

        $this->assertSame(0, DB::table('tanda_motoris')->count());
    }

    public function test_kunjungan_berdekatan_dan_durasi_tidak_dijadikan_tanda(): void
    {
        // 4 kunjungan berturutan di titik hampir sama, durasi singkat, tanpa mock location.
        foreach (range(0, 3) as $i) {
            DB::table('trx_kunjungan')->insert([
                'karyawan_id' => 1, 'tanggal' => '2026-10-02', 'checkin_at' => sprintf('2026-10-02 02:%02d:00', $i * 10),
                'checkout_at' => sprintf('2026-10-02 02:%02d:30', $i * 10), 'lat' => -7.4 + $i * 0.00001, 'lng' => 112.7,
            ]);
        }
        $this->actingAs($this->akun('koor_s3', 'koordinator', 137));

        $this->sinkron();

        $this->assertSame(0, DB::table('tanda_motoris')->count());
        $this->assertSame(0, DB::table('tanda_outlet')->count());
    }

    public function test_titik_outlet_identik_yang_bertransaksi_dan_di_rute_tim_menjadi_tanda_satu_kelompok(): void
    {
        $titik = $this->titik('Wawan08', -7.4, 4); // dikunjungi Rudi (tim), 1 bertransaksi
        $this->titik('Orang Lain', -7.5, 4, 2); // dikunjungi Sinta (di luar tim)
        $this->titik('Wawan08', -7.6, 2); // hanya 2 outlet: di bawah min_outlet
        $this->titik('Tanpa Jual', -7.7, 3, 1, 0); // tidak ada transaksi

        $this->actingAs($this->akun('koor_s4', 'koordinator', 137));
        $this->sinkron()->assertSessionHas('sukses', fn ($p) => str_contains($p, '1 titik outlet (4 outlet)'));

        $tanda = DB::table('tanda_outlet')->orderBy('outlet_id')->get();
        $this->assertSame($titik, $tanda->pluck('outlet_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(1, $tanda->pluck('kelompok')->unique()->count());
        $this->assertSame(['outlet_fiktif', 'perlu_dicek', 'Sistem'], [$tanda[0]->kategori, $tanda[0]->status, $tanda[0]->dibuat_nama]);
        // skor = 3 × 1 bertransaksi + 2 × 0 transaksi kecil (Rp50.000, 2 SKU) + 4 outlet
        $this->assertSame(7, (int) $tanda[0]->skor);
        $this->assertStringContainsString('4 outlet didaftarkan Wawan08', $tanda[0]->catatan);
        $this->assertSame(4, DB::table('riwayat_admin')->where('objek', 'tanda_outlet')->count());
    }

    public function test_transaksi_kecil_sekali_menaikkan_skor_dan_antrean_dibatasi_urut_skor(): void
    {
        config(['marketama.tanda_outlet_sistem.antrean_titik' => 2]);
        $this->titik('A', -7.41, 3, 1, 1, 50000); // skor 3 + 3 = 6
        $kecil = $this->titik('B', -7.42, 3, 1, 3, 10000); // 3 transaksi kecil sekali: 9 + 6 + 3 = 18
        $this->titik('C', -7.43, 5, 1, 2, 50000); // 6 + 5 = 11
        $this->actingAs($this->akun('koor_s5', 'koordinator', 137));

        $this->sinkron();

        // Hanya 2 titik teratas (B = 18, C = 11); A (6) menunggu antrean berkurang.
        $this->assertSame(2, DB::table('tanda_outlet')->distinct()->count('kelompok'));
        $this->assertSame(0, DB::table('tanda_outlet')->join('mk_outlet', 'mk_outlet.id', '=', 'tanda_outlet.outlet_id')->where('mk_outlet.dibuat_oleh', 'A')->count());
        $this->assertSame(18, (int) DB::table('tanda_outlet')->where('outlet_id', $kecil[0])->value('skor'));

        // Sinkron ulang tidak menambah selama antrean penuh.
        $this->sinkron();
        $this->assertSame(2, DB::table('tanda_outlet')->distinct()->count('kelompok'));

        // Setelah titik B diperiksa, antrean punya slot lagi dan A masuk.
        $this->patchJson('/outlet_all/tanda/'.DB::table('tanda_outlet')->where('outlet_id', $kecil[0])->value('id').'/periksa', ['status' => 'wajar', 'catatan' => 'Kios berderet'])->assertOk();
        $this->sinkron();
        $this->assertSame(3, DB::table('tanda_outlet')->distinct()->count('kelompok'));
    }

    public function test_hanya_menambah_tanda_yang_sudah_diperiksa_wajar_tidak_dibuat_ulang(): void
    {
        $this->kunjunganMock(3, '2026-10-01');
        $titik = $this->titik('Wawan08', -7.4, 3);
        $this->actingAs($this->akun('koor_s6', 'koordinator', 137));

        $this->sinkron();
        $this->assertSame(1, DB::table('tanda_motoris')->count());
        $this->assertSame(3, DB::table('tanda_outlet')->count());

        $motoris = DB::table('tanda_motoris')->value('id');
        $this->patchJson("/tracking_sales/tanda/{$motoris}/periksa", ['status' => 'wajar', 'catatan' => 'HP tester'])->assertOk();
        $this->patchJson('/outlet_all/tanda/'.DB::table('tanda_outlet')->where('outlet_id', $titik[0])->value('id').'/periksa', ['status' => 'wajar', 'catatan' => 'Asli'])->assertOk();

        // Hari 1 Okt sudah "lengkap" (dilewati) dan titik outlet sudah pernah ditandai: tidak ada yang bertambah.
        $this->sinkron();
        $this->assertSame(1, DB::table('tanda_motoris')->count());
        $this->assertSame(3, DB::table('tanda_outlet')->count());
    }

    public function test_hari_yang_sudah_lengkap_diperiksa_dilewati_hari_ini_diperiksa_ulang(): void
    {
        $this->actingAs($this->akun('koor_s7', 'koordinator', 137));

        $this->sinkron()->assertSessionHas('sukses', fn ($p) => str_contains($p, '6 hari motoris diperiksa.'));
        $this->assertSame(4, DB::table('pemeriksaan_motoris')->where('lengkap', true)->count()); // 1–2 Okt × 2 motoris

        // Data baru di tanggal yang sudah lengkap tidak diperiksa lagi; hari ini tetap diperiksa.
        $this->kunjunganMock(1, '2026-10-02');
        $this->kunjunganMock(3, '2026-10-03');

        $this->sinkron()->assertSessionHas('sukses', fn ($p) => str_contains($p, '2 hari motoris diperiksa, 4 dilewati'));
        $this->assertSame([[3, '2026-10-03']], DB::table('tanda_motoris')->get()->map(fn ($t) => [(int) $t->karyawan_id, substr($t->tanggal, 0, 10)])->all());
    }

    public function test_beranda_menampilkan_antrean_motoris_dan_titik_tim_beserta_cakupan_pemeriksaan(): void
    {
        $this->kunjunganMock(3, '2026-10-01');
        $this->titik('Wawan08', -7.4, 3);
        $this->actingAs($this->akun('koor_s8', 'koordinator', 137));
        $this->sinkron();
        // Tanda yang sudah diperiksa dan tanda motoris di luar tim tidak masuk antrean.
        DB::table('tanda_motoris')->insert([
            ['karyawan_id' => 1, 'tanggal' => '2026-10-02', 'kategori' => 'lokasi_palsu', 'status' => 'terbukti', 'dibuat_nama' => 'Sistem', 'created_at' => now(), 'updated_at' => now()],
            ['karyawan_id' => 2, 'tanggal' => '2026-10-02', 'kategori' => 'lokasi_palsu', 'status' => 'perlu_dicek', 'dibuat_nama' => 'Sistem', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->get('/home?temuan_dari=2026-10-01&temuan_sampai=2026-10-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home/DashboardKoordinator')
                ->where('temuanFilter.maks_hari', 7)
                ->has('temuan.motoris', 1)
                ->where('temuan.motoris.0.motoris', 'Tegar Mahendra')
                ->where('temuan.motoris.0.kategori_label', 'Diduga lokasi / GPS palsu')
                ->has('temuan.titik', 1)
                ->where('temuan.titik.0.jumlah', 3)
                ->where('temuan.titik.0.pendaftar', 'Wawan08')
                ->where('temuan.titik.0.acuan.latitude', -7.4)
                ->where('temuan.antrean_titik', 10)
                ->where('temuan.pemeriksaan.diperiksa', 6)
                ->where('temuan.pemeriksaan.total', 6)
            );
    }

    public function test_kartu_ditandai_hanya_menghitung_perlu_dicek_dan_langsung_diperbarui(): void
    {
        $this->kunjunganMock(3, '2026-10-01');
        $this->titik('Wawan08', -7.4, 3);
        $koor = $this->akun('koor_s9', 'koordinator', 137);
        $this->actingAs($koor);

        $ambil = fn () => $this->get('/home')->assertInertia(function (Assert $page) use (&$props) {
            $page->loadDeferredProps(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });
        }) && $props;

        $this->sinkron();
        $this->get('/home')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $page) => $page
            ->where('tandaMotoris.motoris', 1)->where('tandaMotoris.total', 1)
            ->where('tandaOutlet.outlet', 3)));

        // Setelah diperiksa Wajar, kartu langsung turun (cache dibuang).
        $motoris = DB::table('tanda_motoris')->value('id');
        $outlet = DB::table('tanda_outlet')->value('id');
        $this->patchJson("/tracking_sales/tanda/{$motoris}/periksa", ['status' => 'wajar', 'catatan' => 'x'])->assertOk();
        $this->patchJson("/outlet_all/tanda/{$outlet}/periksa", ['status' => 'terbukti', 'catatan' => 'y'])->assertOk();

        $this->get('/home')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $page) => $page
            ->where('tandaMotoris.motoris', 0)->where('tandaOutlet.outlet', 0)));
    }

    public function test_validasi_rentang_dan_cakupan(): void
    {
        $this->actingAs($this->akun('koor_s10', 'koordinator', 137));

        $this->sinkron(['sampai' => '2026-10-04'])->assertSessionHasErrors('sampai'); // melewati hari ini
        $this->sinkron(['dari' => '2026-09-26', 'sampai' => '2026-10-03'])->assertSessionHasErrors('dari'); // 8 hari > 7
        $this->assertSame(0, DB::table('pemeriksaan_motoris')->count());

        // Cakupan "semua" (Super Admin dkk.) akan memeriksa seluruh motoris nasional: ditolak.
        $this->actingAs($this->akun('admin_s10', 'lainnya', 500, ['role_id' => $this->role('Admin Semua', 'semua', ['home' => ['lihat']])]));
        $this->sinkron()->assertForbidden();
    }
}
