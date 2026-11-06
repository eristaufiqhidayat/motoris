<?php

namespace Tests\Feature\Penggajian;

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\AdminTestCase;

class PengaturanPenggajianTest extends AdminTestCase
{
    public function test_super_admin_melihat_nilai_berlaku_dan_riwayat(): void
    {
        $this->travelTo('2026-11-06 10:00:00');

        $this->get('/pengaturan_penggajian')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Penggajian/Pengaturan')
            ->where('berlaku.cutoff_gaji.tanggal', 20)
            ->where('berlaku.bayar_gaji.tanggal', 25)
            ->where('berlaku.bayar_insentif.tanggal', 15)
            ->has('riwayat', 3)
        );
    }

    public function test_super_admin_mengubah_dan_riwayat_mencatat_pengubah(): void
    {
        $this->travelTo('2026-11-06 10:00:00');

        $this->post('/pengaturan_penggajian', ['kunci' => 'cutoff_gaji', 'tanggal' => 25, 'berlaku_mulai' => '2026-12-01'])
            ->assertRedirect('/pengaturan_penggajian');

        $this->get('/pengaturan_penggajian')->assertInertia(fn (Assert $p) => $p
            ->where('berlaku.cutoff_gaji.tanggal', 20) // masa depan belum berlaku
            ->has('riwayat', 4)
            ->where('riwayat.0.tanggal', 25)
            ->where('riwayat.0.oleh', 'admin')
        );

        $this->assertSame(1, DB::table('riwayat_admin')->where('objek', 'pengaturan_penggajian')->count());
    }

    public function test_nilai_di_luar_rentang_dan_tanggal_lampau_ditolak(): void
    {
        $this->travelTo('2026-11-06 10:00:00');

        $this->from('/pengaturan_penggajian')
            ->post('/pengaturan_penggajian', ['kunci' => 'cutoff_gaji', 'tanggal' => 29, 'berlaku_mulai' => '2026-12-01'])
            ->assertSessionHasErrors(['tanggal' => 'Tanggal harus antara 1 dan 28.']);

        $this->post('/pengaturan_penggajian', ['kunci' => 'bayar_insentif', 'tanggal' => 10, 'berlaku_mulai' => '2026-11-05'])
            ->assertSessionHasErrors(['berlaku_mulai' => 'Tanggal berlaku tidak boleh di masa lalu.']);

        $this->assertSame(3, DB::table('pengaturan_tanggal_penggajian')->count());
    }

    public function test_selain_super_admin_tidak_bisa_membuka_atau_mengubah(): void
    {
        foreach ([['koor', 'koordinator', 137], ['staf', 'lainnya', 500]] as [$nama, $kategori, $karyawan]) {
            $this->actingAs($this->akun($nama, $kategori, $karyawan));

            $this->get('/pengaturan_penggajian')->assertForbidden();
            $this->post('/pengaturan_penggajian', ['kunci' => 'cutoff_gaji', 'tanggal' => 25, 'berlaku_mulai' => '2099-01-01'])->assertForbidden();
        }
    }
}
