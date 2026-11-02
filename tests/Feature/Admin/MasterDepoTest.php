<?php

namespace Tests\Feature\Admin;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

// Form "Tambah Warehouse / Depo" (MasterDepoController), meniru /master_depo/add di Marketama: simpan ke
// mk_depo (lihat App\Support\Master\MasterDepo), terpisah dari daftar "master_depo" (impor CSV lama).
class MasterDepoTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('provinsi')->insert(['nama_provinsi' => 'Jawa Barat']);
        DB::table('kota_kabupaten')->insert(['provinsi' => 'Jawa Barat', 'nama_kota_kabupaten' => 'Kota Bekasi']);
        DB::table('kecamatan')->insert(['provinsi' => 'Jawa Barat', 'kota_kabupaten' => 'Kota Bekasi', 'nama_kecamatan' => 'Medan Satria']);
        DB::table('kelurahan')->insert(['provinsi' => 'Jawa Barat', 'kota_kabupaten' => 'Kota Bekasi', 'kecamatan' => 'Medan Satria', 'nama_kelurahan' => 'Medan Satria']);
        DB::table('master_project')->insert(['nama_project' => 'Enesis']);
        DB::table('city_group')->insert(['nama_city_group' => 'Jakarta']);
    }

    public function test_halaman_tambah_memuat_provinsi_dan_opsi(): void
    {
        $this->get('/master_depo/tambah')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('MasterDepo/Tambah')
            ->where('provinsiList', ['Jawa Barat'])
            ->where('bisaTambah', true)
            ->where('field', fn ($f) => collect($f)->firstWhere('nama', 'project_id')['opsi'][0]['label'] === 'Enesis'));
    }

    public function test_dropdown_wilayah_berjenjang(): void
    {
        $this->getJson('/master_depo/tambah/wilayah/kota?provinsi=Jawa Barat')->assertOk()->assertJson(['Kota Bekasi']);
        $this->getJson('/master_depo/tambah/wilayah/kecamatan?provinsi=Jawa Barat&kota_kabupaten=Kota Bekasi')->assertOk()->assertJson(['Medan Satria']);
        $this->getJson('/master_depo/tambah/wilayah/kelurahan?provinsi=Jawa Barat&kota_kabupaten=Kota Bekasi&kecamatan=Medan Satria')->assertOk()->assertJson(['Medan Satria']);
        // Provinsi belum dipilih: daftar kosong, bukan error.
        $this->getJson('/master_depo/tambah/wilayah/kota')->assertOk()->assertJson([]);
    }

    private function dataDasar(): array
    {
        return [
            'kode' => 'DEPO-BARU', 'nama' => 'Depo Bekasi Baru',
            'provinsi' => 'Jawa Barat', 'kota_kabupaten' => 'Kota Bekasi', 'kecamatan' => 'Medan Satria', 'kelurahan_desa' => 'Medan Satria',
            'alamat' => 'Jl. Raya Siliwangi No. 1',
            'latitude' => '-6.2200210', 'longitude' => '106.8151910',
            'foto' => UploadedFile::fake()->image('depo.jpg', 300, 300),
            'target_call' => '45', 'target_ec' => '35', 'minimal_value_transaksi' => '35.000', 'insentif_per_poin' => '250',
        ];
    }

    public function test_tambah_depo_tersimpan_ke_mk_depo(): void
    {
        Storage::fake('local');

        $this->post('/master_depo/tambah', $this->dataDasar())->assertRedirect(route('master-depo.tambah'));

        $depo = DB::table('mk_depo')->where('kode', 'DEPO-BARU')->first();
        $this->assertNotNull($depo);
        $this->assertSame(47, (int) $depo->id); // melanjutkan id 46 yang sudah ada di AdminTestCase
        $this->assertSame('Depo Bekasi Baru', $depo->nama);
        $this->assertSame('Kota Bekasi', $depo->kota_kabupaten);
        $this->assertSame(45, (int) $depo->target_call);
        $this->assertSame(35, (int) $depo->target_ec);
        $this->assertSame(35000.0, (float) $depo->minimal_value_transaksi);
        $this->assertTrue((bool) $depo->aktif); // default aktif saat status tidak dikirim
        $this->assertFalse((bool) $depo->multi_polygon_sales);
        $this->assertSame('admin', $depo->diubah_oleh);
        Storage::disk('local')->assertExists($depo->foto);
        $this->get('/master_depo/baru/'.$depo->id.'/foto')->assertOk();

        $this->assertDatabaseHas('riwayat_admin', ['objek' => 'master_depo', 'objek_id' => $depo->id, 'aksi' => 'dibuat']);

        // Muncul di kartu "Depo terbaru" halaman Tambah.
        $this->get('/master_depo/tambah')->assertInertia(fn (Assert $page) => $page
            ->where('terbaru.0.nama', 'Depo Bekasi Baru')->where('terbaru.0.target_call', 45));
    }

    public function test_validasi_field_wajib_dan_kode_unik(): void
    {
        $this->post('/master_depo/tambah', [])->assertSessionHasErrors(['kode', 'nama', 'provinsi', 'kota_kabupaten', 'kecamatan', 'kelurahan_desa', 'alamat', 'latitude', 'longitude', 'foto']);

        DB::table('mk_depo')->where('id', 46)->update(['kode' => 'DEPO-LAMA']);
        $this->post('/master_depo/tambah', array_merge($this->dataDasar(), ['kode' => 'DEPO-LAMA']))->assertSessionHasErrors('kode');
    }

    public function test_akun_tanpa_hak_tambah_ditolak(): void
    {
        // Rute /tambah (GET & POST) sama-sama butuh hak "tambah" (lihat CekHak), konsisten dengan master-produk.
        $roleId = $this->role('Lihat Depo Saja', 'semua', ['master_depo' => ['lihat']]);
        $akun = $this->akun('viewer', 'lainnya', 500, ['role_id' => $roleId]);
        $this->actingAs($akun);

        $this->get('/master_depo/tambah')->assertForbidden();
        $this->post('/master_depo/tambah', $this->dataDasar())->assertForbidden();
    }
}
