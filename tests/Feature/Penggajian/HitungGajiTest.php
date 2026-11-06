<?php

namespace Tests\Feature\Penggajian;

use App\Support\Penggajian\HitungGaji;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Rumus gaji sales: gaji pokok, tunjangan internet, dan tunjangan jabatan penuh; tunjangan BBM & makan dan sewa
// motor prorata (hadir + izin) ÷ hari kerja. Periode uji: 20 Okt–19 Nov 2026 (27 hari kerja).
class HitungGajiTest extends TestCase
{
    use RefreshDatabase;

    private const MULAI = '2026-10-20';

    private const SELESAI = '2026-11-19';

    private function karyawan(array $nilai = []): object
    {
        return (object) ($nilai + [
            'id' => 1, 'nama' => 'Rudi', 'gaji_pokok' => 2700000, 'tunjangan_internet' => 100000, 'tunjangan_jabatan' => 200000,
            'tunjangan_bbm_makan' => 540000, 'sewa_motor' => 270000,
        ]);
    }

    private function komponen(array $hasil, string $kode): ?array
    {
        return collect($hasil['komponen'])->firstWhere('kode', $kode);
    }

    public function test_komponen_penuh_masuk_tanpa_melihat_kehadiran(): void
    {
        $h = HitungGaji::sales($this->karyawan(), ['hadir' => 0, 'terlambat' => 0], 27, 0);

        $this->assertSame(2700000.0, $this->komponen($h, 'gaji_pokok')['nominal']);
        $this->assertSame(100000.0, $this->komponen($h, 'tunjangan_internet')['nominal']);
        $this->assertSame(200000.0, $this->komponen($h, 'tunjangan_jabatan')['nominal']);
    }

    public function test_tunjangan_bbm_makan_dan_sewa_motor_prorata_dari_hadir(): void
    {
        $h = HitungGaji::sales($this->karyawan(), ['hadir' => 18, 'terlambat' => 2], 27, 0);

        $this->assertSame(360000.0, $this->komponen($h, 'tunjangan_bbm_makan')['nominal']); // 540.000 × 18/27
        $this->assertSame(180000.0, $this->komponen($h, 'sewa_motor')['nominal']); // 270.000 × 18/27
    }

    public function test_hari_izin_dihitung_sebagai_hadir_untuk_prorata(): void
    {
        $h = HitungGaji::sales($this->karyawan(), ['hadir' => 15, 'terlambat' => 0], 27, 3);

        $this->assertSame(360000.0, $this->komponen($h, 'tunjangan_bbm_makan')['nominal']); // (15+3)/27
        $this->assertSame(3, $h['hari_izin']);
    }

    public function test_prorata_dibulatkan_ke_rupiah_dan_tidak_melebihi_nilai_bulanan(): void
    {
        $h = HitungGaji::sales($this->karyawan(['tunjangan_bbm_makan' => 1000000]), ['hadir' => 10, 'terlambat' => 0], 27, 0);
        $this->assertSame(370370.0, $this->komponen($h, 'tunjangan_bbm_makan')['nominal']); // 370.370,37

        $h = HitungGaji::sales($this->karyawan(), ['hadir' => 30, 'terlambat' => 0], 27, 0); // masuk di hari Minggu
        $this->assertSame(540000.0, $this->komponen($h, 'tunjangan_bbm_makan')['nominal']);
    }

    public function test_asal_angka_disimpan_di_rincian(): void
    {
        $h = HitungGaji::sales($this->karyawan(), ['hadir' => 18, 'terlambat' => 2], 27, 1);

        $this->assertSame(
            ['nilai_bulanan' => 540000.0, 'hari_hadir' => 18, 'hari_izin' => 1, 'hari_kerja' => 27],
            $this->komponen($h, 'tunjangan_bbm_makan')['rincian'],
        );
        $this->assertSame(['nilai_bulanan' => 2700000.0], $this->komponen($h, 'gaji_pokok')['rincian']);
        $this->assertSame('pendapatan', $this->komponen($h, 'gaji_pokok')['tipe']);
        $this->assertSame('otomatis', $this->komponen($h, 'gaji_pokok')['sumber']);
    }

