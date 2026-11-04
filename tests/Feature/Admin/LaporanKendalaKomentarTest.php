<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

// Diskusi Laporan Kendala (spec 065): kirim komentar, cakupan, validasi, dan linimasa di detail.
class LaporanKendalaKomentarTest extends AdminTestCase
{
    private User $koor;

    private User $spv;

    private int $laporan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->koor = $this->akun('koor', 'koordinator', 137);
        $role = $this->role('Supervisor', 'semua', ['laporan_kendala' => ['lihat']]);
        $this->spv = $this->akun('spv', 'lainnya', 500, ['role_id' => $role]);

        $this->laporan = DB::table('laporan_kendala')->insertGetId([
            'user_id' => $this->koor->id, 'karyawan_id' => 137, 'pelapor_nama' => 'Koor Satu', 'depo_id' => 46,
            'kategori' => 'stok_habis', 'tanggal_kejadian' => '2026-11-03', 'judul' => 'Stok habis', 'uraian' => 'Uraian',
            'status' => 'terbuka', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(), 'aktivitas_at' => now()->subHour(),
        ]);
    }

    private function kirim(string $isi): TestResponse
    {
        return $this->postJson("/laporan_kendala/{$this->laporan}/komentar", ['isi' => $isi]);
    }

    public function test_supervisor_dengan_hak_lihat_saja_bisa_membalas(): void
    {
        $this->actingAs($this->spv);

        $this->kirim("Kapan stok datang?\nTolong kabari.")->assertCreated()
            ->assertJsonPath('komentar.0.penulis', 'Spv')
            ->assertJsonPath('komentar.0.isi', "Kapan stok datang?\nTolong kabari.");

        $this->assertSame(1, DB::table('laporan_kendala_komentar')->where('laporan_kendala_id', $this->laporan)->count());
        $this->assertTrue(now()->subMinute()->lessThan(DB::table('laporan_kendala')->value('aktivitas_at')));
    }

    public function test_pelapor_bisa_berkomentar_di_laporannya_sendiri(): void
    {
        $this->actingAs($this->koor);

        $this->kirim('Stok masuk besok.')->assertCreated();
    }

    public function test_komentar_boleh_pada_laporan_selesai_tanpa_mengubah_status(): void
    {
        DB::table('laporan_kendala')->where('id', $this->laporan)->update(['status' => 'selesai', 'catatan_selesai' => 'Beres']);
        $this->actingAs($this->spv);

        $this->kirim('Terima kasih.')->assertCreated();

        $this->assertSame('selesai', DB::table('laporan_kendala')->value('status'));
    }

    public function test_komentar_kosong_atau_terlalu_panjang_ditolak(): void
    {
        $this->actingAs($this->spv);

        $this->kirim('   ')->assertUnprocessable()->assertJsonValidationErrors('isi');
        $this->kirim(str_repeat('a', 2001))->assertUnprocessable()->assertJsonValidationErrors('isi');

        $this->assertSame(0, DB::table('laporan_kendala_komentar')->count());
    }

    public function test_laporan_di_luar_cakupan_ditolak(): void
    {
        DB::table('mk_karyawan')->insert(['id' => 138, 'kategori' => 'koordinator', 'nama' => 'Koor Dua', 'depo_id' => 22, 'koordinator_id' => null, 'aktif' => true]);
        $this->actingAs($this->akun('koor2', 'koordinator', 138));

        $this->kirim('Halo')->assertNotFound();

        $this->assertSame(0, DB::table('laporan_kendala_komentar')->count());
    }

    public function test_tanpa_hak_lihat_ditolak(): void
    {
        $role = $this->role('Tanpa Kendala', 'semua', ['home' => ['lihat']]);
        $this->actingAs($this->akun('biasa', 'lainnya', 500, ['role_id' => $role]));

        $this->kirim('Halo')->assertForbidden();
    }

    public function test_membuka_detail_memuat_linimasa_urut_waktu(): void
    {
        DB::table('laporan_kendala_komentar')->insert([
            ['laporan_kendala_id' => $this->laporan, 'user_id' => $this->koor->id, 'penulis_nama' => 'Koor Satu', 'jenis' => 'komentar', 'isi' => 'Kedua', 'created_at' => now()->subMinutes(5), 'updated_at' => now()],
            ['laporan_kendala_id' => $this->laporan, 'user_id' => $this->spv->id, 'penulis_nama' => 'Spv', 'jenis' => 'komentar', 'isi' => 'Pertama', 'created_at' => now()->subMinutes(30), 'updated_at' => now()],
        ]);
        $this->actingAs($this->spv);

        $komentar = $this->postJson("/laporan_kendala/{$this->laporan}/dibaca")->assertOk()->json('komentar');

        $this->assertSame(['Pertama', 'Kedua'], array_column($komentar, 'isi'));
    }

    private function komentarSpv(string $jenis = 'komentar'): int
    {
        return DB::table('laporan_kendala_komentar')->insertGetId([
            'laporan_kendala_id' => $this->laporan, 'user_id' => $this->spv->id, 'penulis_nama' => 'Spv', 'jenis' => $jenis,
            'isi' => 'Awal', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_penulis_menyunting_komentarnya_dan_ditandai_disunting(): void
    {
        $id = $this->komentarSpv();
        $this->actingAs($this->spv);

        $this->putJson("/laporan_kendala/{$this->laporan}/komentar/{$id}", ['isi' => 'Revisi'])->assertOk()
            ->assertJsonPath('komentar.0.isi', 'Revisi')
            ->assertJsonPath('komentar.0.disunting', true)
            ->assertJsonPath('komentar.0.milik_sendiri', true);
    }

    public function test_komentar_orang_lain_tidak_bisa_disunting_atau_dihapus(): void
    {
        $id = $this->komentarSpv();
        $this->actingAs($this->koor);

        $this->putJson("/laporan_kendala/{$this->laporan}/komentar/{$id}", ['isi' => 'Bajak'])->assertForbidden();
        $this->deleteJson("/laporan_kendala/{$this->laporan}/komentar/{$id}")->assertForbidden();

        $this->assertSame('Awal', DB::table('laporan_kendala_komentar')->value('isi'));
        $this->assertFalse($this->postJson("/laporan_kendala/{$this->laporan}/dibaca")->json('komentar.0.milik_sendiri'));
    }

    public function test_peristiwa_otomatis_tidak_bisa_disunting_atau_dihapus(): void
    {
        $id = $this->komentarSpv('selesai');
        $this->actingAs($this->spv);

        $this->putJson("/laporan_kendala/{$this->laporan}/komentar/{$id}", ['isi' => 'Ubah'])->assertForbidden();
        $this->deleteJson("/laporan_kendala/{$this->laporan}/komentar/{$id}")->assertForbidden();
    }

    public function test_penulis_menghapus_komentarnya(): void
    {
        $id = $this->komentarSpv();
        $this->actingAs($this->spv);

        $this->deleteJson("/laporan_kendala/{$this->laporan}/komentar/{$id}")->assertOk()->assertJson(['komentar' => []]);

        $this->assertSame(0, DB::table('laporan_kendala_komentar')->count());
    }

    public function test_sunting_kosong_ditolak(): void
    {
        $id = $this->komentarSpv();
        $this->actingAs($this->spv);

        $this->putJson("/laporan_kendala/{$this->laporan}/komentar/{$id}", ['isi' => ''])->assertUnprocessable();
    }
}
