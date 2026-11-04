<?php

namespace Tests\Feature\Admin;

use App\Support\Mobile\Pengaturan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Beranda (home): dashboard interaktif ringkasan nasional data motoris. KPI nasional memakai rumus yang sama
// dengan Summary All Depo (lihat SummaryDepoTest) — fixture berikut sama persis, supaya totalnya bisa
// diverifikasi dari angka yang sudah dibuktikan di sana (kartu Sidoarjo + kartu Balaraja).
class HomeTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 1–3 Okt 2026 = Kamis–Sabtu.
        $this->travelTo(now()->setTimezone('Asia/Jakarta')->setDate(2026, 10, 3)->setTime(12, 0));

        DB::table('trx_absensi')->insert([
            ['karyawan_id' => 1, 'depo_id' => 46, 'tanggal' => '2026-10-01'],
            ['karyawan_id' => 1, 'depo_id' => 46, 'tanggal' => '2026-10-02'],
            ['karyawan_id' => 3, 'depo_id' => 46, 'tanggal' => '2026-10-01'],
        ]);

        $kunjungan = fn (int $karyawan, string $tgl) => DB::table('trx_kunjungan')->insertGetId([
            'karyawan_id' => $karyawan, 'tanggal' => $tgl, 'checkin_at' => "{$tgl} 02:00:00",
        ]);
        $k = [$kunjungan(1, '2026-10-02'), $kunjungan(1, '2026-10-03'), $kunjungan(3, '2026-10-01'), $kunjungan(3, '2026-10-01')];

        $jual = fn (string $kode, int $kunj, int $karyawan, string $tgl, int $sku, int $total, int $poin, bool $ec, ?string $batal = null) => [
            'kode' => $kode, 'kunjungan_id' => $kunj, 'karyawan_id' => $karyawan, 'tanggal' => $tgl, 'jumlah_sku' => $sku,
            'total_qty' => $sku, 'total' => $total, 'poin' => $poin, 'is_effective_call' => $ec, 'dibatalkan_at' => $batal,
        ];
        // Tanpa outlet_id (nullable): funnel outlet (NOO/AC/EC/RO/OSS) semuanya tetap 0 pada fixture ini,
        // karena definisi funnel hanya menghitung outlet yang tercatat lewat trx_kunjungan/trx_penjualan.outlet_id.
        DB::table('trx_penjualan')->insert([
            $jual('S1', $k[0], 1, '2026-10-02', 3, 100000, 10, true),
            $jual('S2', $k[1], 1, '2026-10-03', 1, 20000, 2, false),
            $jual('S3', $k[2], 3, '2026-10-01', 2, 50000, 5, true),
            $jual('S4', $k[3], 3, '2026-10-01', 9, 900000, 90, true, '2026-10-01 05:00:00'), // dibatalkan, tidak ikut dihitung
        ]);
    }

    public function test_beranda_dirender_dengan_filter_bawaan_7_hari_dan_ringkasan_ditunda(): void
    {
        $this->get('/home')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home/Dashboard')
                ->where('filter.date_from', now()->subDays(6)->toDateString())
                ->where('filter.date_to', now()->toDateString())
                // Berat (ringkasanDepo/funnel/tren); belum ada di payload awal, lihat <Deferred> di Home/Dashboard.vue.
                ->missing('ringkasanDepo')->missing('funnel')->missing('tren')
            );
    }

    public function test_kpi_nasional_funnel_dan_tren_dihitung_dari_tabel_motoris(): void
    {
        $this->get('/home?date_from=2026-10-01&date_to=2026-10-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home/Dashboard')
                ->loadDeferredProps(fn (Assert $page) => $page
                    // Sidoarjo (Rudi: hari 3, call 2, ec 1, sales 120000, sku 4; Tegar: hari 1 — 2 kunjungan tanggal
                    // sama digabung UNION, call 2, ec 1, sales 50000, sku 2) + Balaraja (semua 0, Sinta tanpa transaksi).
                    ->where('ringkasanDepo.kpi.depo_aktif', 2)
                    ->where('ringkasanDepo.kpi.motoris_aktif', 3) // Rudi + Tegar (Sidoarjo) + Sinta (Balaraja, aktif tanpa transaksi)
                    ->where('ringkasanDepo.kpi.hari_kerja', 4)
                    ->where('ringkasanDepo.kpi.call', 4)
                    ->where('ringkasanDepo.kpi.ec', 2)
                    ->where('ringkasanDepo.kpi.sales', 170000)
                    ->where('ringkasanDepo.kpi.sku', 6)
                    ->where('ringkasanDepo.kpi.avg_call', 1)
                    ->where('ringkasanDepo.kpi.avg_ec', 0.5)
                    ->where('ringkasanDepo.kpi.avg_ipt', 3)
                    ->where('ringkasanDepo.kpi.ec_achievement', 1.4) // 0.5 × 100 ÷ 35
                    ->where('ringkasanDepo.kpi.ipt_achievement', 100) // 3.0 × 100 ÷ 3
                    ->has('ringkasanDepo.topDepo', 2)
                    // Tidak ada data mk_outlet/trx_outlet_baru/trx_stok_outlet pada fixture ini.
                    ->where('funnel', fn ($funnel) => collect($funnel)->pluck('jumlah')->every(fn ($n) => $n === 0))
                    ->has('tren', 3)
                    ->where('tren.0', ['tanggal' => '2026-10-01', 'sales' => 50000, 'ec' => 1, 'noo' => 0]) // S3 (S4 dibatalkan)
                    ->where('tren.1', ['tanggal' => '2026-10-02', 'sales' => 100000, 'ec' => 1, 'noo' => 0]) // S1
                    ->where('tren.2', ['tanggal' => '2026-10-03', 'sales' => 20000, 'ec' => 0, 'noo' => 0]) // S2, bukan EC
                )
            );
    }

    public function test_kinerja_per_depo_memuat_semua_depo_dengan_rentang_bawaan_30_hari(): void
    {
        $hariIni = now(Pengaturan::zona())->toDateString();

        $this->get('/home')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kinerjaFilter', ['dari' => now(Pengaturan::zona())->subDays(29)->toDateString(), 'sampai' => $hariIni])
                // Rentang KPI halaman tetap 7 hari.
                ->where('filter.date_from', now(Pengaturan::zona())->subDays(6)->toDateString())
                ->loadDeferredProps(fn (Assert $page) => $page->has('kinerja', 2)));
    }

    public function test_kinerja_per_depo_sama_dengan_kartu_summary_depo_dan_menghormati_rentang(): void
    {
        $this->get('/home?kinerja_dari=2026-10-01&kinerja_sampai=2026-10-03')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kinerjaFilter', ['dari' => '2026-10-01', 'sampai' => '2026-10-03'])
                ->loadDeferredProps(fn (Assert $page) => $page
                    ->has('kinerja', 2)
                    // Sidoarjo (urutan pertama: EC Achievement tertinggi): hari kerja 4, call 4, EC 2, SKU 6.
                    ->where('kinerja.0.nama', fn ($n) => is_string($n))
                    ->where('kinerja.0.avg_call', 1)
                    ->where('kinerja.0.avg_ec', 0.5)
                    ->where('kinerja.0.avg_ipt', 3)
                    ->where('kinerja.0.ipt_achievement', 100)
                    ->etc()));
    }

    public function test_rentang_kinerja_dibatasi_62_hari(): void
    {
        $this->get('/home?kinerja_dari=2025-01-01&kinerja_sampai=2026-10-03')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('kinerjaFilter', ['dari' => '2026-08-03', 'sampai' => '2026-10-03']));
    }

    public function test_kunjungan_kedua_lewat_cache_tidak_korup(): void
    {
        // funnel/tren/ringkasanDepo di-cache 5 menit (lihat Dashboard::CACHE_TTL). Kunjungan pertama menghitung
        // dan menyimpan ke cache; kunjungan kedua membaca baliknya lewat unserialize() — ini yang dulu gagal
        // ("Return value must be of type Collection, __PHP_Incomplete_Class returned") karena Collection/stdClass
        // di dalam nilai yang di-cache tidak aman di-unserialize di sebagian driver cache. Sekarang semuanya
        // array murni sebelum di-cache (lihat Dashboard::trenHarian & HomeController::ringkasanDepo).
        $ambil = function (): array {
            $props = null;
            // "use (&$props)" di fn() arrow luar tidak berlaku sampai ke sini (arrow function menangkap by
            // value), jadi dua lapis closure biasa supaya referensinya sama-sama sampai ke $props di atas.
            $this->get('/home?date_from=2026-10-01&date_to=2026-10-03')->assertOk()
                ->assertInertia(function (Assert $page) use (&$props) {
                    $page->loadDeferredProps(function (Assert $page) use (&$props) {
                        $props = $page->toArray()['props'];
                    });
                });

            return $props;
        };

        $pertama = $ambil();
        $kedua = $ambil();

        $this->assertSame($pertama['tren'], $kedua['tren']);
        $this->assertSame($pertama['funnel'], $kedua['funnel']);
        $this->assertSame($pertama['ringkasanDepo'], $kedua['ringkasanDepo']);
    }

    public function test_akun_tanpa_hak_home_ditolak(): void
    {
        $staf = $this->akun('gudang', 'lainnya', 500, ['role_id' => $this->role('Gudang', 'semua', ['summary_all_depo' => ['lihat']])]);
        $this->actingAs($staf);

        $this->get('/home')->assertForbidden();
    }

    public function test_beranda_koordinator_menampilkan_tim_sendiri_7_hari_terakhir_bukan_ranking_depo(): void
    {
        // Karyawan 137 = Koor Satu (depo Sidoarjo/46), membawahi Rudi (1) dan Tegar (3). Tim Saya bawaannya
        // 7 hari terakhir (sama dengan Tren Harian), bukan "hari ini" — berbeda dari absenHariIni/checkinOutlet
        // yang selalu hari ini. Rentang bawaan (27 Sep–3 Okt) mencakup semua fixture di setUp (1–3 Okt), jadi
        // totalnya sama dengan kartu Sidoarjo di test_kpi_nasional_funnel_dan_tren_... (Home/Dashboard Super Admin).
        $koordinator = $this->akun('koor1', 'koordinator', 137);
        $this->actingAs($koordinator);

        $this->get('/home')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home/DashboardKoordinator')
                ->where('depo.nama', 'Sidoarjo')
                ->where('timFilter', ['dari' => now()->subDays(6)->toDateString(), 'sampai' => now()->toDateString()])
                ->where('trenFilter', ['dari' => now()->subDays(6)->toDateString(), 'sampai' => now()->toDateString()])
                ->missing('detail')->missing('funnel')->missing('tren')->missing('tandaMotoris')->missing('tandaOutlet')
                ->loadDeferredProps(fn (Assert $page) => $page
                    ->where('detail.total.hari_kerja', 4)
                    ->where('detail.total.call', 4)
                    ->where('detail.total.ec', 2)
                    ->where('detail.total.sales', 170000)
                    ->where('detail.total.sku', 6)
                    ->has('detail.tim', 1)
                    ->where('detail.tim.0.koordinator_id', 137)
                    ->has('detail.tim.0.sales', 2) // Rudi, Tegar — Joko (non-aktif) tidak ikut, tidak ada data di rentang ini
                )
            );
    }

    public function test_tim_saya_bisa_difilter_rentang_tanggal_sendiri_lepas_dari_tren_harian(): void
    {
        // Tim Saya dan Tren Harian punya rentang masing-masing (tim_dari/tim_sampai vs tren_dari/tren_sampai)
        // — mengubah satu tidak ikut mengubah yang lain.
        $koordinator = $this->akun('koor18', 'koordinator', 137);
        $this->actingAs($koordinator);

        $this->get('/home?tim_dari=2026-10-01&tim_sampai=2026-10-01')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home/DashboardKoordinator')
                ->where('timFilter', ['dari' => '2026-10-01', 'sampai' => '2026-10-01'])
                // Tren Harian tetap bawaan 7 hari terakhir, tidak ikut tim_dari/tim_sampai.
                ->where('trenFilter', ['dari' => now()->subDays(6)->toDateString(), 'sampai' => now()->toDateString()])
                ->loadDeferredProps(fn (Assert $page) => $page
                    // Hanya 1 Okt: Rudi (1) cuma absen (hari_kerja 1, tanpa kunjungan/penjualan di tanggal ini) +
                    // Tegar (3) absen & 2 kunjungan tanggal sama (hari_kerja 1, call 2) & penjualan S3 (S4 dibatalkan).
                    ->where('detail.total.hari_kerja', 2)
                    ->where('detail.total.call', 2)
                    ->where('detail.total.ec', 1)
                    ->where('detail.total.sales', 50000)
                    ->where('detail.total.sku', 2)
                )
            );
    }

    public function test_motoris_ditandai_dibatasi_cakupan_tim_dan_tidak_terikat_tanggal(): void
    {
        // Tanda motoris, seperti tanda outlet, tidak difilter tanggal sama sekali — tanda lama yang masih Perlu dicek
        // tetap dihitung, bukan cuma tanda hari ini. Yang sudah diperiksa (Terbukti / Wajar) tidak dihitung.
        $tanda = fn (int $karyawan, string $tgl, string $status = 'perlu_dicek') => DB::table('tanda_motoris')->insert([
            'karyawan_id' => $karyawan, 'tanggal' => $tgl, 'kategori' => 'lokasi_palsu', 'status' => $status,
            'dibuat_oleh' => null, 'dibuat_nama' => 'Sistem', 'dicabut_at' => $status === 'wajar' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tanda(1, '2026-10-02'); // Rudi, Perlu dicek, bukan hari ini — tetap dihitung
        $tanda(1, '2026-10-03'); // Rudi, Perlu dicek, hari ini — tanda kedua, motoris yang sama
        $tanda(3, '2026-10-01', 'wajar'); // Tegar, sudah diperiksa Wajar — tidak dihitung
        $tanda(3, '2026-10-02', 'terbukti'); // Tegar, sudah diperiksa Terbukti — tidak lagi perlu dicek
        $tanda(2, '2026-10-02'); // Sinta, di luar tim koordinator 137 — tidak dihitung

        $koordinator = $this->akun('koor4', 'koordinator', 137);
        $this->actingAs($koordinator);

        $this->get('/home')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home/DashboardKoordinator')
                ->loadDeferredProps(fn (Assert $page) => $page
                    ->where('tandaMotoris.motoris', 1) // hanya Rudi
                    ->where('tandaMotoris.total', 2) // dua tanda aktif untuk Rudi, beda tanggal
                )
            );
    }

    public function test_outlet_ditandai_dibatasi_pendaftar_outlet_sama_dengan_kolom_koordinator_di_rekap(): void
    {
        // Cakupannya harus persis sama dengan kolom "Koordinator" di menu Rekap Outlet Ditandai
        // (App\Support\Menu\SumberTandaOutlet): dicocokkan lewat motoris pendaftar outlet (trx_outlet_baru,
        // atau mk_outlet.dibuat_oleh sebagai cadangan untuk data lama), bukan lewat depo outlet.
        DB::table('mk_outlet')->insert([
            ['id' => 70, 'nama' => 'Toko Makmur', 'dibuat_oleh' => null], // didaftarkan Rudi (1) lewat HP — anggota tim koordinator 137
            ['id' => 71, 'nama' => 'Warung Bu Sri', 'dibuat_oleh' => 'Tegar Mahendra'], // didaftarkan manual (data lama), nama cocok Tegar (3) — anggota tim juga
            ['id' => 72, 'nama' => 'Toko Luar', 'dibuat_oleh' => null], // didaftarkan Sinta (2) — bukan anggota tim koordinator 137
        ]);
        DB::table('trx_outlet_baru')->insert([
            ['karyawan_id' => 1, 'outlet_id' => 70, 'nama' => 'Toko Makmur', 'lat' => -7.4, 'lng' => 112.7, 'status' => 'disetujui'],
            ['karyawan_id' => 2, 'outlet_id' => 72, 'nama' => 'Toko Luar', 'lat' => -7.4, 'lng' => 112.7, 'status' => 'disetujui'],
        ]);
        $tanda = fn (int $outlet, string $status = 'perlu_dicek') => DB::table('tanda_outlet')->insert([
            'outlet_id' => $outlet, 'kategori' => 'outlet_fiktif', 'status' => $status, 'dibuat_oleh' => null, 'dibuat_nama' => 'Sistem',
            'dicabut_at' => $status === 'wajar' ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tanda(70); // Perlu dicek
        $tanda(70); // Perlu dicek — tanda kedua, outlet yang sama
        $tanda(71, 'wajar'); // sudah diperiksa Wajar, tidak dihitung walau anggota tim
        $tanda(70, 'terbukti'); // sudah diperiksa Terbukti, tidak lagi perlu dicek
        $tanda(72); // didaftarkan di luar tim koordinator 137, tidak dihitung walau Perlu dicek

        $koordinator = $this->akun('koor5', 'koordinator', 137);
        $this->actingAs($koordinator);

        $this->get('/home')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home/DashboardKoordinator')
                ->missing('tandaOutlet')
                ->loadDeferredProps(fn (Assert $page) => $page
                    ->where('tandaOutlet.outlet', 1) // hanya Toko Makmur
                    ->where('tandaOutlet.total', 2) // dua tanda aktif untuk Toko Makmur
                )
            );
    }

    public function test_absen_hari_ini_dihitung_dari_tim_sendiri_bukan_rentang_filter(): void
    {
        // "Hari ini" = tanggal saat test berjalan (2026-10-03, lihat travelTo di setUp) — Rudi sudah absen
        // datang hari ini, Tegar belum.
        DB::table('trx_absensi')->insert(['karyawan_id' => 1, 'depo_id' => 46, 'tanggal' => '2026-10-03', 'masuk_at' => '2026-10-03 06:00:00']);

        $koordinator = $this->akun('koor3', 'koordinator', 137);
        $this->actingAs($koordinator);

        $this->get('/home')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home/DashboardKoordinator')
                ->where('absenHariIni.tanggal', '2026-10-03')
                ->where('absenHariIni.total', 2) // Rudi + Tegar, aktif; Joko (non-aktif) tidak ikut
                ->where('absenHariIni.hadir', 1) // Rudi sudah, Tegar belum
            );
    }

    public function test_checkin_outlet_hari_ini_sudah_dan_belum_dengan_batas_jam_11_waktu_setempat(): void
    {
        // Fixture setUp: pada 2026-10-03 hanya Rudi (1) yang punya kunjungan; Tegar (3) belum. Waktu test 12:00 WIB,
        // sudah lewat batas 11:00 → "belum" berarti tidak akan checkin lagi hari ini.
        $this->actingAs($this->akun('koor7', 'koordinator', 137));

        $this->get('/home')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home/DashboardKoordinator')
            ->where('checkinOutlet', ['sudah' => 1, 'belum' => 1, 'total' => 2, 'batas' => '11:00', 'lewat' => true])
        );

        // Jam 09:00 WIB: hitungan sama, tapi batas belum lewat (Tegar masih bisa checkin).
        $this->travelTo(now()->setTimezone('Asia/Jakarta')->setDate(2026, 10, 3)->setTime(9, 0));

        $this->get('/home')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('checkinOutlet', ['sudah' => 1, 'belum' => 1, 'total' => 2, 'batas' => '11:00', 'lewat' => false])
        );
    }

    public function test_tren_harian_noo_dihitung_per_tanggal_recorded_at_dan_dibatasi_pendaftar(): void
    {
        // Dibatasi lewat karyawan_id pendaftar (Dashboard::outletBaru), BUKAN lewat depo_id (kolomnya
        // sengaja dikosongkan di sini) — sama dengan bug yang sudah diperbaiki untuk Outlet Ditandai: kolom
        // depo_id tidak selalu terisi, jadi NOO bisa salah tampil 0 kalau dibatasi lewat kolom itu.
        DB::table('trx_outlet_baru')->insert([
            ['karyawan_id' => 1, 'nama' => 'Toko A', 'lat' => -7.4, 'lng' => 112.7, 'recorded_at' => '2026-10-01 03:00:00'], // Rudi, tim koordinator 137
            ['karyawan_id' => 1, 'nama' => 'Toko B', 'lat' => -7.4, 'lng' => 112.7, 'recorded_at' => '2026-10-01 05:00:00'], // Rudi, tanggal sama
            ['karyawan_id' => 2, 'nama' => 'Toko C', 'lat' => -7.4, 'lng' => 112.7, 'recorded_at' => '2026-10-02 03:00:00'], // Sinta, bukan tim koordinator 137
        ]);

        $koordinator = $this->akun('koor10', 'koordinator', 137);
        $this->actingAs($koordinator);

        $this->get('/home?tren_dari=2026-10-01&tren_sampai=2026-10-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home/DashboardKoordinator')
                ->loadDeferredProps(fn (Assert $page) => $page
                    ->where('tren.0.noo', 2) // Toko A + Toko B, didaftarkan Rudi (tim koordinator 137)
                    ->where('tren.1.noo', 0) // Toko C didaftarkan Sinta, di luar tim
                    ->where('tren.2.noo', 0)
                )
            );
    }

    public function test_tren_harian_koordinator_bisa_difilter_rentang_tanggal_sendiri(): void
    {
        // Tren Harian satu-satunya bagian Beranda Koordinator yang bisa difilter tanggal — beda dari
        // detail/absenHariIni/checkinOutlet/tandaMotoris/tandaOutlet yang selalu hari ini/tidak terikat tanggal.
        $koordinator = $this->akun('koor8', 'koordinator', 137);
        $this->actingAs($koordinator);

        $this->get('/home?tren_dari=2026-10-01&tren_sampai=2026-10-02')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home/DashboardKoordinator')
                ->where('trenFilter', ['dari' => '2026-10-01', 'sampai' => '2026-10-02'])
                // absenHariIni tetap hari ini (2026-10-03), tidak ikut tren_dari/tren_sampai.
                ->where('absenHariIni.tanggal', '2026-10-03')
                ->loadDeferredProps(fn (Assert $page) => $page
                    ->has('tren', 2)
                    ->where('tren.0', ['tanggal' => '2026-10-01', 'sales' => 50000, 'ec' => 1, 'noo' => 0]) // S3 (S4 dibatalkan)
                    ->where('tren.1', ['tanggal' => '2026-10-02', 'sales' => 100000, 'ec' => 1, 'noo' => 0]) // S1
                )
            );
    }

    public function test_tren_harian_koordinator_tanggal_terbalik_ditukar(): void
    {
        $koordinator = $this->akun('koor9', 'koordinator', 137);
        $this->actingAs($koordinator);

        $this->get('/home?tren_dari=2026-10-02&tren_sampai=2026-10-01')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trenFilter', ['dari' => '2026-10-01', 'sampai' => '2026-10-02'])
            );
    }

    public function test_kartu_ringkas_hanya_bertautan_ke_menu_yang_boleh_dibuka(): void
    {
        $this->actingAs($this->akun('koor11', 'koordinator', 137));

        $this->get('/home')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tautan', ['motoris_ditandai' => '/motoris_ditandai', 'outlet_ditandai' => '/outlet_ditandai'])
        );

        // Role downline yang hanya boleh membuka Beranda: kartu tetap tampil, tapi tidak bisa diklik.
        $terbatas = $this->akun('koor12', 'koordinator', 137, ['role_id' => $this->role('Koor Terbatas', 'downline', ['home' => ['lihat']])]);
        $this->actingAs($terbatas);

        $this->get('/home')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home/DashboardKoordinator')
            ->where('tautan', ['motoris_ditandai' => null, 'outlet_ditandai' => null])
        );
    }

    public function test_drawer_absen_menampilkan_status_tiap_sales_di_tim(): void
    {
        // Rudi (1) sudah absen hari ini (2026-10-03), Tegar (3) belum — Joko (4, non-aktif) tidak ikut tim.
        // masuk_at disimpan UTC (JamLokal menampilkannya di WIB, +7 jam — lihat App\Support\Menu\JamLokal).
        DB::table('trx_absensi')->insert(['karyawan_id' => 1, 'depo_id' => 46, 'tanggal' => '2026-10-03', 'masuk_at' => '2026-10-03 06:00:00', 'terlambat' => true]);

        $this->actingAs($this->akun('koor13', 'koordinator', 137));

        $this->getJson('/home/absen-hari-ini')->assertOk()->assertExactJson(['baris' => [
            ['nama' => 'Tegar Mahendra', 'sudah' => false, 'waktu' => null, 'terlambat' => false],
            ['nama' => 'Rudi Hartono', 'sudah' => true, 'waktu' => '13:00', 'terlambat' => true],
        ]]);
    }

    public function test_drawer_checkin_outlet_menampilkan_status_tiap_sales_di_tim(): void
    {
        // Rudi (1) sudah checkin hari ini (kunjungan 2026-10-03 02:00 UTC dari setUp = 09:00 WIB), Tegar (3) belum.
        $this->actingAs($this->akun('koor14', 'koordinator', 137));

        $this->getJson('/home/checkin-outlet')->assertOk()->assertExactJson(['baris' => [
            ['nama' => 'Tegar Mahendra', 'sudah' => false, 'waktu' => null],
            ['nama' => 'Rudi Hartono', 'sudah' => true, 'waktu' => '09:00'],
        ]]);
    }

    public function test_drawer_motoris_ditandai_menampilkan_tanda_aktif_tim_saja(): void
    {
        DB::table('tanda_motoris')->insert([
            'karyawan_id' => 1, 'tanggal' => '2026-10-02', 'kategori' => 'lokasi_palsu',
            'dibuat_oleh' => null, 'dibuat_nama' => 'Sistem', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tanda_motoris')->insert([
            'karyawan_id' => 2, 'tanggal' => '2026-10-02', 'kategori' => 'lokasi_palsu', 'dibuat_oleh' => 1,
            'dibuat_nama' => 'Lain', 'created_at' => now(), 'updated_at' => now(),
        ]); // Sinta, di luar tim koordinator 137 — tidak ikut.

        $this->actingAs($this->akun('koor15', 'koordinator', 137));

        $this->getJson('/home/motoris-ditandai')->assertOk()->assertExactJson(['baris' => [
            ['motoris' => 'Rudi Hartono', 'kategori' => 'Diduga lokasi / GPS palsu', 'tanggal' => '2026-10-02', 'ditandai_oleh' => 'Sistem'],
        ]]);
    }

    public function test_drawer_outlet_ditandai_menampilkan_tanda_aktif_tim_saja(): void
    {
        DB::table('mk_outlet')->insert(['id' => 70, 'nama' => 'Toko Makmur', 'dibuat_oleh' => null]);
        DB::table('trx_outlet_baru')->insert(['karyawan_id' => 1, 'outlet_id' => 70, 'nama' => 'Toko Makmur', 'lat' => -7.4, 'lng' => 112.7, 'status' => 'disetujui']);
        DB::table('tanda_outlet')->insert([
            'outlet_id' => 70, 'kategori' => 'outlet_fiktif', 'dibuat_oleh' => null, 'dibuat_nama' => 'Sistem',
            'created_at' => '2026-10-02 06:00:00', 'updated_at' => now(),
        ]);

        $this->actingAs($this->akun('koor16', 'koordinator', 137));

        $this->getJson('/home/outlet-ditandai')->assertOk()->assertExactJson(['baris' => [
            ['outlet' => 'Toko Makmur', 'kategori' => 'Diduga outlet fiktif', 'waktu' => '2 Okt 2026 13:00', 'ditandai_oleh' => 'Sistem'],
        ]]);
    }

    public function test_drawer_motoris_dan_outlet_ditandai_ditolak_tanpa_hak_menu(): void
    {
        $terbatas = $this->akun('koor17', 'koordinator', 137, ['role_id' => $this->role('Koor Terbatas 2', 'downline', ['home' => ['lihat']])]);
        $this->actingAs($terbatas);

        $this->getJson('/home/motoris-ditandai')->assertForbidden();
        $this->getJson('/home/outlet-ditandai')->assertForbidden();
    }

    public function test_akun_koordinator_tanpa_karyawan_terhubung_tidak_dapat_tim(): void
    {
        $koordinator = $this->akun('koor2', 'koordinator', null);
        $this->actingAs($koordinator);

        $this->get('/home')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home/DashboardKoordinator')
                ->where('depo', null)
                ->where('absenHariIni.total', 0)->where('absenHariIni.hadir', 0)
                ->where('checkinOutlet.total', 0)->where('checkinOutlet.sudah', 0)->where('checkinOutlet.belum', 0)
                ->loadDeferredProps(fn (Assert $page) => $page
                    ->where('detail', null)
                    ->where('tandaMotoris.motoris', 0)->where('tandaMotoris.total', 0)
                    ->where('tandaOutlet.outlet', 0)->where('tandaOutlet.total', 0)
                )
            );
    }
}