    public function test_total_pendapatan_dan_hari_di_hasil(): void
    {
        $h = HitungGaji::sales($this->karyawan(), ['hadir' => 18, 'terlambat' => 2], 27, 0);

        $this->assertSame(2700000.0 + 100000 + 200000 + 360000 + 180000, $h['total_pendapatan']);
        $this->assertSame(0.0, $h['total_potongan']);
        $this->assertSame($h['total_pendapatan'], $h['total_bersih']);
        $this->assertSame([27, 18, 2], [$h['hari_kerja'], $h['hari_hadir'], $h['hari_terlambat']]);
    }

    public function test_karyawan_tanpa_gaji_pokok_tidak_punya_komponen_itu(): void
    {
        $h = HitungGaji::sales($this->karyawan(['gaji_pokok' => null, 'tunjangan_jabatan' => 0]), ['hadir' => 27, 'terlambat' => 0], 27, 0);

        $this->assertNull($this->komponen($h, 'gaji_pokok'));
        $this->assertNull($this->komponen($h, 'tunjangan_jabatan'));
        $this->assertSame(100000.0 + 540000 + 270000, $h['total_pendapatan']);
    }

    public function test_hari_kerja_nol_tidak_membagi_dengan_nol(): void
    {
        $h = HitungGaji::sales($this->karyawan(), ['hadir' => 0, 'terlambat' => 0], 0, 0);

        $this->assertSame(0.0, $this->komponen($h, 'tunjangan_bbm_makan')['nominal']);
    }

    public function test_kehadiran_hanya_menghitung_absensi_dalam_periode_dengan_masuk(): void
    {
        $baris = fn (int $k, string $tgl, bool $masuk, bool $telat = false) => [
            'karyawan_id' => $k, 'tanggal' => $tgl, 'masuk_at' => $masuk ? "{$tgl} 08:00:00" : null, 'terlambat' => $telat,
        ];

        DB::table('trx_absensi')->insert([
            $baris(1, '2026-10-19', true), // sehari sebelum periode: tidak dihitung
            $baris(1, '2026-10-20', true),
            $baris(1, '2026-10-21', true, true),
            $baris(1, '2026-11-19', true),
            $baris(1, '2026-11-20', true), // sehari sesudah periode: tidak dihitung
            $baris(1, '2026-10-22', false), // tanpa jam masuk: bukan hadir
            $baris(2, '2026-10-25', true),
        ]);

        $k = HitungGaji::kehadiran([1, 2, 3], self::MULAI, self::SELESAI);

        $this->assertSame(['hadir' => 3, 'terlambat' => 1, 'terlambat_bulan' => ['2026-10' => 1]], $k[1]);
        $this->assertSame(['hadir' => 1, 'terlambat' => 0, 'terlambat_bulan' => []], $k[2]);
        $this->assertSame(['hadir' => 0, 'terlambat' => 0, 'terlambat_bulan' => []], $k[3]);
    }

    public function test_insentif_ec_dan_poin_dengan_asal_angka(): void
    {
        DB::table('rumus_insentif_sales')->insert([
            ['tot_ec_lower_bound' => '0', 'tot_ec_upper_bound' => '9', 'total_insentif' => '0'],
            ['tot_ec_lower_bound' => '10', 'tot_ec_upper_bound' => null, 'total_insentif' => '120000'],
        ]);
        DB::table('sales_point_conversion_per_warehouse')->insert(['depo' => 'Sidoarjo', 'poin_insentif_rp' => 250]);

        $h = HitungGaji::insentifSales('Sidoarjo', ['ec' => 12, 'poin' => 8]);

        $this->assertSame(['insentif_ec', 'insentif_poin'], array_column($h['komponen'], 'kode'));
        $this->assertSame(120000.0, $this->komponen($h, 'insentif_ec')['nominal']);
        $this->assertSame(['total_ec' => 12, 'tingkat' => ['bawah' => 10, 'atas' => null, 'insentif' => 120000]], $this->komponen($h, 'insentif_ec')['rincian']);
        $this->assertSame(2000.0, $this->komponen($h, 'insentif_poin')['nominal']);
        $this->assertSame(['total_poin' => 8, 'rupiah_per_poin' => 250], $this->komponen($h, 'insentif_poin')['rincian']);
        $this->assertSame(122000.0, $h['total_bersih']);
        $this->assertNull($h['hari_kerja']);
    }

