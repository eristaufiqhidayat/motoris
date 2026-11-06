<?php

namespace Tests\Feature\Penggajian;

use App\Models\User;
use App\Support\Penggajian\PeriodeGaji;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PeriodeGajiTest extends TestCase
{
    use RefreshDatabase;

    public function test_cutoff_awal_20_membuat_25_oktober_masuk_periode_november(): void
    {
        $p = PeriodeGaji::gaji('2026-10-25');

        $this->assertSame('2026-10-20', $p['mulai']);
        $this->assertSame('2026-11-19', $p['selesai']);
        $this->assertSame('November 2026', $p['label']);
    }

    public function test_gaji_dibayar_tanggal_25_pada_bulan_label_periode(): void
    {
        $p = PeriodeGaji::gaji('2026-10-25');

        $this->assertSame('2026-11-25', $p['tanggal_bayar']);
        $this->assertSame('November 2026', $p['label']);
    }

    public function test_tanggal_bayar_gaji_bisa_diubah_dengan_tanggal_berlaku(): void
    {
        $this->travelTo('2026-11-06 10:00:00');
        PeriodeGaji::ubahPengaturan('bayar_gaji', 28, '2026-12-01', $this->admin());

        $this->assertSame('2026-11-25', PeriodeGaji::gaji('2026-10-25')['tanggal_bayar']); // periode November: belum berlaku
        $this->assertSame('2026-12-28', PeriodeGaji::gaji('2026-11-25')['tanggal_bayar']); // periode Desember
    }

    public function test_tanggal_sebelum_cutoff_masuk_periode_yang_dimulai_bulan_lalu(): void
    {
        $p = PeriodeGaji::gaji('2026-10-19');

        $this->assertSame(['2026-09-20', '2026-10-19'], [$p['mulai'], $p['selesai']]);
        $this->assertSame('Oktober 2026', $p['label']);
    }

    public function test_periode_melewati_pergantian_tahun(): void
    {
        $p = PeriodeGaji::gaji('2026-12-31');

        $this->assertSame(['2026-12-20', '2027-01-19'], [$p['mulai'], $p['selesai']]);
        $this->assertSame('Januari 2027', $p['label']);
    }

    public function test_cutoff_1_membuat_periode_sama_dengan_bulan_kalender_termasuk_februari(): void
    {
        $this->setelCutoff(1);

        $p = PeriodeGaji::gaji('2027-02-10');

        $this->assertSame(['2027-02-01', '2027-02-28'], [$p['mulai'], $p['selesai']]);
    }

    public function test_cutoff_28_berakhir_tanggal_27_bulan_berikutnya(): void
    {
        $this->setelCutoff(28);

        $p = PeriodeGaji::gaji('2027-01-28');

        $this->assertSame(['2027-01-28', '2027-02-27'], [$p['mulai'], $p['selesai']]);
    }

    public function test_tanggal_bayar_insentif_awal_15_bulan_berikutnya(): void
    {
        $p = PeriodeGaji::insentif('2026-11-10');

        $this->assertSame('2026-11-01', $p['mulai']);
        $this->assertSame('2026-11-30', $p['selesai']);
        $this->assertSame('2026-12-15', $p['tanggal_bayar']);
        $this->assertSame('November 2026', $p['label']);
    }

    public function test_tanggal_bayar_insentif_desember_jatuh_di_januari(): void
    {
        $this->assertSame('2027-01-15', PeriodeGaji::insentif('2026-12-05')['tanggal_bayar']);
    }

    public function test_pengaturan_bertanggal_masa_depan_belum_memengaruhi_periode_berjalan(): void
    {
        $this->travelTo('2026-11-06 10:00:00');
        PeriodeGaji::ubahPengaturan('cutoff_gaji', 25, '2026-12-01', $this->admin());

        $this->assertSame('2026-10-20', PeriodeGaji::gaji('2026-11-06')['mulai']);
        $this->assertSame('2026-11-20', PeriodeGaji::gaji('2026-11-25')['mulai']);
        $this->assertSame('2026-12-25', PeriodeGaji::gaji('2026-12-26')['mulai']);
    }

    public function test_periode_di_sekitar_pergantian_cutoff_bersambung_tanpa_tumpang_tindih(): void
    {
        $this->travelTo('2026-11-06 10:00:00');
        PeriodeGaji::ubahPengaturan('cutoff_gaji', 25, '2026-12-01', $this->admin());

        // Periode transisi: dimulai 20 Nov (cut off lama), berakhir sebelum 25 Des (cut off baru), tanpa celah.
        foreach (['2026-11-20', '2026-11-26', '2026-12-10', '2026-12-24'] as $t) {
            $p = PeriodeGaji::gaji($t);
            $this->assertSame(['2026-11-20', '2026-12-24'], [$p['mulai'], $p['selesai']]);
        }

        $p = PeriodeGaji::gaji('2026-12-25');
        $this->assertSame(['2026-12-25', '2027-01-24'], [$p['mulai'], $p['selesai']]);
    }

    public function test_pengaturan_dengan_tanggal_berlaku_sama_ditolak_tanpa_menimpa_riwayat(): void
    {
        $this->travelTo('2026-11-06 10:00:00');
        PeriodeGaji::ubahPengaturan('bayar_insentif', 10, '2026-11-06', $this->admin());

        try {
            PeriodeGaji::ubahPengaturan('bayar_insentif', 12, '2026-11-06', $this->admin());
            $this->fail('Tanggal berlaku yang sama seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('berlaku_mulai', $e->errors());
        }

        $this->assertSame(10, PeriodeGaji::nilai('bayar_insentif', '2026-11-06'));
    }

    public function test_cutoff_lebih_akhir_dari_tanggal_bayar_gaji_ditolak_dan_tidak_tersimpan(): void
    {
        $this->travelTo('2026-11-06 10:00:00');

        try {
            PeriodeGaji::ubahPengaturan('cutoff_gaji', 28, '2026-12-01', $this->admin()); // bayar gaji masih 25
            $this->fail('Cut off setelah tanggal bayar seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Tanggal bayar gaji (25) tidak boleh lebih awal dari tanggal cut off gaji (28)', $e->errors()['tanggal'][0]);
        }

        $this->assertSame(20, PeriodeGaji::nilai('cutoff_gaji', '2026-12-05'));
        $this->assertSame(0, DB::table('pengaturan_tanggal_penggajian')->where('berlaku_mulai', '2026-12-01')->count());
        $this->assertSame(0, DB::table('riwayat_admin')->where('objek', 'pengaturan_penggajian')->count());
    }

    public function test_tanggal_bayar_gaji_lebih_awal_dari_cutoff_ditolak(): void
    {
        $this->travelTo('2026-11-06 10:00:00');

        $this->expectException(ValidationException::class);

        PeriodeGaji::ubahPengaturan('bayar_gaji', 15, '2026-12-01', $this->admin()); // cut off 20
    }

    public function test_tanggal_bayar_sama_dengan_cutoff_diterima(): void
    {
        $this->travelTo('2026-11-06 10:00:00');
        PeriodeGaji::ubahPengaturan('cutoff_gaji', 25, '2026-12-01', $this->admin()); // bayar 25: sehari setelah periode berakhir

        $this->assertSame(25, PeriodeGaji::nilai('cutoff_gaji', '2026-12-05'));
    }

    public function test_perubahan_ditolak_bila_bentrok_dengan_pengaturan_terjadwal_sesudahnya(): void
    {
        $this->travelTo('2026-11-06 10:00:00');
        $admin = $this->admin();
        PeriodeGaji::ubahPengaturan('bayar_gaji', 28, '2026-12-01', $admin);
        PeriodeGaji::ubahPengaturan('cutoff_gaji', 28, '2027-01-01', $admin); // bayar 28 >= cut off 28: sah

        // Pada 15 Des (cut off 20, bayar 25) sah, tetapi pada 1 Jan jadi cut off 28 dengan bayar 25.
        try {
            PeriodeGaji::ubahPengaturan('bayar_gaji', 25, '2026-12-15', $admin);
            $this->fail('Perubahan yang membuat pengaturan terjadwal bentrok seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Berlaku mulai 2027-01-01', $e->errors()['tanggal'][0]);
        }

        $this->assertSame(0, DB::table('pengaturan_tanggal_penggajian')->where('berlaku_mulai', '2026-12-15')->count());
    }

    public function test_cutoff_tanggal_1_ditolak(): void
    {
        $this->travelTo('2026-11-06 10:00:00');

        $this->expectException(ValidationException::class);

        PeriodeGaji::ubahPengaturan('cutoff_gaji', 1, '2026-12-01', $this->admin());
    }

    public function test_tanggal_bayar_insentif_tidak_dibatasi_cutoff_gaji(): void
    {
        $this->travelTo('2026-11-06 10:00:00');
        PeriodeGaji::ubahPengaturan('bayar_insentif', 5, '2026-12-01', $this->admin());

        $this->assertSame(5, PeriodeGaji::nilai('bayar_insentif', '2026-12-05'));
    }

    public function test_pengaturan_berlaku_hari_ini_langsung_dipakai(): void
    {
        $this->travelTo('2026-11-06 10:00:00');
        PeriodeGaji::ubahPengaturan('bayar_insentif', 10, '2026-11-06', $this->admin());

        $this->assertSame('2026-12-10', PeriodeGaji::insentif('2026-11-10')['tanggal_bayar']);
    }

    public function test_nilai_di_luar_1_sampai_28_ditolak_dengan_pesan_jelas(): void
    {
        $this->travelTo('2026-11-06 10:00:00');

        foreach ([0, 29, 31, -1] as $nilai) {
            try {
                PeriodeGaji::ubahPengaturan('cutoff_gaji', $nilai, '2026-11-06', $this->admin());
                $this->fail("Nilai {$nilai} seharusnya ditolak.");
            } catch (ValidationException $e) {
                $this->assertSame('Tanggal harus antara 1 dan 28.', $e->errors()['tanggal'][0]);
            }
        }

        $this->assertSame(0, DB::table('pengaturan_tanggal_penggajian')->where('kunci', 'cutoff_gaji')->where('berlaku_mulai', '>=', '2026-01-01')->count());
    }

    public function test_tanggal_berlaku_lampau_ditolak(): void
    {
        $this->travelTo('2026-11-06 10:00:00');

        try {
            PeriodeGaji::ubahPengaturan('cutoff_gaji', 21, '2026-11-05', $this->admin());
            $this->fail('Tanggal berlaku lampau seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertSame('Tanggal berlaku tidak boleh di masa lalu.', $e->errors()['berlaku_mulai'][0]);
        }
    }

    public function test_kunci_tidak_dikenal_ditolak(): void
    {
        $this->expectException(ValidationException::class);

        PeriodeGaji::ubahPengaturan('lain', 10, '2099-01-01', $this->admin());
    }

    public function test_hari_kerja_senin_sampai_sabtu_dikurangi_libur(): void
    {
        // 2026-11-02 (Senin) .. 2026-11-08 (Minggu): 6 hari kerja.
        $this->assertSame(6, PeriodeGaji::hariKerja('2026-11-02', '2026-11-08'));

        DB::table('holiday_dates')->insert([
            ['nama_libur' => 'Cuti', 'tanggal' => '2026-11-04'], // Rabu
            ['nama_libur' => 'Libur Minggu', 'tanggal' => '2026-11-08'], // Minggu: tidak mengurangi dua kali
        ]);

        $this->assertSame(5, PeriodeGaji::hariKerja('2026-11-02', '2026-11-08'));
    }

    public function test_hari_kerja_satu_periode_gaji_penuh(): void
    {
        // 20 Okt–19 Nov 2026 = 31 hari, 4 Minggu (25 Okt, 1, 8, 15 Nov) → 27 hari kerja.
        $this->assertSame(27, PeriodeGaji::hariKerja('2026-10-20', '2026-11-19'));
    }

    private function setelCutoff(int $nilai): void
    {
        DB::table('pengaturan_tanggal_penggajian')->where('kunci', 'cutoff_gaji')->update(['tanggal' => $nilai]);
    }

    private ?User $aktor = null;

    private function admin(): User
    {
        return $this->aktor ??= User::factory()->create(['username' => 'admin', 'kategori' => 'super_admin']);
    }
}
