<?php

namespace Tests\Feature\Admin;

use App\Support\Akses\Akses;
use Illuminate\Support\Facades\Route;

// Halaman menu umum (MenuController, /{slug}) tertutup bagi akun bercakupan sempit kecuali cakupannya jelas.
class CakupanMenuTest extends AdminTestCase
{
    /** @return array<string, array{bool, bool}> slug => [ada sumber, master] untuk halaman rute umum yang punya tabel */
    private function halamanUmum(): array
    {
        $rute = array_flip(array_map(fn ($r) => $r->uri(), Route::getRoutes()->get('GET')));
        $hasil = [];

        foreach (config('marketama.pages') as $slug => $halaman) {
            if (isset($rute[$slug]) || ! $halaman['tables']) {
                continue;
            }

            $sumber = false;
            $kumpul = function (array $items) use (&$kumpul, &$sumber) {
                foreach ($items as $i) {
                    is_array($i) && isset($i['tables']) ? $kumpul($i['tables']) : $sumber = $sumber || (bool) config('marketama.tables.'.(is_array($i) ? $i['table'] : $i).'.sumber');
                }
            };
            $kumpul($halaman['tables']);

            $hasil[$slug] = [$sumber, ($halaman['cakupan'] ?? null) === 'master'];
        }

        return $hasil;
    }

    private function akunTerbatas(string $cakupan)
    {
        $role = $this->role('Lihat semua '.$cakupan, $cakupan, array_fill_keys(Akses::semuaSlug(), ['lihat']));

        return $this->akun('terbatas-'.$cakupan, 'lainnya', 137, ['role_id' => $role]);
    }

    public function test_sapuan_halaman_umum_tanpa_cakupan_jelas_ditolak_bagi_akun_terbatas(): void
    {
        foreach (['downline', 'depo', 'diri'] as $cakupan) {
            $akun = $this->akunTerbatas($cakupan);

            foreach ($this->halamanUmum() as $slug => [$sumber, $master]) {
                $res = $this->actingAs($akun)->get('/'.$slug);

                $sumber || $master
                    ? $this->assertNotSame(403, $res->status(), "{$slug} ({$cakupan}) seharusnya terbuka")
                    : $this->assertSame(403, $res->status(), "{$slug} ({$cakupan}) seharusnya tertutup");
            }
        }
    }

    public function test_super_admin_tetap_membuka_semua_halaman_umum(): void
    {
        foreach (array_keys($this->halamanUmum()) as $slug) {
            $this->assertNotSame(403, $this->actingAs($this->admin)->get('/'.$slug)->status(), "{$slug} untuk Super Admin");
        }
    }

    public function test_sidebar_koordinator_menyembunyikan_data_transaksi_mentah(): void
    {
        $slug = collect(Akses::menu($this->akunTerbatas('downline')))
            ->flatMap(fn ($g) => collect($g['items'])->flatMap(fn ($i) => $i['children'] ?? [$i]))->pluck('slug')->all();

        // penggajian_sales dan penggajian_koordinator punya rute sendiri (PenggajianController membatasi per depo);
        // koordinator sungguhan tetap tidak melihatnya lewat Akses::MENU_ADMIN.
        foreach (['loading_barang', 'retur_barang', 'setor_margin', 'lap_loading_barang'] as $tutup) {
            $this->assertNotContains($tutup, $slug);
        }

        foreach (['provinsi', 'outlet_all'] as $buka) {
            $this->assertContains($buka, $slug);
        }
    }

    public function test_halaman_lap_tidak_pernah_ditandai_master(): void
    {
        // lap_* adalah hasil gabungan yang memuat nama sebagai teks: tidak bisa dibatasi cakupan.
        foreach (config('marketama.pages') as $slug => $halaman) {
            if (str_starts_with($slug, 'lap_')) {
                $this->assertNotSame('master', $halaman['cakupan'] ?? null, $slug);
            }
        }
    }
}
