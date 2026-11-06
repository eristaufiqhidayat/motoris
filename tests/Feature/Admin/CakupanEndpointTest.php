<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Endpoint peta/riwayat yang dulu terbuka bagi akun bercakupan sempit kini mengikuti cakupan role.
class CakupanEndpointTest extends AdminTestCase
{
    private const MENU = ['tracking_sales_history_location', 'tracking_koordinator_history_location', 'lap_loading_barang'];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('mk_karyawan')->insert(['id' => 138, 'kategori' => 'koordinator', 'nama' => 'Koor Dua', 'depo_id' => 22, 'koordinator_id' => null, 'aktif' => true]);
        DB::table('tracking_koordinator')->insert([
            ['koordinator_id' => 137, 'nama' => 'Koor Satu', 'depo' => 'Sidoarjo'],
            ['koordinator_id' => 138, 'nama' => 'Koor Dua', 'depo' => 'Balaraja'],
        ]);
        DB::table('tracking_koordinator_lokasi')->insert([
            ['koordinator_id' => 137, 'tanggal' => '2026-10-21', 'waktu' => '2026-10-21 08:00:00', 'lat' => -6.1, 'lng' => 106.7, 'akurasi' => 5],
            ['koordinator_id' => 138, 'tanggal' => '2026-10-21', 'waktu' => '2026-10-21 08:00:00', 'lat' => -6.2, 'lng' => 106.8, 'akurasi' => 5],
        ]);
        DB::table('tracking_sales_lokasi')->insert([
            ['sales_id' => 1, 'tanggal' => '2026-10-21', 'waktu' => '2026-10-21 08:00:00', 'lat' => -6.1, 'lng' => 106.7],
            ['sales_id' => 2, 'tanggal' => '2026-10-21', 'waktu' => '2026-10-21 08:00:00', 'lat' => -6.2, 'lng' => 106.8],
        ]);
    }

    private function akunDengan(string $cakupan, int $karyawan): User
    {
        $role = $this->role('Uji '.$cakupan, $cakupan, array_fill_keys(self::MENU, ['lihat']));

        return $this->akun('uji-'.$cakupan, 'lainnya', $karyawan, ['role_id' => $role]);
    }

    public function test_rute_gps_motoris_di_luar_cakupan_ditolak(): void
    {
        $this->actingAs($this->akunDengan('downline', 137));

        $this->getJson('/tracking_sales_history_location/locations?sales_id=1&tanggal=2026-10-21')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/tracking_sales_history_location/locations?sales_id=2&tanggal=2026-10-21')->assertNotFound();
    }

    public function test_admin_tetap_melihat_rute_motoris_mana_pun(): void
    {
        $this->getJson('/tracking_sales_history_location/locations?sales_id=2&tanggal=2026-10-21')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_tracking_koordinator_hanya_koordinator_dalam_cakupan(): void
    {
        $this->actingAs($this->akunDengan('depo', 500));

        $props = $this->inertiaProps($this->get('/tracking_koordinator_history_location?tanggal=2026-10-21'), 'Tracking/History');
        $this->assertSame([137], array_column($props['people'], 'id'));
        $this->assertSame([137], array_map('intval', array_keys((array) $props['counts'])));

        $this->getJson('/tracking_koordinator_history_location/counts?tanggal=2026-10-21')->assertOk()->assertJsonMissing(['138' => 1]);
        $this->getJson('/tracking_koordinator_history_location/locations?koordinator_id=137&tanggal=2026-10-21')->assertOk();
        $this->getJson('/tracking_koordinator_history_location/locations?koordinator_id=138&tanggal=2026-10-21')->assertNotFound();
    }

    public function test_admin_melihat_semua_koordinator(): void
    {
        $props = $this->inertiaProps($this->get('/tracking_koordinator_history_location?tanggal=2026-10-21'), 'Tracking/History');

        $this->assertEqualsCanonicalizing([137, 138], array_map('intval', array_column($props['people'], 'id')));
    }

    public function test_riwayat_distributor_tertutup_bagi_akun_bercakupan_sempit(): void
    {
        // lap_loading_barang berisi nama koordinator sebagai teks, jadi tak bisa dibatasi: menunya tertutup (403).
        // Pembatasan depo di LapLoadingBarangController tinggal lapisan kedua.
        $this->actingAs($this->akunDengan('depo', 500));

        $this->getJson('/lap_loading_barang/riwayat-distributor?distributor_id=1')->assertForbidden();
    }
}
