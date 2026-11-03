<?php

namespace Tests\Feature\Admin;

use App\Support\Akses\Akses;

// Menu Karyawan (dan Lap. Karyawan/User, kini di popup menu User footer bersama menu akun) hanya untuk Super Admin.
class HakMenuKaryawanTest extends AdminTestCase
{
    private const MENU = ['karyawan_supervisor', 'karyawan_koordinator', 'karyawan_sales', 'karyawan_lainnya', 'lap_karyawan', 'lap_user'];

    public function test_koordinator_dan_lainnya_ditolak_dari_menu_karyawan(): void
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

    public function test_super_admin_tetap_bisa_mengakses_menu_karyawan(): void
    {
        $this->actingAs($this->admin);

        foreach (self::MENU as $slug) {
            $this->get("/{$slug}")->assertOk();
        }
    }

    public function test_menu_karyawan_dan_users_pindah_ke_popup_user_footer(): void
    {
        $sidebar = Akses::menu($this->admin);
        $footer = collect($sidebar)->firstWhere('posisi', 'footer');

        $slugDiPopup = collect($footer['items'])
            ->flatMap(fn ($item) => $item['children'] ?? [$item])
            ->pluck('slug')
            ->all();

        foreach (['super_admin', 'role', ...self::MENU] as $slug) {
            $this->assertContains($slug, $slugDiPopup);
        }

        $sidebarUtama = collect($sidebar)->filter(fn ($g) => ($g['posisi'] ?? null) !== 'footer')->pluck('label')->all();
        $this->assertNotContains('User dan Karyawan', $sidebarUtama);
    }
}
