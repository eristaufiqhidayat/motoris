<?php

namespace Tests\Feature\Admin;

use App\Support\Akses\Akses;

// Grup sidebar "Absensi dan Penggajian" (data gaji/absensi sensitif) hanya untuk Super Admin.
class HakMenuAbsensiPenggajianTest extends AdminTestCase
{
    private const MENU = [
        'absensi_sales', 'absensi_sales_terlambat', 'absensi_koordinator', 'absensi_ssk_supervisor', 'permintaan_izin',
        'parameter_pemotong_gaji', 'potongan_gaji', 'penggajian_sales', 'penggajian_koordinator',
        'lap_absensi_sales', 'lap_absensi_koordinator', 'lap_izin_sakit', 'lap_insentif_sales', 'lap_insentif_koordinator',
        'lap_penggajian_sales', 'lap_penggajian_koordinator',
    ];

    public function test_koordinator_dan_lainnya_ditolak_dari_menu_absensi_dan_penggajian(): void
    {
        $this->actingAs($this->akun('koor', 'koordinator', 137));

        foreach (self::MENU as $slug) {
            $this->get("/{$slug}")->assertForbidden();
        }

        $this->actingAs($this->akun('staf', 'lainnya', 500));

        foreach (self::MENU as $slug) {
            $this->get("/{$slug}")->assertForbidden();
        }
    }

    public function test_super_admin_tetap_bisa_mengakses_menu_absensi_dan_penggajian(): void
    {
        $this->actingAs($this->admin);

        foreach (self::MENU as $slug) {
            $this->get("/{$slug}")->assertOk();
        }
    }

    public function test_grup_sidebar_absensi_dan_penggajian_hilang_untuk_koordinator(): void
    {
        $koor = $this->akun('koor2', 'koordinator', 137);

        $labelAdmin = collect(Akses::menu($this->admin))->pluck('label')->all();
        $labelKoor = collect(Akses::menu($koor))->pluck('label')->all();

        $this->assertContains('Absensi dan Penggajian', $labelAdmin);
        $this->assertNotContains('Absensi dan Penggajian', $labelKoor);
    }
}