    public function test_insentif_tanpa_rentang_cocok_bernilai_nol_dan_depo_tanpa_konversi_tidak_punya_poin(): void
    {
        DB::table('rumus_insentif_sales')->insert(['tot_ec_lower_bound' => '5', 'tot_ec_upper_bound' => '9', 'total_insentif' => '70000']);

        $h = HitungGaji::insentifSales('Depo Lain', ['ec' => 2, 'poin' => 40]);

        $this->assertSame(0.0, $this->komponen($h, 'insentif_ec')['nominal']);
        $this->assertNull($this->komponen($h, 'insentif_ec')['rincian']['tingkat']);
        $this->assertNull($this->komponen($h, 'insentif_poin'));
        $this->assertSame(0.0, $h['total_pendapatan']);
    }

    public function test_kehadiran_mengelompokkan_keterlambatan_per_bulan_kalender(): void
    {
        $telat = fn (string $tgl, bool $t = true) => ['karyawan_id' => 1, 'tanggal' => $tgl, 'masuk_at' => "{$tgl} 08:00:00", 'terlambat' => $t];

        DB::table('trx_absensi')->insert([
            $telat('2026-10-21'), $telat('2026-10-30'), $telat('2026-11-02'), $telat('2026-11-03', false), $telat('2026-11-18'),
            $telat('2026-10-19'), // di luar periode: tidak dihitung
        ]);

        $k = HitungGaji::kehadiran([1], self::MULAI, self::SELESAI);

        $this->assertSame(5, $k[1]['hadir']);
        $this->assertSame(4, $k[1]['terlambat']);
        $this->assertSame(['2026-10' => 2, '2026-11' => 2], $k[1]['terlambat_bulan']);
    }

    private function hadir(int $hadir, array $bulan): array
    {
        return ['hadir' => $hadir, 'terlambat' => array_sum($bulan), 'terlambat_bulan' => $bulan];
    }

    public function test_terlambat_sampai_3_kali_sebulan_tidak_dipotong(): void
    {
        $h = HitungGaji::sales($this->karyawan(), $this->hadir(20, ['2026-10' => 3]), 27, 0);

        $this->assertNull($this->komponen($h, 'potongan_terlambat_bbm_makan'));
        $this->assertNull($this->komponen($h, 'potongan_terlambat_sewa_motor'));
        $this->assertSame(0.0, $h['total_potongan']);
    }

    public function test_mulai_terlambat_ke_4_tunjangan_bbm_makan_dan_sewa_motor_dipotong_per_hari(): void
    {
        $h = HitungGaji::sales($this->karyawan(), $this->hadir(20, ['2026-10' => 5]), 27, 0); // 2 hari dipotong

        $bbm = $this->komponen($h, 'potongan_terlambat_bbm_makan');
        $sewa = $this->komponen($h, 'potongan_terlambat_sewa_motor');

        $this->assertSame(40000.0, $bbm['nominal']); // 540.000 × 2/27
        $this->assertSame(20000.0, $sewa['nominal']); // 270.000 × 2/27
        $this->assertSame(['potongan', 'otomatis'], [$bbm['tipe'], $bbm['sumber']]);
        $this->assertSame(60000.0, $h['total_potongan']);
        $this->assertSame($h['total_pendapatan'] - 60000, $h['total_bersih']);
    }

