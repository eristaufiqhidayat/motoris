<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Monitoring Harian Sales › Sales Tidak Absen Pagi dari trx_absensi + mk_karyawan:
// sales aktif dalam cakupan role akun yang tidak punya absen masuk pada tanggal terpilih.
class SalesTidakAbsenTest extends AdminTestCase
{
    private const URL = '/monitoring_harian_sales?tab=sales_tidak_absen&tanggal=2026-10-22';

    protected function setUp(): void
    {
        parent::setUp();

        // Karyawan dasar (AdminTestCase): Rudi(1) & Tegar(3) depo Sidoarjo koor 137, Sinta(2) Balaraja, Joko(4) nonaktif.
        DB::table('mk_karyawan')->insert([
            ['id' => 5, 'kategori' => 'sales', 'nama' => 'Wati Lestari', 'depo_id' => 46, 'koordinator_id' => 137, 'aktif' => true],
            ['id' => 6, 'kategori' => 'sales', 'nama' => 'Fajar Nugroho', 'depo_id' => 22, 'koordinator_id' => null, 'aktif' => true],
        ]);

        $absen = fn (int $karyawan, string $tanggal) => ['karyawan_id' => $karyawan, 'tanggal' => $tanggal, 'masuk_at' => now()];
        DB::table('trx_absensi')->insert([
            $absen(1, '2026-10-22'), // Rudi: sudah absen
            $absen(6, '2026-10-22'), // Fajar: sudah absen
            $absen(2, '2026-10-21'), // Sinta: absen hari lain, bukan hari ini
            $absen(137, '2026-10-22'), // koordinator: bukan sales
        ]);
    }

    public function test_daftar_sales_belum_absen_dan_kpi(): void
    {
        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $page) => $page
                ->where('tables.0.rows.total', 3)
                // Urut depo lalu nama; nomor urut diisi.
                ->where('tables.0.rows.data.0.no', 1)
                ->where('tables.0.rows.data.0.depo', 'Balaraja')
                ->where('tables.0.rows.data.0.nama_sales', 'Sinta Wulandari')
                ->where('tables.0.rows.data.1.nama_sales', 'Tegar Mahendra')
                ->where('tables.0.rows.data.1.koordinator', 'Koor Satu')
                ->where('tables.0.rows.data.2.nama_sales', 'Wati Lestari')
                ->where('tables.0.kpi', [
                    ['label' => 'Sales aktif', 'value' => 5],
                    ['label' => 'Sudah absen', 'value' => 2],
                    ['label' => 'Belum absen', 'value' => 3],
                ])
                ->where('tables.0.keterangan', fn ($teks) => str_contains($teks, 'belum absen masuk'))
                ->where('tables.0.filter_pilihan.depo', ['22' => 'Balaraja', '46' => 'Sidoarjo'])
                ->where('tables.0.filter_pilihan.koordinator', ['137' => 'Koor Satu'])
            )
        );
    }

    public function test_filter_depo_koordinator_dan_pencarian(): void
    {
        $this->get(self::URL.'&depo=46')->assertInertia(fn (Assert $page) => $page
            ->where('filter.depo', 46)
            ->loadDeferredProps(fn (Assert $page) => $page
                ->where('tables.0.rows.total', 2)
                ->where('tables.0.kpi.0.value', 3) // Rudi, Tegar, Wati
            ));
        $this->get(self::URL.'&koordinator=137')->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $page) => $page
                ->where('tables.0.rows.total', 2)
                ->where('tables.0.rows.data.0.nama_sales', 'Tegar Mahendra')
            ));
        $this->get(self::URL.'&q=wati')->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $page) => $page
                ->where('tables.0.rows.total', 1)
                ->where('tables.0.rows.data.0.nama_sales', 'Wati Lestari')
            ));
    }

    public function test_tanggal_bawaan_hari_ini_wib(): void
    {
        // 25 Okt 2026 00:30 UTC = 07:30 WIB: tanggal bawaan ikut WIB, semua sales aktif belum absen.
        $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 10, 24)->setTime(23, 30));

        $this->get('/monitoring_harian_sales?tab=sales_tidak_absen')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tanggal', '2026-10-25')
            ->loadDeferredProps(fn (Assert $page) => $page
                ->where('tables.0.rows.total', 5)
                ->where('tables.0.kpi.2.value', 5)
            ));
    }

    public function test_cakupan_role_koordinator(): void
    {
        $koor = $this->akun('koor.satu', 'koordinator', 137, [
            'role_id' => $this->role('Koor Monitoring', 'downline', ['monitoring_harian_sales' => ['lihat']]),
        ]);
        $this->actingAs($koor);

        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $page) => $page
                ->where('tables.0.rows.total', 2)
                ->where('tables.0.kpi.0.value', 3)
                ->where('tables.0.filter_pilihan.depo', ['46' => 'Sidoarjo'])
            ));
    }
}
