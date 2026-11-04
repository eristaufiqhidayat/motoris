<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Kendala\LaporanKendala;
use Illuminate\Support\Facades\DB;

// Lencana "Ada balasan baru" Laporan Kendala (spec 065): daftar, filter, kolom Diskusi, angka sidebar dan kartu Beranda.
class LaporanKendalaBalasanBaruTest extends AdminTestCase
{
    private User $koor;

    private User $spv;

    private int $laporan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->koor = $this->akun('koor', 'koordinator', 137);
        $role = $this->role('Supervisor', 'semua', ['laporan_kendala' => ['lihat'], 'home' => ['lihat']]);
        $this->spv = $this->akun('spv', 'lainnya', 500, ['role_id' => $role]);

        $this->laporan = DB::table('laporan_kendala')->insertGetId([
            'user_id' => $this->koor->id, 'karyawan_id' => 137, 'pelapor_nama' => 'Koor Satu', 'depo_id' => 46,
            'kategori' => 'stok_habis', 'tanggal_kejadian' => '2026-11-03', 'judul' => 'Stok habis', 'uraian' => 'Uraian',
            'status' => 'terbuka', 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2),
            'aktivitas_at' => now()->subHours(2), 'aktivitas_oleh' => $this->koor->id,
        ]);
    }

    private function baris(User $user, string $query = ''): array
    {
        $this->actingAs($user);

        return collect($this->inertiaProps($this->get('/laporan_kendala?status=semua'.$query), 'LaporanKendala/Index')['baris']['data'])
            ->firstWhere('id', $this->laporan) ?? [];
    }

    // Waktu dimajukan sebelum setiap aksi supaya urutan aktivitas dan waktu baca tidak sama detik.
    private function buka(User $user): void
    {
        $this->travel(2)->seconds();
        $this->actingAs($user);
        $this->postJson("/laporan_kendala/{$this->laporan}/dibaca")->assertOk();
    }

    private function balas(User $user, string $isi = 'Balasan'): void
    {
        $this->travel(2)->seconds();
        $this->actingAs($user);
        $this->postJson("/laporan_kendala/{$this->laporan}/komentar", ['isi' => $isi])->assertCreated();
    }

    public function test_pelapor_melihat_lencana_setelah_supervisor_membalas_dan_hilang_setelah_dibuka(): void
    {
        $this->assertFalse($this->baris($this->koor)['ada_balasan_baru']);

        $this->balas($this->spv);

        $this->assertTrue($this->baris($this->koor)['ada_balasan_baru']);

        $this->buka($this->koor);

        $this->assertFalse($this->baris($this->koor)['ada_balasan_baru']);
    }

    public function test_penulis_komentar_tidak_melihat_lencana_untuk_komentarnya_sendiri(): void
    {
        $this->buka($this->spv);
        $this->balas($this->spv);

        $this->assertFalse($this->baris($this->spv)['ada_balasan_baru']);
    }

    public function test_supervisor_yang_sudah_membaca_melihat_lencana_saat_pelapor_membalas(): void
    {
        $this->buka($this->spv);
        $this->assertFalse($this->baris($this->spv)['ada_balasan_baru']);

        $this->balas($this->koor, 'Stok masuk besok.');

        $this->assertTrue($this->baris($this->spv)['ada_balasan_baru']);
        $this->assertFalse($this->baris($this->spv)['belum_dibaca']);
    }

    public function test_supervisor_yang_belum_pernah_membuka_hanya_dapat_belum_dibaca(): void
    {
        $this->balas($this->koor);

        $baris = $this->baris($this->spv);
        $this->assertTrue($baris['belum_dibaca']);
        $this->assertFalse($baris['ada_balasan_baru']);
    }

    public function test_pelapor_mengubah_laporan_memberi_lencana_ke_pembaca(): void
    {
        $this->buka($this->spv);

        $this->travel(2)->seconds();
        $this->actingAs($this->koor);
        $this->put("/laporan_kendala/{$this->laporan}", [
            'kategori' => 'stok_habis', 'tanggal_kejadian' => now()->toDateString(), 'judul' => 'Stok habis', 'uraian' => 'Uraian baru',
        ])->assertRedirect();

        $this->assertTrue($this->baris($this->spv)['ada_balasan_baru']);
        $this->assertFalse($this->baris($this->koor)['ada_balasan_baru']);
    }

    public function test_lencana_juga_berlaku_pada_laporan_selesai(): void
    {
        DB::table('laporan_kendala')->where('id', $this->laporan)->update(['status' => 'selesai', 'catatan_selesai' => 'Beres']);
        $this->balas($this->spv, 'Terima kasih.');

        $this->assertTrue($this->baris($this->koor)['ada_balasan_baru']);
    }

    public function test_laporan_lama_tanpa_aktivitas_oleh_tidak_memberi_lencana(): void
    {
        DB::table('laporan_kendala')->where('id', $this->laporan)->update(['aktivitas_oleh' => null]);

        $this->assertFalse($this->baris($this->koor)['ada_balasan_baru']);
    }

    public function test_pelapor_tidak_tampil_di_daftar_pembaca_tetapi_dibaca_tercatat(): void
    {
        $this->buka($this->koor);
        $this->buka($this->spv);

        $pembaca = $this->postJson("/laporan_kendala/{$this->laporan}/dibaca")->json('pembaca');
        $this->assertSame([], $pembaca);

        $this->actingAs($this->koor);
        $pembaca = $this->postJson("/laporan_kendala/{$this->laporan}/dibaca")->json('pembaca');
        $this->assertSame(['Spv'], array_column($pembaca, 'nama'));
    }

    public function test_kolom_diskusi_menghitung_komentar_saja(): void
    {
        $this->balas($this->spv);
        $this->balas($this->koor);
        $this->travel(2)->seconds();
        $this->actingAs($this->koor);
        $this->patch("/laporan_kendala/{$this->laporan}/selesai", ['catatan_selesai' => 'Beres']);

        $this->assertSame(2, $this->baris($this->koor)['jumlah_komentar']);
    }

    public function test_filter_ada_balasan_baru(): void
    {
        $lain = DB::table('laporan_kendala')->insertGetId([
            'user_id' => $this->koor->id, 'karyawan_id' => 137, 'pelapor_nama' => 'Koor Satu', 'depo_id' => 46,
            'kategori' => 'lainnya', 'tanggal_kejadian' => '2026-11-03', 'judul' => 'Tanpa balasan', 'uraian' => 'U',
            'status' => 'terbuka', 'created_at' => now(), 'updated_at' => now(), 'aktivitas_at' => now(), 'aktivitas_oleh' => $this->koor->id,
        ]);
        $this->balas($this->spv);

        $this->actingAs($this->koor);
        $ids = array_column($this->inertiaProps($this->get('/laporan_kendala?status=semua&ada_balasan=1'), 'LaporanKendala/Index')['baris']['data'], 'id');

        $this->assertSame([$this->laporan], $ids);
        $this->assertNotContains($lain, $ids);
    }

    public function test_angka_sidebar_menjumlah_belum_dibaca_dan_ada_balasan_baru(): void
    {
        // Supervisor: laporan belum dibuka (1 belum dibaca) + laporan sudah dibuka lalu dibalas pelapor (1 balasan baru).
        $kedua = DB::table('laporan_kendala')->insertGetId([
            'user_id' => $this->koor->id, 'karyawan_id' => 137, 'pelapor_nama' => 'Koor Satu', 'depo_id' => 46,
            'kategori' => 'lainnya', 'tanggal_kejadian' => '2026-11-03', 'judul' => 'Kedua', 'uraian' => 'U',
            'status' => 'terbuka', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
            'aktivitas_at' => now()->subHour(), 'aktivitas_oleh' => $this->koor->id,
        ]);
        $this->buka($this->spv);
        $this->balas($this->koor);
        $this->assertNotSame($kedua, $this->laporan);

        $this->actingAs($this->spv);
        $this->assertSame(2, $this->get('/home')->viewData('page')['props']['kendalaBelumDibaca']);
    }

    public function test_koordinator_mendapat_angka_sidebar_dari_balasan_atasan(): void
    {
        $this->actingAs($this->koor);
        $this->assertNull($this->get('/home')->viewData('page')['props']['kendalaBelumDibaca']);

        $this->balas($this->spv);

        $this->actingAs($this->koor);
        $this->assertSame(1, $this->get('/home')->viewData('page')['props']['kendalaBelumDibaca']);
    }

    public function test_kartu_beranda_memuat_jumlah_ada_balasan_baru(): void
    {
        $this->balas($this->spv);

        $this->assertSame(1, LaporanKendala::ringkasan($this->koor)['ada_balasan']);
    }
}
