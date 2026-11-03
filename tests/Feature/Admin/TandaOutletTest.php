<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Akses\Akses;
use Illuminate\Support\Facades\DB;

// Tanda outlet (diduga outlet fiktif, spec 062): dibuat sistem per titik lokasi (kelompok); koordinator hanya melihat dan
// memeriksa (Terbukti / Wajar + catatan), dan hasilnya berlaku untuk seluruh kelompok. Hak, validasi, audit.
class TandaOutletTest extends AdminTestCase
{
    private const URL = '/outlet_all/tanda';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('mk_outlet')->insert([
            ['id' => 10, 'kode' => 'OUT-10', 'nama' => 'Warung Aming', 'dibuat_oleh' => 'Budi Santoso', 'dibuat_pada' => '2026-10-22 11:23:00'],
            ['id' => 11, 'kode' => 'OUT-11', 'nama' => 'Warung Aming 2', 'dibuat_oleh' => 'Budi Santoso', 'dibuat_pada' => '2026-10-22 11:25:00'],
            ['id' => 20, 'kode' => 'OUT-20', 'nama' => 'Toko Dua', 'dibuat_oleh' => null, 'dibuat_pada' => null],
        ]);
    }

    /** Koordinator bawaan (hak Tambah pada outlet_all). */
    private function koordinator(string $username = 'koor'): User
    {
        return $this->akun($username, 'koordinator', 137);
    }

    private function akunDenganHak(string $username, array $aksi, string $cakupan = 'semua'): User
    {
        $role = $this->role('Peran '.$username, $cakupan, ['outlet_all' => $aksi]);

        return $this->akun($username, 'koordinator', 137, ['role_id' => $role]);
    }

    /** Tanda sistem Perlu dicek untuk satu outlet (kelompok opsional). */
    private function tanda(int $outlet = 10, array $lain = []): int
    {
        return DB::table('tanda_outlet')->insertGetId($lain + [
            'outlet_id' => $outlet, 'kategori' => 'outlet_fiktif', 'catatan' => '2 outlet didaftarkan Budi Santoso di koordinat yang sama persis.',
            'status' => 'perlu_dicek', 'dibuat_oleh' => null, 'dibuat_nama' => 'Sistem', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function periksa(User $user, int $tanda, array $lain = [])
    {
        return $this->actingAs($user)->patchJson(self::URL."/{$tanda}/periksa", $lain + ['status' => 'terbukti', 'catatan' => 'Toko tidak ada di lokasi.']);
    }

    public function test_tidak_ada_lagi_endpoint_tanda_manual(): void
    {
        $koor = $this->koordinator();
        $tanda = $this->tanda();

        // Rute tambah/cabut sudah dihapus: tidak ada yang menjawab 2xx (405 bila path-nya masih dikenal, 404 selebihnya).
        foreach ([
            $this->actingAs($koor)->postJson(self::URL, ['outlet_id' => 10]),
            $this->actingAs($koor)->deleteJson(self::URL."/{$tanda}"),
        ] as $res) {
            $this->assertContains($res->status(), [404, 405]);
        }
    }

    public function test_role_bawaan_koordinator_boleh_memeriksa_tanda(): void
    {
        $this->assertTrue(Akses::bisa($this->koordinator(), 'outlet_all', 'tambah'));
    }

    public function test_daftar_memuat_tanda_aktif_pada_outlet_yang_diminta_dengan_jumlah_sekelompok(): void
    {
        $this->tanda(10, ['kelompok' => 'abc', 'skor' => 12]);
        $this->tanda(11, ['kelompok' => 'abc', 'skor' => 12]);
        $this->tanda(10, ['status' => 'wajar', 'dicabut_at' => now()]); // sudah diperiksa Wajar: tidak tampil
        $this->tanda(20);

        $res = $this->actingAs($this->koordinator())->getJson(self::URL.'?outlet_id=10')->assertOk();

        $res->assertJsonCount(1, 'tanda')->assertJsonPath('tanda.0.oleh', 'Sistem')->assertJsonPath('tanda.0.status_label', 'Perlu dicek')
            ->assertJsonPath('tanda.0.sekelompok', 1)->assertJsonPath('tanda.0.bisa_periksa', true)
            ->assertJsonPath('outlet.dibuat_oleh', 'Budi Santoso');
    }

    public function test_akun_hanya_lihat_bisa_melihat_tapi_tidak_memeriksa(): void
    {
        $lihat = $this->akunDenganHak('pemantau', ['lihat']);
        $tanda = $this->tanda();

        $this->actingAs($lihat)->getJson(self::URL.'?outlet_id=10')->assertOk()->assertJsonPath('tanda.0.bisa_periksa', false);
        $this->periksa($lihat, $tanda)->assertForbidden();
        $this->assertSame('perlu_dicek', DB::table('tanda_outlet')->find($tanda)->status);
    }

    public function test_akun_tanpa_hak_menu_ditolak(): void
    {
        $role = $this->role('Tanpa Outlet', 'semua', ['home' => ['lihat']]);
        $user = $this->akun('tanpa', 'koordinator', 137, ['role_id' => $role]);

        $this->actingAs($user)->getJson(self::URL.'?outlet_id=10')->assertForbidden();
        $this->periksa($user, $this->tanda())->assertForbidden();
    }

    public function test_outlet_yang_tidak_ada_di_master_ditolak(): void
    {
        $this->actingAs($this->koordinator())->getJson(self::URL.'?outlet_id=99999')->assertNotFound();
    }

    public function test_periksa_menerapkan_hasil_ke_seluruh_kelompok_dan_mencatat_riwayat_admin(): void
    {
        $a = $this->tanda(10, ['kelompok' => 'abc']);
        $b = $this->tanda(11, ['kelompok' => 'abc']);
        $lain = $this->tanda(20, ['kelompok' => 'xyz']);

        $this->periksa($this->koordinator(), $a, ['status' => 'wajar', 'catatan' => 'Kios berderet, asli.'])->assertOk()->assertJsonPath('diperiksa', 2);

        foreach ([$a, $b] as $id) {
            $baris = DB::table('tanda_outlet')->find($id);
            $this->assertSame(['wajar', 'Kios berderet, asli.', 'koor'], [$baris->status, $baris->hasil_catatan, $baris->dicek_nama]);
            $this->assertNotNull($baris->dicabut_at);
        }
        $this->assertSame('perlu_dicek', DB::table('tanda_outlet')->find($lain)->status);
        $this->assertSame(2, DB::table('riwayat_admin')->where('objek', 'tanda_outlet')->where('aksi', 'ubah')->count());
    }

    public function test_periksa_terbukti_tidak_mencabut_tanda(): void
    {
        $a = $this->tanda(10);

        $this->periksa($this->koordinator(), $a)->assertOk()->assertJsonPath('status', 'terbukti');

        $this->assertNull(DB::table('tanda_outlet')->find($a)->dicabut_at);
    }

    public function test_validasi_dan_tanda_yang_sudah_diperiksa_ditolak(): void
    {
        $koor = $this->koordinator();
        $tanda = $this->tanda();

        $this->periksa($koor, $tanda, ['status' => 'curang'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->periksa($koor, $tanda, ['catatan' => ''])->assertUnprocessable()->assertJsonValidationErrors('catatan');

        $this->periksa($koor, $tanda)->assertOk();
        $this->periksa($koor, $tanda)->assertNotFound();
        $this->periksa($koor, 99999)->assertNotFound();
    }

    public function test_hanya_pengguna_yang_login_yang_bisa_mengakses(): void
    {
        auth()->logout();

        $this->getJson(self::URL.'?outlet_id=10')->assertUnauthorized();
    }

    public function test_migration_memberi_hak_tambah_ke_role_bawaan_koordinator_saja(): void
    {
        DB::table('role_hak')->where('menu', 'outlet_all')->update(['tambah' => false]);
        $lain = $this->role('Pemantau Biasa', 'semua', ['outlet_all' => ['lihat']]);
        $tanpaMenu = $this->role('Tanpa Menu Outlet', 'semua', ['home' => ['lihat']]);

        (require database_path('migrations/2026_11_01_000005_beri_hak_tanda_outlet_koordinator.php'))->up();

        $this->assertTrue((bool) DB::table('role_hak')->where('role_id', $this->roleId('koordinator'))->where('menu', 'outlet_all')->value('tambah'));
        $this->assertFalse((bool) DB::table('role_hak')->where('role_id', $lain)->where('menu', 'outlet_all')->value('tambah'));
        $this->assertFalse(DB::table('role_hak')->where('role_id', $tanpaMenu)->where('menu', 'outlet_all')->exists());
        $this->assertFalse((bool) DB::table('role_hak')->where('role_id', $this->roleId('lainnya'))->where('menu', 'outlet_all')->value('tambah'));
    }
}