    public function test_potongan_terlambat_menyimpan_asal_angkanya(): void
    {
        $h = HitungGaji::sales($this->karyawan(), $this->hadir(20, ['2026-10' => 5, '2026-11' => 2]), 27, 0);

        $this->assertSame(
            ['hari_terlambat' => 7, 'toleransi_per_bulan' => 3, 'hari_dipotong' => 2, 'per_bulan' => ['2026-10' => 5, '2026-11' => 2], 'nilai_bulanan' => 540000.0, 'hari_kerja' => 27],
            $this->komponen($h, 'potongan_terlambat_bbm_makan')['rincian'],
        );
    }

    public function test_toleransi_dihitung_per_bulan_kalender_bukan_sepanjang_periode(): void
    {
        // 4 di Oktober dan 4 di November: 1 hari lewat toleransi di tiap bulan = 2 hari (bukan 8 − 3 = 5).
        $h = HitungGaji::sales($this->karyawan(), $this->hadir(20, ['2026-10' => 4, '2026-11' => 4]), 27, 0);
        $this->assertSame(40000.0, $this->komponen($h, 'potongan_terlambat_bbm_makan')['nominal']);

        // 3 di tiap bulan: total 6 tetapi tidak ada bulan yang lewat toleransi.
        $h = HitungGaji::sales($this->karyawan(), $this->hadir(20, ['2026-10' => 3, '2026-11' => 3]), 27, 0);
        $this->assertNull($this->komponen($h, 'potongan_terlambat_bbm_makan'));
    }

    public function test_tanpa_rincian_per_bulan_total_terlambat_dianggap_satu_bulan(): void
    {
        $h = HitungGaji::sales($this->karyawan(), ['hadir' => 10, 'terlambat' => 5], 27, 0);

        $this->assertSame(40000.0, $this->komponen($h, 'potongan_terlambat_bbm_makan')['nominal']);
        $this->assertArrayNotHasKey('per_bulan', $this->komponen($h, 'potongan_terlambat_bbm_makan')['rincian']);
    }

    public function test_hanya_komponen_yang_bernilai_yang_dipotong_dan_tidak_melebihi_tunjangan_yang_dibayar(): void
    {
        $h = HitungGaji::sales($this->karyawan(['sewa_motor' => null]), $this->hadir(20, ['2026-10' => 5]), 27, 0);
        $this->assertNotNull($this->komponen($h, 'potongan_terlambat_bbm_makan'));
        $this->assertNull($this->komponen($h, 'potongan_terlambat_sewa_motor'));

        // Data janggal (terlambat lebih banyak dari hari hadir): potongan dibatasi sebesar tunjangan prorata yang dibayar.
        $h = HitungGaji::sales($this->karyawan(), $this->hadir(1, ['2026-10' => 9]), 27, 0);
        $this->assertSame($this->komponen($h, 'tunjangan_bbm_makan')['nominal'], $this->komponen($h, 'potongan_terlambat_bbm_makan')['nominal']);
        $this->assertSame($this->komponen($h, 'sewa_motor')['nominal'], $this->komponen($h, 'potongan_terlambat_sewa_motor')['nominal']);
    }

    public function test_hari_kerja_nol_tidak_menghasilkan_potongan_terlambat(): void
    {
        $h = HitungGaji::sales($this->karyawan(), $this->hadir(0, ['2026-10' => 6]), 0, 0);

        $this->assertNull($this->komponen($h, 'potongan_terlambat_bbm_makan'));
    }

    public function test_koordinator_tidak_dikenai_potongan_terlambat(): void
    {
        $h = HitungGaji::koordinator($this->karyawan(), $this->hadir(20, ['2026-10' => 8]), 27, 0);

        $this->assertNull($this->komponen($h, 'potongan_terlambat_bbm_makan'));
        $this->assertSame(0.0, $h['total_potongan']);
    }
}
