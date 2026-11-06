<?php

namespace Tests\Feature\Penggajian;

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\AdminTestCase;

// Riwayat gaji dan insentif per orang: dari penggajian yang sudah final saja, dalam cakupan depo akun.
// Depo 46: Rudi (1) dan Tegar (3). Depo 22: Sinta (2).
class PenggajianRiwayatTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-25 10:00:00');

        DB::table('mk_karyawan')->whereIn('id', [1, 2, 3])->update(['gaji_pokok' => 2500000, 'tunjangan_internet' => 100000]);
        DB::table('rumus_insentif_sales')->insert(['tot_ec_lower_bound' => '0', 'tot_ec_upper_bound' => '100', 'total_insentif' => '50000']);
        DB::table('parameter_pemotong_gaji')->insert(['id' => 5, 'nama' => 'Potongan BPJS Kesehatan']);
    }

    /** Buat penggajian lewat HTTP; $status 'final' memfinalkannya, 'batal' membatalkannya, 'draf' membiarkannya. */
    private function penggajian(string $kategori, int $depo, string $mulai, string $status = 'final'): int
    {
        $this->post('/penggajian_sales', ['kategori' => $kategori, 'depo_id' => $depo, 'periode_mulai' => $mulai]);
        $id = (int) DB::table('penggajian')->orderByDesc('id')->value('id');

        if ($status === 'final') {
            $this->post("/penggajian_sales/{$id}/finalkan");
        } elseif ($status === 'batal') {
            $this->post("/penggajian_sales/{$id}/batalkan");
        }

        return $id;
    }

    private function riwayat(string $q = '')
    {
        return $this->get('/penggajian_sales?tab=riwayat'.($q !== '' ? '&q='.urlencode($q) : ''));
    }

    public function test_pencarian_nama_menampilkan_semua_periode_orang_itu_dengan_kategori_dan_total(): void
    {
        $gaji = $this->penggajian('gaji', 46, '2026-10-20');
        $this->penggajian('insentif', 46, '2026-10-01');
        $this->penggajian('gaji', 22, '2026-10-20'); // depo lain, bukan Rudi

        $this->riwayat('rudi')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Penggajian/Index')
            ->where('tab', 'riwayat')
            ->where('q', 'rudi')
            ->where('baris.total', 2)
            ->where('baris.data.0.nama', 'Rudi Hartono')
            ->where('baris.data.0.kategori', 'Gaji')
            ->where('baris.data.0.depo', 'Sidoarjo')
            ->where('baris.data.0.no_penggajian', DB::table('penggajian')->find($gaji)->no_penggajian)
            ->where('baris.data.0.pendapatan', 'Rp 2.600.000')
            ->where('baris.data.0.potongan', 'Rp 0')
            ->where('baris.data.0.bersih', 'Rp 2.600.000')
            ->where('baris.data.1.kategori', 'Insentif')
            ->where('baris.data.1.pendapatan', 'Rp 50.000'));
    }

    public function test_pencarian_sebagian_nama_tanpa_membedakan_huruf_besar_kecil(): void
    {
        $this->penggajian('gaji', 46, '2026-10-20');

        $this->riwayat('HARTO')->assertInertia(fn (Assert $p) => $p->where('baris.total', 1)->where('baris.data.0.nama', 'Rudi Hartono'));
        $this->riwayat('tidak ada')->assertInertia(fn (Assert $p) => $p->where('baris.total', 0));
        $this->riwayat('%')->assertInertia(fn (Assert $p) => $p->where('baris.total', 0)); // karakter wildcard dianggap huruf biasa
    }

    public function test_tanpa_pencarian_menampilkan_semua_riwayat_final_terbaru_dulu(): void
    {
        $this->penggajian('gaji', 46, '2026-09-20');
        $this->penggajian('gaji', 46, '2026-10-20');

        $this->riwayat()->assertInertia(fn (Assert $p) => $p
            ->where('baris.total', 4) // Rudi dan Tegar × 2 periode
            ->where('baris.data.0.periode', fn ($v) => str_starts_with($v, 'November 2026')));
    }

    public function test_draf_dan_penggajian_batal_tidak_tampil(): void
    {
        $this->penggajian('gaji', 46, '2026-09-20', 'draf');
        $this->penggajian('gaji', 46, '2026-08-20', 'batal');
        $final = $this->penggajian('insentif', 46, '2026-10-01', 'final');

        $this->riwayat('rudi')->assertInertia(fn (Assert $p) => $p
            ->where('baris.total', 1)
            ->where('baris.data.0.no_penggajian', DB::table('penggajian')->find($final)->no_penggajian));
    }

    public function test_baris_memuat_rincian_komponen_untuk_drawer(): void
    {
        $this->penggajian('gaji', 46, '2026-10-20');

        $this->riwayat('rudi')->assertInertia(fn (Assert $p) => $p
            ->where('baris.data.0.orang.nama', 'Rudi Hartono')
            ->where('baris.data.0.orang.kategori', 'gaji')
            ->where('baris.data.0.orang.hari_kerja', 27)
            ->where('baris.data.0.orang.total_pendapatan', 2600000)
            ->has('baris.data.0.orang.komponen', 2)
            ->where('baris.data.0.orang.komponen.0.kode', 'gaji_pokok')
            ->where('baris.data.0.orang.komponen.0.rincian.nilai_bulanan', 2500000));
    }

    public function test_riwayat_hanya_menampilkan_depo_dalam_cakupan_akun(): void
    {
        $this->penggajian('gaji', 46, '2026-10-20');
        $this->penggajian('gaji', 22, '2026-10-20');

        $this->riwayat()->assertInertia(fn (Assert $p) => $p->where('baris.total', 3)); // Rudi, Tegar, Sinta

        $role = $this->role('Admin Depo', 'depo', ['penggajian_sales' => ['lihat']]);
        $this->actingAs($this->akun('depo46', 'koordinator', 137, ['role_id' => $role]));

        $this->riwayat()->assertInertia(fn (Assert $p) => $p->where('baris.total', 2)->where('baris.data.0.depo', 'Sidoarjo'));
        $this->riwayat('sinta')->assertInertia(fn (Assert $p) => $p->where('baris.total', 0));
    }

    public function test_riwayat_ditolak_untuk_selain_super_admin_dan_tab_riwayat_tanpa_tombol_tambah(): void
    {
        $this->get('/penggajian_sales?tab=riwayat')->assertInertia(fn (Assert $p) => $p->where('bisaTambah', false));

        $this->actingAs($this->akun('koor', 'koordinator', 137));
        $this->get('/penggajian_sales?tab=riwayat')->assertForbidden();
    }

    public function test_daftar_punya_tab_riwayat_dan_tab_lain_tetap_berfungsi(): void
    {
        $this->get('/penggajian_sales')->assertInertia(fn (Assert $p) => $p
            ->where('tabs', fn ($t) => collect($t)->pluck('key')->all() === ['gaji', 'insentif', 'riwayat'])
            ->where('tab', 'gaji'));
        $this->get('/penggajian_sales?tab=insentif')->assertInertia(fn (Assert $p) => $p->where('tab', 'insentif'));
        $this->get('/penggajian_sales?tab=entah')->assertInertia(fn (Assert $p) => $p->where('tab', 'gaji'));
    }
}
