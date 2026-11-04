<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Mobile\Pengaturan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Buat dan ubah Laporan Kendala (spec 064): data pelapor diambil dari akun login, ubah hanya oleh pelapor selama Terbuka.
class LaporanKendalaSimpanTest extends AdminTestCase
{
    private function data(array $lain = []): array
    {
        return $lain + [
            'kategori' => 'stok_habis',
            'tanggal_kejadian' => now()->toDateString(),
            'judul' => 'Stok canvassing habis di depo',
            'uraian' => 'Barang tidak tersedia sehingga motoris tidak bisa loading.',
        ];
    }

    private function koordinator(string $username = 'koor', int $karyawan = 137): User
    {
        return $this->akun($username, 'koordinator', $karyawan);
    }

    private function buat(User $pelapor, array $lain = []): int
    {
        $k = DB::table('mk_karyawan')->find($pelapor->karyawan_id);

        return DB::table('laporan_kendala')->insertGetId($lain + [
            'user_id' => $pelapor->id, 'karyawan_id' => $k->id, 'pelapor_nama' => $k->nama, 'depo_id' => $k->depo_id,
            'kategori' => 'lainnya', 'tanggal_kejadian' => '2026-11-03', 'judul' => 'Awal', 'uraian' => 'Uraian awal',
            'status' => 'terbuka', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_koordinator_membuat_laporan_terbuka_dengan_data_pelapor_dari_akun(): void
    {
        $koor = $this->koordinator();
        $this->actingAs($koor);

        $this->post('/laporan_kendala', $this->data())->assertRedirect()->assertSessionHasNoErrors();

        $l = DB::table('laporan_kendala')->first();
        $this->assertSame('terbuka', $l->status);
        $this->assertSame($koor->id, (int) $l->user_id);
        $this->assertSame(137, (int) $l->karyawan_id);
        $this->assertSame('Koor Satu', $l->pelapor_nama);
        $this->assertSame(46, (int) $l->depo_id);

        $judul = collect($this->inertiaProps($this->get('/laporan_kendala'), 'LaporanKendala/Index')['baris']['data'])->pluck('judul');
        $this->assertContains('Stok canvassing habis di depo', $judul->all());
    }

    public function test_validasi_per_field(): void
    {
        // 17:03 UTC = 00:03 WIB: tanggal "besok" menurut UTC sudah sama dengan hari ini menurut zona aplikasi, jadi
        // tanggal masa depan harus dihitung di zona aplikasi (test ini sempat gagal di CI pada jam itu).
        $this->travelTo(Carbon::parse('2026-11-04 17:03:00', 'UTC'));
        $this->actingAs($this->koordinator());

        $this->post('/laporan_kendala', $this->data(['kategori' => 'ngawur', 'tanggal_kejadian' => Carbon::now(Pengaturan::zona())->addDay()->toDateString(), 'judul' => str_repeat('a', 121), 'uraian' => '']))
            ->assertSessionHasErrors(['kategori', 'tanggal_kejadian', 'judul', 'uraian']);

        $this->assertSame(0, DB::table('laporan_kendala')->count());
    }

    public function test_super_admin_tidak_boleh_membuat_laporan(): void
    {
        $this->post('/laporan_kendala', $this->data())->assertForbidden();

        $this->assertSame(0, DB::table('laporan_kendala')->count());
    }

    public function test_akun_tanpa_karyawan_ditolak_dengan_pesan(): void
    {
        $role = $this->role('Pelapor', 'semua', ['laporan_kendala' => ['lihat', 'tambah']]);
        $this->actingAs($this->akun('tanpakar', 'lainnya', null, ['role_id' => $role]));

        $this->post('/laporan_kendala', $this->data())->assertSessionHasErrors(['karyawan']);

        $this->assertSame(0, DB::table('laporan_kendala')->count());
    }

    public function test_tanpa_hak_tambah_ditolak(): void
    {
        $role = $this->role('Pembaca', 'semua', ['laporan_kendala' => ['lihat']]);
        $this->actingAs($this->akun('baca', 'lainnya', 500, ['role_id' => $role]));

        $this->post('/laporan_kendala', $this->data())->assertForbidden();
    }

    public function test_pelapor_mengubah_laporan_terbukanya(): void
    {
        $koor = $this->koordinator();
        $id = $this->buat($koor);
        $this->actingAs($koor);

        $this->put("/laporan_kendala/{$id}", $this->data(['judul' => 'Judul baru', 'kategori' => 'kendaraan']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $l = DB::table('laporan_kendala')->find($id);
        $this->assertSame('Judul baru', $l->judul);
        $this->assertSame('kendaraan', $l->kategori);
        $this->assertSame('terbuka', $l->status);
    }

    public function test_tidak_bisa_mengubah_laporan_orang_lain(): void
    {
        $id = $this->buat($this->koordinator('koor'));
        DB::table('mk_karyawan')->insert(['id' => 138, 'kategori' => 'koordinator', 'nama' => 'Koor Dua', 'depo_id' => 22, 'koordinator_id' => null, 'aktif' => true]);
        $this->actingAs($this->koordinator('koor2', 138));

        $this->put("/laporan_kendala/{$id}", $this->data(['judul' => 'Dibajak']))->assertForbidden();

        $this->assertSame('Awal', DB::table('laporan_kendala')->find($id)->judul);
    }

    public function test_super_admin_pun_tidak_mengubah_laporan_orang_lain(): void
    {
        $id = $this->buat($this->koordinator());

        $this->put("/laporan_kendala/{$id}", $this->data(['judul' => 'Dibajak']))->assertForbidden();
    }

    public function test_laporan_selesai_tidak_bisa_diubah(): void
    {
        $koor = $this->koordinator();
        $id = $this->buat($koor, ['status' => 'selesai', 'catatan_selesai' => 'Beres']);
        $this->actingAs($koor);

        $this->put("/laporan_kendala/{$id}", $this->data(['judul' => 'Ubah lagi']))->assertStatus(422);

        $this->assertSame('Awal', DB::table('laporan_kendala')->find($id)->judul);
    }

    public function test_tanpa_hak_ubah_ditolak(): void
    {
        $role = $this->role('Tambah saja', 'downline', ['laporan_kendala' => ['lihat', 'tambah']]);
        $koor = $this->akun('koor', 'koordinator', 137, ['role_id' => $role]);
        $id = $this->buat($koor);
        $this->actingAs($koor);

        $this->put("/laporan_kendala/{$id}", $this->data())->assertForbidden();
    }

    public function test_daftar_menandai_laporan_yang_bisa_diubah(): void
    {
        $koor = $this->koordinator();
        $milik = $this->buat($koor, ['judul' => 'Milik sendiri']);
        $selesai = $this->buat($koor, ['judul' => 'Sudah selesai', 'status' => 'selesai']);
        DB::table('mk_karyawan')->where('id', 1)->update(['koordinator_id' => 137]);
        $lain = $this->buat($this->akun('koor3', 'koordinator', 1), ['judul' => 'Milik downline']);
        $this->actingAs($koor);

        $bisa = collect($this->inertiaProps($this->get('/laporan_kendala?status=semua'), 'LaporanKendala/Index')['baris']['data'])
            ->pluck('bisa_ubah', 'id');

        $this->assertTrue($bisa[$milik]);
        $this->assertFalse($bisa[$selesai]);
        $this->assertFalse($bisa[$lain]);
    }

    public function test_pelapor_menutup_laporan_dengan_catatan(): void
    {
        $koor = $this->koordinator();
        $id = $this->buat($koor);
        $this->actingAs($koor);

        $this->patch("/laporan_kendala/{$id}/selesai", ['catatan_selesai' => 'Stok sudah datang.'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $l = DB::table('laporan_kendala')->find($id);
        $this->assertSame('selesai', $l->status);
        $this->assertSame('Stok sudah datang.', $l->catatan_selesai);
        $this->assertNotNull($l->selesai_at);
    }

    public function test_menutup_tanpa_catatan_ditolak(): void
    {
        $koor = $this->koordinator();
        $id = $this->buat($koor);
        $this->actingAs($koor);

        $this->patch("/laporan_kendala/{$id}/selesai", ['catatan_selesai' => '  '])->assertSessionHasErrors('catatan_selesai');

        $this->assertSame('terbuka', DB::table('laporan_kendala')->find($id)->status);
    }

    public function test_bukan_pelapor_tidak_bisa_menutup(): void
    {
        $id = $this->buat($this->koordinator());

        // Super Admin (bukan pelapor) pun ditolak.
        $this->patch("/laporan_kendala/{$id}/selesai", ['catatan_selesai' => 'Beres'])->assertForbidden();

        $this->assertSame('terbuka', DB::table('laporan_kendala')->find($id)->status);
    }

    public function test_laporan_selesai_tidak_bisa_ditutup_ulang(): void
    {
        $koor = $this->koordinator();
        $id = $this->buat($koor, ['status' => 'selesai', 'catatan_selesai' => 'Catatan lama']);
        $this->actingAs($koor);

        $this->patch("/laporan_kendala/{$id}/selesai", ['catatan_selesai' => 'Catatan baru'])->assertStatus(422);

        $this->assertSame('Catatan lama', DB::table('laporan_kendala')->find($id)->catatan_selesai);
    }

    public function test_tanpa_hak_ubah_tidak_bisa_menutup(): void
    {
        $role = $this->role('Tambah saja', 'downline', ['laporan_kendala' => ['lihat', 'tambah']]);
        $koor = $this->akun('koor', 'koordinator', 137, ['role_id' => $role]);
        $id = $this->buat($koor);
        $this->actingAs($koor);

        $this->patch("/laporan_kendala/{$id}/selesai", ['catatan_selesai' => 'Beres'])->assertForbidden();
    }

    public function test_daftar_memuat_isi_detail_dan_catatan_selesai(): void
    {
        $koor = $this->koordinator();
        $id = $this->buat($koor, ['status' => 'selesai', 'catatan_selesai' => 'Sudah beres', 'selesai_at' => now()]);
        $this->actingAs($koor);

        $baris = collect($this->inertiaProps($this->get('/laporan_kendala?status=semua'), 'LaporanKendala/Index')['baris']['data'])->firstWhere('id', $id);

        $this->assertSame('Sudah beres', $baris['catatan_selesai']);
        $this->assertNotNull($baris['selesai']);
        $this->assertFalse($baris['bisa_ubah']);
    }
}
