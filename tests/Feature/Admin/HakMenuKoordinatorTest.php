<?php

namespace Tests\Feature\Admin;

// Koordinator (role bawaan) tidak boleh mengelola Manajemen Produk, Konfigurasi, dan Master Data.
class HakMenuKoordinatorTest extends AdminTestCase
{
    public function test_koordinator_ditolak_dari_manajemen_produk_konfigurasi_dan_master_data(): void
    {
        $this->actingAs($this->akun('koor', 'koordinator', 137));

        foreach (['produk', 'master_area', 'master_depo', 'provinsi', 'rumus_insentif_koordinator'] as $slug) {
            $this->get("/{$slug}")->assertForbidden();
        }

        // Menu lain yang masih diizinkan bawaan tetap terbuka.
        $this->get('/home')->assertOk();
    }

    public function test_super_admin_tetap_bisa_mengakses_semua_menu_tersebut(): void
    {
        $this->actingAs($this->admin);

        foreach (['produk', 'master_area', 'master_depo', 'provinsi', 'rumus_insentif_koordinator'] as $slug) {
            $this->get("/{$slug}")->assertOk();
        }
    }
}
