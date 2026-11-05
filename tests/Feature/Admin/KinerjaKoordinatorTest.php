<?php

namespace Tests\Feature\Admin;

use App\Support\Motoris\KinerjaKoordinator;
use Illuminate\Support\Facades\DB;

// Kinerja koordinator di Beranda admin (spec 067): tindak lanjut temuan sistem, hasil tim (outlet per motoris), dan pembinaan
// (join visit). Tim koordinator 137 = Rudi (1) dan Tegar (3); koordinator 138 dibuat di sini dengan Sinta (2).
class KinerjaKoordinatorTest extends AdminTestCase
{
    private const DARI = '2026-09-04';

    private const SAMPAI = '2026-10-03';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTimezone('Asia/Jakarta')->setDate(2026, 10, 3)->setTime(12, 0));

        DB::table('mk_karyawan')->insert(['id' => 138, 'kategori' => 'koordinator', 'nama' => 'Koor Dua', 'depo_id' => 22, 'koordinator_id' => null, 'aktif' => true]);
        DB::table('mk_karyawan')->where('id', 2)->update(['koordinator_id' => 138]);
    }

    private function kinerja(?string $dari = null, ?string $sampai = null): KinerjaKoordinator
    {
        return new KinerjaKoordinator($dari ?? self::DARI, $sampai ?? self::SAMPAI, $this->admin);
    }

    private function baris(int $koordinator): array
    {
        return collect($this->kinerja()->baris())->firstWhere('id', $koordinator);
    }

    /** Tanda motoris buatan sistem: dibuat $dibuat, status $status, diperiksa $dicek (null = belum). */
    private function tandaMotoris(int $karyawan, string $tanggal, string $dibuat, string $status, ?string $dicek = null): void
    {
        DB::table('tanda_motoris')->insert([
            'karyawan_id' => $karyawan, 'tanggal' => $tanggal, 'kategori' => 'lokasi_palsu', 'dibuat_oleh' => null, 'dibuat_nama' => 'Sistem',
            'status' => $status, 'dicek_at' => $dicek, 'created_at' => $dibuat, 'updated_at' => $dibuat,
        ]);
    }

    public function test_tindak_lanjut_temuan_tim(): void
    {
        $this->tandaMotoris(1, '2026-10-01', '2026-10-02 08:00:00', 'terbukti', '2026-10-03 08:00:00'); // 1 hari
        $this->tandaMotoris(3, '2026-10-01', '2026-10-01 08:00:00', 'wajar', '2026-10-03 08:00:00'); // 2 hari
        $this->tandaMotoris(1, '2026-09-20', '2026-09-25 08:00:00', 'perlu_dicek'); // menunggu 8 hari
        $this->tandaMotoris(3, '2026-10-02', '2026-10-02 08:00:00', 'perlu_dicek'); // menunggu 1 hari
        $this->tandaMotoris(2, '2026-10-02', '2026-10-02 08:00:00', 'perlu_dicek'); // tim koordinator lain

        $k = $this->baris(137);

        $this->assertSame(2, $k['motoris']);
        $this->assertSame(4, $k['temuan']);
        $this->assertSame(2, $k['diperiksa']);
        $this->assertSame(50.0, $k['tindak_lanjut']);
        $this->assertSame(1.5, $k['median_hari']);
        $this->assertSame(1, $k['menunggu_lama']); // hanya yang menunggu lebih dari 7 hari
        $this->assertSame(1, $k['terbukti']);
        $this->assertSame(50.0, $k['rasio_terbukti']);
        $this->assertFalse($k['perlu_audit']); // baru 2 diperiksa (< 5)
    }

    public function test_koordinator_tanpa_temuan_tidak_punya_persen_tindak_lanjut(): void
    {
        $k = $this->baris(138);

        $this->assertSame(0, $k['temuan']);
        $this->assertNull($k['tindak_lanjut']);
        $this->assertNull($k['median_hari']);
        $this->assertNull($k['rasio_terbukti']);
    }

    public function test_temuan_di_luar_periode_tidak_dihitung_kecuali_menunggu_lama(): void
    {
        $this->tandaMotoris(1, '2026-08-01', '2026-08-02 08:00:00', 'terbukti', '2026-08-03 08:00:00'); // sebelum periode
        $this->tandaMotoris(1, '2026-08-05', '2026-08-06 08:00:00', 'perlu_dicek'); // sebelum periode tapi masih menunggu

        $k = $this->baris(137);

        $this->assertSame(0, $k['temuan']);
        $this->assertSame(1, $k['menunggu_lama']);
    }

    public function test_tanda_outlet_dihitung_lewat_motoris_tim_yang_mengunjungi_outlet(): void
    {
        DB::table('trx_kunjungan')->insert(['karyawan_id' => 1, 'tanggal' => '2026-10-02', 'outlet_id' => 701, 'checkin_at' => '2026-10-02 02:00:00']);
        DB::table('trx_kunjungan')->insert(['karyawan_id' => 1, 'tanggal' => '2026-10-02', 'outlet_id' => 701, 'checkin_at' => '2026-10-02 03:00:00']); // kunjungan ganda tidak menggandakan tanda
        DB::table('tanda_outlet')->insert([
            'outlet_id' => 701, 'kategori' => 'outlet_fiktif', 'status' => 'terbukti', 'dibuat_nama' => 'Sistem',
            'dicek_at' => '2026-10-03 08:00:00', 'created_at' => '2026-10-02 08:00:00', 'updated_at' => '2026-10-02 08:00:00',
        ]);

        $this->assertSame(1, $this->baris(137)['temuan']);
        $this->assertSame(1, $this->baris(137)['terbukti']);
        $this->assertSame(0, $this->baris(138)['temuan']);
    }

    public function test_perlu_audit_bila_rasio_terbukti_jauh_di_bawah_nasional(): void
    {
        foreach ([1, 2, 3, 4, 5] as $i) { // koordinator 137: 5 diperiksa, semua terbukti
            $this->tandaMotoris(1, "2026-09-0{$i}", '2026-09-10 08:00:00', 'terbukti', '2026-09-11 08:00:00');
        }
        foreach ([1, 2, 3, 4, 5] as $i) { // koordinator 138: 5 diperiksa, semua wajar
            $this->tandaMotoris(2, "2026-09-1{$i}", '2026-09-16 08:00:00', 'wajar', '2026-09-17 08:00:00');
        }

        $this->assertFalse($this->baris(137)['perlu_audit']);
        $this->assertTrue($this->baris(138)['perlu_audit']); // rasio 0% < separuh rasio nasional (50%)
        $this->assertSame(1, $this->kinerja()->nasional()['perlu_audit']);
        $this->assertSame(50.0, $this->kinerja()->nasional()['rasio_terbukti']);
    }

    public function test_outlet_per_motoris_tim_tanpa_outlet_terbukti_fiktif(): void
    {
        $kunjungan = DB::table('trx_kunjungan')->insertGetId(['karyawan_id' => 1, 'tanggal' => '2026-10-02', 'checkin_at' => '2026-10-02 02:00:00']);
        $jual = fn (string $kode, int $karyawan, int $outlet, bool $ec = true) => [
            'kode' => $kode, 'kunjungan_id' => $kunjungan, 'karyawan_id' => $karyawan, 'outlet_id' => $outlet, 'tanggal' => '2026-10-02',
            'jumlah_sku' => 1, 'total_qty' => 1, 'total' => 1000, 'poin' => 0, 'is_effective_call' => $ec,
        ];
        DB::table('trx_penjualan')->insert([
            $jual('K1', 1, 801), $jual('K2', 1, 802), $jual('K3', 3, 802), // dua motoris menjual ke 802
            $jual('K4', 1, 803), // outlet fiktif terbukti → tidak dihitung
            $jual('K5', 3, 804, false), // bukan EC → tidak dihitung
        ]);
        DB::table('tanda_outlet')->insert([
            'outlet_id' => 803, 'kategori' => 'outlet_fiktif', 'status' => 'terbukti', 'dibuat_nama' => 'Sistem', 'created_at' => '2026-10-01 08:00:00', 'updated_at' => '2026-10-01 08:00:00',
        ]);

        $this->assertSame(1.0, $this->baris(137)['outlet_per_motoris']); // 2 outlet (801, 802) ÷ 2 motoris
        $this->assertSame(0.0, $this->baris(138)['outlet_per_motoris']);
    }

    public function test_join_visit_cakupan_dan_keabsahan(): void
    {
        $visit = fn (int $sales, array $lain = []) => $lain + [
            'koordinator_id' => 137, 'sales_id' => $sales, 'tanggal' => '2026-10-02', 'jarak_m' => 50, 'mock_location' => false, 'selesai_at' => '2026-10-02 05:00:00',
        ];
        DB::table('trx_join_visit')->insert([
            $visit(1), // sah
            $visit(1, ['mock_location' => true]), // mock location
            $visit(3, ['selesai_at' => null]), // belum selesai
            $visit(3, ['jarak_m' => 500]), // terlalu jauh dari outlet
            $visit(3, ['tanggal' => '2026-08-01']), // di luar periode
        ]);

        $k = $this->baris(137);

        $this->assertSame(4, $k['join_visit']);
        $this->assertSame(100.0, $k['cakupan_join_visit']); // motoris 1 dan 3 sama-sama pernah di-join
        $this->assertSame(25.0, $k['join_visit_sah']); // 1 dari 4
        $this->assertSame(0.0, $this->baris(138)['cakupan_join_visit']);
        $this->assertNull($this->baris(138)['join_visit_sah']);
    }

    public function test_akun_cakupan_depo_hanya_melihat_koordinator_di_depo_sendiri(): void
    {
        $this->actingAs($this->akun('stafdepo', 'lainnya', 2)); // Sinta, depo Balaraja (22)

        $ids = collect((new KinerjaKoordinator(self::DARI, self::SAMPAI, auth()->user()))->baris())->pluck('id')->all();

        $this->assertSame([138], $ids);
    }

    public function test_perhatian_mengurutkan_tindak_lanjut_terendah_lalu_cakupan_join_visit(): void
    {
        $this->tandaMotoris(1, '2026-10-01', '2026-10-02 08:00:00', 'terbukti', '2026-10-03 08:00:00'); // 137: 100%
        $this->tandaMotoris(2, '2026-10-01', '2026-10-02 08:00:00', 'perlu_dicek'); // 138: 0%

        $urut = collect($this->kinerja()->perhatian(10))->pluck('id')->all();

        $this->assertSame([138, 137], $urut);
    }

    public function test_kecepatan_verifikasi_noo_dan_antrean_menunggu_lama(): void
    {
        $noo = fn (int $karyawan, string $status, string $daftar, ?string $verifikasi) => [
            'karyawan_id' => $karyawan, 'nama' => 'Warung', 'lat' => -7.4, 'lng' => 112.7, 'status' => $status,
            'recorded_at' => $daftar, 'diverifikasi_pada' => $verifikasi,
        ];
        DB::table('trx_outlet_baru')->insert([
            $noo(1, 'disetujui', '2026-10-01 08:00:00', '2026-10-02 08:00:00'), // 1 hari
            $noo(3, 'ditolak', '2026-09-28 08:00:00', '2026-10-01 08:00:00'), // 3 hari
            $noo(1, 'menunggu', '2026-09-25 08:00:00', null), // menunggu 8 hari (> 3)
            $noo(3, 'menunggu', '2026-10-03 06:00:00', null), // baru hari ini
            $noo(1, 'disetujui', '2026-07-01 08:00:00', '2026-07-02 08:00:00'), // diverifikasi sebelum periode
            $noo(2, 'menunggu', '2026-09-20 08:00:00', null), // Sinta, tim koordinator 138
        ]);

        $k = $this->baris(137);

        $this->assertSame(2.0, $k['noo_median_hari']); // median 1 dan 3 hari
        $this->assertSame(1, $k['noo_menunggu_lama']);
        $this->assertNull($this->baris(138)['noo_median_hari']);
        $this->assertSame(1, $this->baris(138)['noo_menunggu_lama']);
        $this->assertSame(2, $this->kinerja()->nasional()['noo_menunggu_lama']);
        $this->assertSame(2.0, $this->kinerja()->nasional()['noo_median_hari']);
    }
}
