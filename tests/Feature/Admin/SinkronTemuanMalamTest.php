<?php

namespace Tests\Feature\Admin;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

// Sinkronisasi temuan otomatis setiap malam (spec 067): perintah temuan:sinkron menjalankan SinkronTemuan per tim koordinator
// untuk kemarin, supaya temuan tidak bergantung pada koordinator menekan tombol Sinkronisasi Data. Tim koordinator 137 =
// Rudi (1) dan Tegar (3); Sinta (2) tanpa koordinator.
class SinkronTemuanMalamTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 3 Okt 2026 = hari ini; kemarin = 2 Okt.
        $this->travelTo(now()->setTimezone('Asia/Jakarta')->setDate(2026, 10, 3)->setTime(1, 0));
    }

    private function kunjunganMock(int $karyawan, string $tanggal): void
    {
        DB::table('trx_kunjungan')->insert([
            'karyawan_id' => $karyawan, 'tanggal' => $tanggal, 'checkin_at' => "{$tanggal} 02:00:00", 'checkout_at' => "{$tanggal} 02:05:00",
            'lat' => -7.4, 'lng' => 112.7, 'mock_location' => true,
        ]);
    }

    private function tanda(): array
    {
        return DB::table('tanda_motoris')->orderBy('karyawan_id')->get()
            ->map(fn ($t) => [(int) $t->karyawan_id, substr($t->tanggal, 0, 10), $t->status, $t->dibuat_nama])->all();
    }

    public function test_memeriksa_kemarin_untuk_semua_tim_termasuk_motoris_tanpa_koordinator(): void
    {
        $this->kunjunganMock(3, '2026-10-02'); // Tegar, tim koordinator 137
        $this->kunjunganMock(2, '2026-10-02'); // Sinta, tanpa koordinator
        $this->kunjunganMock(1, '2026-10-01'); // Rudi, bukan kemarin — tidak diperiksa

        $this->artisan('temuan:sinkron')->assertSuccessful();

        $this->assertSame([[2, '2026-10-02', 'perlu_dicek', 'Sistem'], [3, '2026-10-02', 'perlu_dicek', 'Sistem']], $this->tanda());
        $this->assertSame(3, DB::table('pemeriksaan_motoris')->where('tanggal', '2026-10-02')->count()); // Rudi, Sinta, Tegar
    }

    public function test_opsi_tanggal_memeriksa_tanggal_itu(): void
    {
        $this->kunjunganMock(1, '2026-10-01');

        $this->artisan('temuan:sinkron', ['--tanggal' => '2026-10-01'])->assertSuccessful();

        $this->assertSame([[1, '2026-10-01', 'perlu_dicek', 'Sistem']], $this->tanda());
    }

    public function test_dijalankan_dua_kali_tidak_membuat_tanda_ganda(): void
    {
        $this->kunjunganMock(3, '2026-10-02');

        $this->artisan('temuan:sinkron')->assertSuccessful();
        $this->artisan('temuan:sinkron')->assertSuccessful();

        $this->assertCount(1, $this->tanda());
    }

    public function test_tanggal_tidak_valid_ditolak(): void
    {
        $this->artisan('temuan:sinkron', ['--tanggal' => 'kemarin'])->assertFailed();
        $this->artisan('temuan:sinkron', ['--tanggal' => '2026-10-04'])->assertFailed(); // di masa depan
    }

    public function test_terdaftar_di_jadwal_pukul_01(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'temuan:sinkron'));

        $this->assertNotNull($event);
        $this->assertSame('0 1 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
