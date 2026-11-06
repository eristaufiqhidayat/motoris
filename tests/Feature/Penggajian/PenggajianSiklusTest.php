<?php

namespace Tests\Feature\Penggajian;

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\AdminTestCase;

// Siklus draf gaji sales: hari izin, komponen manual, hitung ulang, kekurangan data, finalisasi, dan pembatalan.
// Depo 46: Rudi (1, gaji lengkap) dan Tegar (3, gaji pokok 2,5 juta). Periode 20 Okt–19 Nov 2026 (27 hari kerja).
class PenggajianSiklusTest extends AdminTestCase
{
    private int $id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-06 10:00:00');

        DB::table('mk_karyawan')->where('id', 1)->update(['gaji_pokok' => 2700000, 'tunjangan_internet' => 100000, 'tunjangan_jabatan' => 200000, 'tunjangan_bbm_makan' => 540000, 'sewa_motor' => 270000]);
        DB::table('mk_karyawan')->where('id', 3)->update(['gaji_pokok' => 2500000]);
        DB::table('parameter_pemotong_gaji')->insert([['id' => 4, 'nama' => 'Potongan Pinjaman/Kasbon'], ['id' => 5, 'nama' => 'Potongan BPJS Kesehatan']]);
    }

    private function buat(): int
    {
        $this->post('/penggajian_sales', ['depo_id' => 46, 'periode_mulai' => '2026-10-20']);

        return $this->id = (int) DB::table('penggajian')->orderByDesc('id')->value('id');
    }

    private function detail(int $karyawan): int
    {
        return (int) DB::table('penggajian_detail')->where('penggajian_id', $this->id)->where('karyawan_id', $karyawan)->value('id');
    }

    private function url(string $aksi = '', ?int $karyawan = null): string
    {
        return "/penggajian_sales/{$this->id}".($karyawan ? '/detail/'.$this->detail($karyawan) : '').$aksi;
    }

    private function nominal(int $karyawan, string $kode): ?float
    {
        $n = DB::table('penggajian_komponen')->where('detail_id', $this->detail($karyawan))->where('kode', $kode)->value('nominal');

        return $n === null ? null : (float) $n;
    }

    private function total(int $karyawan): array
    {
        $d = DB::table('penggajian_detail')->find($this->detail($karyawan));

        return [(float) $d->total_pendapatan, (float) $d->total_potongan, (float) $d->total_bersih];
    }

    private function komponenManual(int $karyawan, array $ubah = [])
    {
        return $this->post($this->url('/komponen', $karyawan), $ubah + ['tipe' => 'potongan', 'parameter_pemotong_id' => 5, 'nominal' => 150000, 'keterangan' => 'BPJS November']);
    }

    // ------------------------------------------------------------------ hari izin

    public function test_hari_izin_menaikkan_tunjangan_prorata_dan_total(): void
    {
        $this->buat();
        $sebelum = $this->total(1)[0];

        $this->put($this->url('/izin', 1), ['hari_izin' => 3])->assertSessionHasNoErrors();

        $this->assertSame(60000.0, $this->nominal(1, 'tunjangan_bbm_makan')); // 540.000 × 3/27
        $this->assertSame(30000.0, $this->nominal(1, 'sewa_motor'));
        $this->assertSame(3, (int) DB::table('penggajian_detail')->find($this->detail(1))->hari_izin);
        $this->assertSame($sebelum + 90000, $this->total(1)[0]);
    }

    public function test_hari_izin_di_luar_rentang_ditolak(): void
    {
        $this->buat();

        foreach ([-1, 28, 'abc'] as $nilai) {
            $this->put($this->url('/izin', 1), ['hari_izin' => $nilai])->assertSessionHasErrors('hari_izin');
        }

        $this->assertSame(0, (int) DB::table('penggajian_detail')->find($this->detail(1))->hari_izin);
    }

    // ------------------------------------------------------------------ komponen manual

    public function test_potongan_manual_dari_parameter_mengurangi_total_bersih(): void
    {
        $this->buat();
        $pendapatan = $this->total(1)[0];

        $this->komponenManual(1)->assertSessionHasNoErrors();

        $k = DB::table('penggajian_komponen')->where('detail_id', $this->detail(1))->where('sumber', 'manual')->first();
        $this->assertSame(['potongan', 'Potongan BPJS Kesehatan', 5, 'BPJS November'], [$k->tipe, $k->nama, (int) $k->parameter_pemotong_id, $k->keterangan]);
        $this->assertSame([$pendapatan, 150000.0, $pendapatan - 150000], $this->total(1));
    }

    public function test_potongan_wajib_memilih_parameter_yang_ada(): void
    {
        $this->buat();

        $this->komponenManual(1, ['parameter_pemotong_id' => null])->assertSessionHasErrors('parameter_pemotong_id');
        $this->komponenManual(1, ['parameter_pemotong_id' => 999])->assertSessionHasErrors('parameter_pemotong_id');
        $this->assertSame(0, DB::table('penggajian_komponen')->where('sumber', 'manual')->count());
    }

    public function test_pendapatan_manual_memakai_nama_bebas_dan_menambah_total(): void
    {
        $this->buat();
        $pendapatan = $this->total(1)[0];

        $this->komponenManual(1, ['tipe' => 'pendapatan', 'parameter_pemotong_id' => null, 'nama' => 'Bonus target', 'nominal' => 500000])->assertSessionHasNoErrors();

        $this->assertSame([$pendapatan + 500000, 0.0, $pendapatan + 500000], $this->total(1));

        $this->komponenManual(1, ['tipe' => 'pendapatan', 'parameter_pemotong_id' => null, 'nama' => '', 'nominal' => 1000])->assertSessionHasErrors('nama');
    }

    public function test_nominal_tidak_valid_ditolak(): void
    {
        $this->buat();

        foreach ([0, -5, 'abc', 10000000000] as $nilai) {
            $this->komponenManual(1, ['nominal' => $nilai])->assertSessionHasErrors('nominal');
        }
    }

    public function test_ubah_dan_hapus_komponen_manual_memperbarui_total(): void
    {
        $this->buat();
        $pendapatan = $this->total(1)[0];
        $this->komponenManual(1);
        $id = (int) DB::table('penggajian_komponen')->where('sumber', 'manual')->value('id');

        $this->put($this->url("/komponen/{$id}", 1), ['tipe' => 'potongan', 'parameter_pemotong_id' => 4, 'nominal' => 200000, 'keterangan' => 'Kasbon'])->assertSessionHasNoErrors();
        $k = DB::table('penggajian_komponen')->find($id);
        $this->assertSame(['Potongan Pinjaman/Kasbon', 200000.0], [$k->nama, (float) $k->nominal]);
        $this->assertSame([$pendapatan, 200000.0, $pendapatan - 200000], $this->total(1));

        $this->delete($this->url("/komponen/{$id}", 1))->assertSessionHasNoErrors();
        $this->assertNull(DB::table('penggajian_komponen')->find($id));
        $this->assertSame([$pendapatan, 0.0, $pendapatan], $this->total(1));
    }

    public function test_komponen_otomatis_tidak_bisa_diubah_atau_dihapus_dan_komponen_orang_lain_404(): void
    {
        $this->buat();
        $otomatis = (int) DB::table('penggajian_komponen')->where('detail_id', $this->detail(1))->where('kode', 'gaji_pokok')->value('id');

        $this->put($this->url("/komponen/{$otomatis}", 1), ['tipe' => 'pendapatan', 'nama' => 'x', 'nominal' => 1])->assertSessionHasErrors('penggajian');
        $this->delete($this->url("/komponen/{$otomatis}", 1))->assertSessionHasErrors('penggajian');
        $this->assertSame(2700000.0, $this->nominal(1, 'gaji_pokok'));

        // Komponen milik Rudi lewat URL detail Tegar.
        $this->delete($this->url("/komponen/{$otomatis}", 3))->assertNotFound();
        $this->assertSame(2700000.0, $this->nominal(1, 'gaji_pokok'));
    }

    // ------------------------------------------------------------------ hitung ulang

    public function test_hitung_ulang_memperbarui_otomatis_dan_mempertahankan_manual_dan_izin(): void
    {
        $this->buat();
        $this->put($this->url('/izin', 1), ['hari_izin' => 2]);
        $this->komponenManual(1);

        DB::table('mk_karyawan')->where('id', 1)->update(['gaji_pokok' => 3000000]);
        foreach (['2026-10-21', '2026-10-22', '2026-10-23'] as $t) {
            DB::table('trx_absensi')->insert(['karyawan_id' => 1, 'tanggal' => $t, 'masuk_at' => "{$t} 08:00:00", 'terlambat' => $t === '2026-10-23']);
        }

        $this->post($this->url('/hitung-ulang'))->assertSessionHasNoErrors();

        $d = DB::table('penggajian_detail')->find($this->detail(1));
        $this->assertSame([3, 1, 2], [(int) $d->hari_hadir, (int) $d->hari_terlambat, (int) $d->hari_izin]);
        $this->assertSame(3000000.0, $this->nominal(1, 'gaji_pokok'));
        $this->assertSame(100000.0, $this->nominal(1, 'tunjangan_bbm_makan')); // 540.000 × (3+2)/27
        $this->assertSame(150000.0, (float) DB::table('penggajian_komponen')->where('detail_id', $d->id)->where('sumber', 'manual')->value('nominal'));
        $this->assertSame(1, DB::table('penggajian_komponen')->where('detail_id', $d->id)->where('sumber', 'manual')->count());
        $this->assertSame(0, DB::table('penggajian_komponen')->where('detail_id', $d->id)->where('kode', 'gaji_pokok')->where('nominal', 2700000)->count());
        $this->assertSame(
            (float) DB::table('penggajian_komponen')->where('detail_id', $d->id)->where('tipe', 'pendapatan')->sum('nominal'),
            $this->total(1)[0],
        );
        $this->assertSame($this->total(1)[0] - 150000, $this->total(1)[2]);
    }

    // ------------------------------------------------------------------ kekurangan data dan finalisasi

    public function test_detail_menampilkan_kekurangan_data_dan_finalkan_ditolak_selama_ada(): void
    {
        DB::table('mk_karyawan')->where('id', 3)->update(['gaji_pokok' => null]);
        $this->buat();

        $this->get($this->url())->assertInertia(fn (Assert $p) => $p
            ->component('Penggajian/Detail')
            ->has('kekurangan', 1)
            ->where('kekurangan.0.nama', 'Tegar Mahendra')
            ->where('kekurangan.0.masalah', 'Gaji pokok kosong')
        );

        $this->post($this->url('/finalkan'))->assertSessionHasErrors('penggajian');
        $this->assertSame('draf', DB::table('penggajian')->find($this->id)->status);

        // Setelah data diperbaiki dan dihitung ulang, kekurangan hilang dan final diterima.
        DB::table('mk_karyawan')->where('id', 3)->update(['gaji_pokok' => 2500000]);
        $this->post($this->url('/hitung-ulang'));
        $this->get($this->url())->assertInertia(fn (Assert $p) => $p->has('kekurangan', 0));

        $this->post($this->url('/finalkan'))->assertSessionHasNoErrors();
        $p = DB::table('penggajian')->find($this->id);
        $this->assertSame(['final', $this->admin->id], [$p->status, (int) $p->difinalkan_oleh]);
        $this->assertNotNull($p->difinalkan_pada);
        $this->assertSame(1, DB::table('riwayat_admin')->where('objek', 'penggajian')->where('aksi', 'finalkan')->count());
    }

    public function test_peringatan_bila_periode_belum_selesai(): void
    {
        $this->buat(); // hari ini 6 Nov, periode berakhir 19 Nov

        $this->get($this->url())->assertInertia(fn (Assert $p) => $p->where('penggajian.belum_selesai', true));

        $this->travelTo('2026-11-20 08:00:00');
        $this->get($this->url())->assertInertia(fn (Assert $p) => $p->where('penggajian.belum_selesai', false));
    }

    public function test_finalkan_periode_berjalan_tetap_diizinkan(): void
    {
        $this->buat();

        $this->post($this->url('/finalkan'))->assertSessionHasNoErrors();
        $this->assertSame('final', DB::table('penggajian')->find($this->id)->status);
    }

    public function test_penggajian_final_tidak_bisa_diubah_dihitung_ulang_atau_dibatalkan(): void
    {
        $this->buat();
        $this->komponenManual(1);
        $manual = (int) DB::table('penggajian_komponen')->where('sumber', 'manual')->value('id');
        $this->post($this->url('/finalkan'));
        $total = $this->total(1);

        $this->put($this->url('/izin', 1), ['hari_izin' => 5])->assertSessionHasErrors('penggajian');
        $this->komponenManual(1)->assertSessionHasErrors('penggajian');
        $this->put($this->url("/komponen/{$manual}", 1), ['tipe' => 'potongan', 'parameter_pemotong_id' => 5, 'nominal' => 1])->assertSessionHasErrors('penggajian');
        $this->delete($this->url("/komponen/{$manual}", 1))->assertSessionHasErrors('penggajian');
        $this->post($this->url('/hitung-ulang'))->assertSessionHasErrors('penggajian');
        $this->post($this->url('/batalkan'))->assertSessionHasErrors('penggajian');
        $this->post($this->url('/finalkan'))->assertSessionHasErrors('penggajian');

        $this->assertSame('final', DB::table('penggajian')->find($this->id)->status);
        $this->assertSame($total, $this->total(1));
        $this->assertSame(1, DB::table('penggajian_komponen')->where('sumber', 'manual')->count());
    }

    public function test_mengubah_gaji_karyawan_setelah_final_tidak_mengubah_rincian(): void
    {
        $this->buat();
        $this->post($this->url('/finalkan'));
        $sebelum = $this->total(1);

        DB::table('mk_karyawan')->where('id', 1)->update(['gaji_pokok' => 9000000, 'tunjangan_bbm_makan' => 1]);

        $this->get($this->url())->assertInertia(fn (Assert $p) => $p->where('detail.0.total_pendapatan', (int) $sebelum[0])
            ->where('detail.0.komponen.0.nominal', 2700000));
        $this->assertSame($sebelum, $this->total(1));
        $this->assertSame(2700000.0, $this->nominal(1, 'gaji_pokok'));
    }

    // ------------------------------------------------------------------ batal

    public function test_batalkan_draf_tidak_menghalangi_penggajian_baru_dan_tidak_bisa_diubah(): void
    {
        $this->buat();
        $lama = $this->id;

        $this->post($this->url('/batalkan'))->assertSessionHasNoErrors();
        $this->assertSame('batal', DB::table('penggajian')->find($lama)->status);
        $this->assertSame(2, DB::table('penggajian_detail')->where('penggajian_id', $lama)->count()); // baris tetap ada
        $this->assertSame(1, DB::table('riwayat_admin')->where('objek', 'penggajian')->where('aksi', 'batalkan')->count());

        $baru = $this->buat();
        $this->assertNotSame($lama, $baru);
        $this->assertSame('draf', DB::table('penggajian')->find($baru)->status);

        $this->id = $lama;
        $this->put($this->url('/izin', 1), ['hari_izin' => 1])->assertSessionHasErrors('penggajian');
        $this->post($this->url('/finalkan'))->assertSessionHasErrors('penggajian');
        $this->post($this->url('/hitung-ulang'))->assertSessionHasErrors('penggajian');
    }

    // ------------------------------------------------------------------ akses

    public function test_aksi_ditolak_untuk_selain_super_admin_dan_depo_di_luar_cakupan(): void
    {
        $this->buat();
        $manual = $this->url('/komponen', 1);

        $this->actingAs($this->akun('koor', 'koordinator', 137));
        $this->post($this->url('/hitung-ulang'))->assertForbidden();
        $this->post($this->url('/finalkan'))->assertForbidden();
        $this->post($this->url('/batalkan'))->assertForbidden();
        $this->put($this->url('/izin', 1), ['hari_izin' => 1])->assertForbidden();
        $this->post($manual, ['tipe' => 'pendapatan', 'nama' => 'x', 'nominal' => 1])->assertForbidden();

        // Akun bercakupan depo 22 punya hak ubah, tetapi penggajian ini milik depo 46.
        $role = $this->role('Admin Depo 22', 'depo', ['penggajian_sales' => ['lihat', 'tambah', 'ubah']]);
        $this->actingAs($this->akun('depo22', 'sales', 2, ['role_id' => $role]));
        $this->post($this->url('/hitung-ulang'))->assertForbidden();
        $this->post($this->url('/finalkan'))->assertForbidden();
        $this->put($this->url('/izin', 1), ['hari_izin' => 1])->assertForbidden();
        $this->post($manual, ['tipe' => 'pendapatan', 'nama' => 'x', 'nominal' => 1])->assertForbidden();
        $this->assertSame('draf', DB::table('penggajian')->find($this->id)->status);
        $this->assertSame(0, (int) DB::table('penggajian_detail')->find($this->detail(1))->hari_izin);
    }

    public function test_detail_dari_penggajian_lain_404(): void
    {
        $this->buat();
        $lain = DB::table('penggajian')->insertGetId([
            'jenis' => 'sales', 'kategori' => 'gaji', 'no_penggajian' => 'X-1', 'periode_mulai' => '2026-09-20', 'periode_selesai' => '2026-10-19',
            'depo_id' => 22, 'status' => 'draf', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $detailLain = DB::table('penggajian_detail')->insertGetId(['penggajian_id' => $lain, 'karyawan_id' => 2, 'nama' => 'Sinta', 'created_at' => now(), 'updated_at' => now()]);

        $this->put("/penggajian_sales/{$this->id}/detail/{$detailLain}/izin", ['hari_izin' => 1])->assertNotFound();
    }
}
