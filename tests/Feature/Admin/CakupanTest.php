<?php

namespace Tests\Feature\Admin;

use App\Support\Akses\Cakupan;

// Cakupan data per role: semua | depo | downline | diri (lewat antarmuka Cakupan, bukan HTTP).
class CakupanTest extends AdminTestCase
{
    private function idKaryawan(Cakupan $c): array
    {
        return $c->karyawan()->orderBy('k.id')->pluck('k.id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_semua_tidak_dibatasi(): void
    {
        $c = Cakupan::untuk($this->admin);

        $this->assertSame('semua', $c->mode());
        $this->assertSame([1, 2, 3, 4, 137, 500], $this->idKaryawan($c));
        $this->assertNull($c->depoIds());
        $this->assertSame('semua', $c->kunci());
        $this->assertTrue($c->mencakup(2));
        $this->assertTrue($c->mencakup(99999)); // data lama di luar mk_karyawan tidak dibatasi
    }

    public function test_downline_melihat_diri_dan_timnya(): void
    {
        $koor = $this->akun('koor', 'koordinator', 137);
        $c = Cakupan::untuk($koor);

        $this->assertTrue($c->tim());
        $this->assertSame([1, 3, 4, 137], $this->idKaryawan($c));
        $this->assertSame([46], $c->depoIds());
        $this->assertSame('user_'.$koor->id, $c->kunci());
        $this->assertTrue($c->mencakup(1));
        $this->assertFalse($c->mencakup(2));
    }

    public function test_diri_hanya_dirinya(): void
    {
        $c = Cakupan::untuk($this->akun('rudi', 'sales', 1));

        $this->assertSame('diri', $c->mode());
        $this->assertSame([1], $this->idKaryawan($c));
        $this->assertFalse($c->mencakup(3));
    }

    public function test_depo_melihat_seluruh_depo_karyawan_akun(): void
    {
        $c = Cakupan::untuk($this->akun('staf', 'lainnya', 500));

        $this->assertSame('depo', $c->mode());
        $this->assertSame([1, 3, 4, 137, 500], $this->idKaryawan($c));
        $this->assertSame([46], $c->depoIds());
        $this->assertFalse($c->mencakup(2));
    }

    public function test_akun_tanpa_karyawan_tidak_melihat_apa_pun(): void
    {
        $c = Cakupan::untuk($this->akun('koor-kosong', 'koordinator', null));

        $this->assertSame([], $this->idKaryawan($c));
        $this->assertSame([], $c->depoIds());
    }

    public function test_batasi_dipakai_pada_query_join_dan_tanpa_adalah_pengecualian_bernama(): void
    {
        $q = $this->akun('rudi', 'sales', 1);
        $baris = Cakupan::untuk($q)->batasi(\DB::table('mk_karyawan as x'), 'x')->pluck('x.id')->all();

        $this->assertSame([1], array_map('intval', $baris));
        $this->assertTrue(Cakupan::tanpa('uji')->semua());
    }
}
