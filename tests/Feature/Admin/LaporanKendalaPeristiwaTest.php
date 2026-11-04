<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Peristiwa linimasa Laporan Kendala (spec 065): Selesai dan Buka Kembali tercatat sebagai peristiwa diskusi.
class LaporanKendalaPeristiwaTest extends AdminTestCase
{
    private User $koor;

    private User $spv;

    private int $laporan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->koor = $this->akun('koor', 'koordinator', 137);
        $role = $this->role('Supervisor', 'semua', ['laporan_kendala' => ['lihat', 'ubah']]);
        $this->spv = $this->akun('spv', 'lainnya', 500, ['role_id' => $role]);

        $this->laporan = DB::table('laporan_kendala')->insertGetId([
            'user_id' => $this->koor->id, 'karyawan_id' => 137, 'pelapor_nama' => 'Koor Satu', 'depo_id' => 46,
            'kategori' => 'stok_habis', 'tanggal_kejadian' => '2026-11-03', 'judul' => 'Stok habis', 'uraian' => 'Uraian',
            'status' => 'terbuka', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(), 'aktivitas_at' => now()->subHour(),
        ]);
    }

    private function linimasa(): array
    {
        return $this->postJson("/laporan_kendala/{$this->laporan}/dibaca")->assertOk()->json('komentar');
    }

    public function test_selesai_menulis_peristiwa_dengan_catatan_penyelesaian(): void
    {
        $this->actingAs($this->koor);

        $this->patch("/laporan_kendala/{$this->laporan}/selesai", ['catatan_selesai' => 'Stok sudah datang.'])->assertRedirect();

        $peristiwa = $this->linimasa()[0];
        $this->assertSame('selesai', $peristiwa['jenis']);
        $this->assertSame('Stok sudah datang.', $peristiwa['isi']);
        $this->assertSame('Koor', $peristiwa['penulis']);
        $this->assertFalse($peristiwa['milik_sendiri']);
        $this->assertSame('Stok sudah datang.', DB::table('laporan_kendala')->value('catatan_selesai'));
    }

    public function test_pelapor_membuka_kembali_laporan_selesai(): void
    {
        $this->actingAs($this->koor);
        $this->patch("/laporan_kendala/{$this->laporan}/selesai", ['catatan_selesai' => 'Beres.']);

        $this->patch("/laporan_kendala/{$this->laporan}/buka-kembali", ['alasan' => 'Stok habis lagi.'])->assertRedirect();

        $l = DB::table('laporan_kendala')->find($this->laporan);
        $this->assertSame('terbuka', $l->status);
        $this->assertNull($l->selesai_at);
        $this->assertNull($l->catatan_selesai);

        $jenis = array_column($this->linimasa(), 'jenis');
        $this->assertSame(['selesai', 'dibuka_kembali'], $jenis);
        $this->assertSame('Stok habis lagi.', $this->linimasa()[1]['isi']);
    }

    public function test_catatan_lama_tetap_terlihat_dan_bisa_selesai_lagi(): void
    {
        $this->actingAs($this->koor);
        $this->patch("/laporan_kendala/{$this->laporan}/selesai", ['catatan_selesai' => 'Catatan pertama']);
        $this->patch("/laporan_kendala/{$this->laporan}/buka-kembali");
        $this->patch("/laporan_kendala/{$this->laporan}/selesai", ['catatan_selesai' => 'Catatan kedua'])->assertRedirect();

        $linimasa = $this->linimasa();
        $this->assertSame(['selesai', 'dibuka_kembali', 'selesai'], array_column($linimasa, 'jenis'));
        $this->assertSame('Catatan pertama', $linimasa[0]['isi']);
        $this->assertNull($linimasa[1]['isi']);
        $this->assertSame('Catatan kedua', DB::table('laporan_kendala')->value('catatan_selesai'));
    }

    public function test_buka_kembali_hanya_untuk_pelapor(): void
    {
        DB::table('laporan_kendala')->where('id', $this->laporan)->update(['status' => 'selesai', 'catatan_selesai' => 'Beres']);
        $this->actingAs($this->spv);

        $this->patch("/laporan_kendala/{$this->laporan}/buka-kembali")->assertForbidden();

        $this->assertSame('selesai', DB::table('laporan_kendala')->value('status'));
    }

    public function test_buka_kembali_hanya_dari_selesai(): void
    {
        $this->actingAs($this->koor);

        $this->patch("/laporan_kendala/{$this->laporan}/buka-kembali")->assertStatus(422);

        $this->assertSame(0, DB::table('laporan_kendala_komentar')->count());
    }

    public function test_buka_kembali_menaikkan_hitungan_terbuka_di_daftar_dan_aktivitas(): void
    {
        DB::table('laporan_kendala')->where('id', $this->laporan)->update(['status' => 'selesai', 'catatan_selesai' => 'Beres', 'selesai_at' => now()]);
        $this->actingAs($this->koor);

        $this->patch("/laporan_kendala/{$this->laporan}/buka-kembali")->assertRedirect();

        $baris = $this->inertiaProps($this->get('/laporan_kendala'), 'LaporanKendala/Index')['baris']['data'];
        $this->assertSame([$this->laporan], array_column($baris, 'id'));
        $this->assertTrue($baris[0]['bisa_ubah']);
        $this->assertTrue(now()->subMinute()->lessThan(DB::table('laporan_kendala')->value('aktivitas_at')));
    }

    public function test_daftar_menandai_laporan_selesai_milik_sendiri_bisa_dibuka_kembali(): void
    {
        DB::table('laporan_kendala')->where('id', $this->laporan)->update(['status' => 'selesai', 'catatan_selesai' => 'Beres']);

        $this->actingAs($this->koor);
        $baris = $this->inertiaProps($this->get('/laporan_kendala?status=selesai'), 'LaporanKendala/Index')['baris']['data'][0];
        $this->assertTrue($baris['bisa_buka_kembali']);
        $this->assertFalse($baris['bisa_ubah']);

        $this->actingAs($this->spv);
        $baris = $this->inertiaProps($this->get('/laporan_kendala?status=selesai'), 'LaporanKendala/Index')['baris']['data'][0];
        $this->assertFalse($baris['bisa_buka_kembali']);
    }

    public function test_tanpa_hak_ubah_tidak_bisa_membuka_kembali(): void
    {
        DB::table('laporan_kendala')->where('id', $this->laporan)->update(['status' => 'selesai', 'catatan_selesai' => 'Beres']);
        $role = $this->role('Lihat Saja', 'semua', ['laporan_kendala' => ['lihat']]);
        DB::table('users')->where('id', $this->koor->id)->update(['role_id' => $role]);
        $this->actingAs($this->koor->fresh());

        $this->patch("/laporan_kendala/{$this->laporan}/buka-kembali")->assertForbidden();
    }
}
