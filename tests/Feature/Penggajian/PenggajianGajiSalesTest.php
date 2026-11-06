<?php

namespace Tests\Feature\Penggajian;

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\AdminTestCase;

// Draf gaji sales: buat per depo dan periode, satu detail per motoris aktif, komponen otomatis dengan asal angkanya.
// Depo 46 punya sales aktif Rudi (1) dan Tegar (3) serta Joko (4, nonaktif); Depo 22 punya Sinta (2).
class PenggajianGajiSalesTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-06 10:00:00');

        DB::table('mk_karyawan')->where('id', 1)->update(['gaji_pokok' => 2700000, 'tunjangan_internet' => 100000, 'tunjangan_jabatan' => 200000, 'tunjangan_bbm_makan' => 540000, 'sewa_motor' => 270000]);
        DB::table('mk_karyawan')->where('id', 3)->update(['gaji_pokok' => 2500000]);
    }

    private function absen(int $karyawan, string $tanggal, bool $terlambat = false): void
    {
        DB::table('trx_absensi')->insert(['karyawan_id' => $karyawan, 'tanggal' => $tanggal, 'masuk_at' => "{$tanggal} 08:00:00", 'terlambat' => $terlambat]);
    }

    private function buat(array $ubah = [])
    {
        return $this->post('/penggajian_sales', $ubah + ['depo_id' => 46, 'periode_mulai' => '2026-10-20']);
    }

    public function test_membuat_penggajian_menghasilkan_satu_detail_per_motoris_aktif(): void
    {
        $this->buat()->assertRedirect();

        $this->assertSame(1, DB::table('penggajian')->count());
        $p = DB::table('penggajian')->first();
        $this->assertSame(['sales', 'gaji', 'draf', 46], [$p->jenis, $p->kategori, $p->status, (int) $p->depo_id]);
        $this->assertSame(['2026-10-20', '2026-11-19', '2026-11-25'], [$p->periode_mulai, $p->periode_selesai, $p->tanggal_bayar]);
        $this->assertSame(['Rudi Hartono', 'Tegar Mahendra'], DB::table('penggajian_detail')->orderBy('nama')->pluck('nama')->all());
        $this->assertSame($this->admin->id, (int) $p->dibuat_oleh);
        $this->assertSame(1, DB::table('riwayat_admin')->where('objek', 'penggajian')->where('aksi', 'buat')->count());
    }

    public function test_motoris_dengan_status_aktif_kosong_atau_nonaktif_tidak_ikut(): void
    {
        DB::table('mk_karyawan')->insert(['id' => 5, 'kategori' => 'sales', 'nama' => 'Status Kosong', 'depo_id' => 46, 'koordinator_id' => 137, 'aktif' => null]);

        $this->buat();

        $this->assertSame(['Rudi Hartono', 'Tegar Mahendra'], DB::table('penggajian_detail')->orderBy('nama')->pluck('nama')->all());
    }

    public function test_detail_menampilkan_hari_dan_komponen_dengan_asal_angka(): void
    {
        foreach (['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30'] as $t) {
            $this->absen(1, $t);
        }

        $this->absen(1, '2026-10-19'); // di luar periode
        $this->absen(1, '2026-11-20'); // di luar periode

        // Kuota motoris depo 46 untuk bulan label periode (November 2026): pembagi tingkat kehadiran.
        DB::table('master_kuota_motoris')->insert(['depo_id' => 46, 'berlaku_mulai' => '2026-11', 'kuota' => 3]);

        $res = $this->buat();
        $id = DB::table('penggajian')->value('id');
        $res->assertRedirect("/penggajian_sales/{$id}");

        $this->get("/penggajian_sales/{$id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Penggajian/Detail')
            ->where('penggajian.status', 'draf')
            ->where('penggajian.periode_label', 'November 2026')
            ->where('penggajian.total_hadir', 9) // total hari kehadiran seluruh orang untuk kartu KPI (Rudi 9, Tegar 0)
            ->where('penggajian.rata_hadir', 0.3) // 9 ÷ 27 hari kerja
            ->where('penggajian.total_potongan', 0)
            ->where('penggajian.tingkat_hadir', 11.1) // 9 ÷ (27 hari kerja × kuota 3), bukan × 2 orang di draf
            ->where('penggajian.dasar_hadir', 'kuota')
            ->where('penggajian.pembagi_hadir', 3)
            ->has('detail', 2)
            ->where('detail.0.nama', 'Rudi Hartono')
            ->where('detail.0.hari_kerja', 27)
            ->where('detail.0.hari_hadir', 9)
            ->where('detail.0.hari_terlambat', 0)
            ->where('detail.0.hari_tidak_hadir', 18) // 27 hari kerja − 9 hadir
            ->where('detail.1.hari_tidak_hadir', 27) // Tegar tidak pernah hadir
            // 540.000 × 9/27 = 180.000; 270.000 × 9/27 = 90.000
            ->where('detail.0.total_pendapatan', 2700000 + 100000 + 200000 + 180000 + 90000)
            ->where('detail.0.komponen', fn ($k) => collect($k)->firstWhere('kode', 'tunjangan_bbm_makan')['nominal'] == 180000
                && collect($k)->firstWhere('kode', 'tunjangan_bbm_makan')['rincian']['hari_hadir'] === 9
                && collect($k)->firstWhere('kode', 'gaji_pokok')['rincian']['nilai_bulanan'] == 2700000)
        );
    }

    public function test_terlambat_ke_4_dst_dipotong_otomatis_dari_bbm_makan_dan_sewa_motor_dan_ikut_hitung_ulang(): void
    {
        DB::table('parameter_pemotong_gaji')->insert(['id' => 5, 'nama' => 'Potongan BPJS Kesehatan']);
        foreach (['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-26', '2026-10-27'] as $t) {
            $this->absen(1, $t, true); // 5 kali terlambat di Oktober: 2 hari dipotong
        }

        $this->buat();
        $id = (int) DB::table('penggajian')->value('id');
        $detail = (int) DB::table('penggajian_detail')->where('karyawan_id', 1)->value('id');
        $nominal = fn (string $kode) => (float) DB::table('penggajian_komponen')->where('detail_id', $detail)->where('kode', $kode)->value('nominal');

        $this->assertSame([100000.0, 50000.0], [$nominal('tunjangan_bbm_makan'), $nominal('sewa_motor')]); // prorata 5/27
        $this->assertSame([40000.0, 20000.0], [$nominal('potongan_terlambat_bbm_makan'), $nominal('potongan_terlambat_sewa_motor')]);
        $d = DB::table('penggajian_detail')->find($detail);
        $this->assertSame(60000.0, (float) $d->total_potongan);
        $this->assertSame((float) $d->total_pendapatan - 60000, (float) $d->total_bersih);

        $this->get("/penggajian_sales/{$id}")->assertInertia(fn (Assert $p) => $p
            ->where('detail.0.total_potongan', 60000)
            ->where('detail.0.komponen', fn ($k) => collect($k)->where('kode', 'potongan_terlambat_bbm_makan')->first()['sumber'] === 'otomatis'));

        // Potongan otomatis tidak bisa diubah atau dihapus manual.
        $komponen = (int) DB::table('penggajian_komponen')->where('detail_id', $detail)->where('kode', 'potongan_terlambat_bbm_makan')->value('id');
        $this->delete("/penggajian_sales/{$id}/detail/{$detail}/komponen/{$komponen}")->assertSessionHasErrors('penggajian');

        // Tambah potongan manual lalu terlambat sekali lagi: Hitung Ulang memperbarui yang otomatis, manual tetap.
        $this->post("/penggajian_sales/{$id}/detail/{$detail}/komponen", ['tipe' => 'potongan', 'parameter_pemotong_id' => 5, 'nominal' => 10000]);
        $this->absen(1, '2026-10-28', true);
        $this->post("/penggajian_sales/{$id}/hitung-ulang")->assertSessionHasNoErrors();

        $this->assertSame(60000.0, $nominal('potongan_terlambat_bbm_makan')); // 3 hari × 540.000 ÷ 27
        $this->assertSame(30000.0, $nominal('potongan_terlambat_sewa_motor'));
        $this->assertSame(1, DB::table('penggajian_komponen')->where('detail_id', $detail)->where('sumber', 'manual')->count());
        $this->assertSame(100000.0, (float) DB::table('penggajian_detail')->find($detail)->total_potongan); // 60.000 + 30.000 + 10.000 manual

        // Menyimpan hari izin menghitung ulang orang itu dengan data absensi terbaru, termasuk potongan terlambatnya.
        $this->put("/penggajian_sales/{$id}/detail/{$detail}/izin", ['hari_izin' => 1])->assertSessionHasNoErrors();
        $this->assertSame(60000.0, $nominal('potongan_terlambat_bbm_makan'));
    }

    public function test_terlambat_sampai_3_kali_sebulan_tidak_ada_potongan_otomatis(): void
    {
        foreach (['2026-10-21', '2026-10-22', '2026-10-23'] as $t) {
            $this->absen(1, $t, true);
        }

        $this->buat();
        $detail = (int) DB::table('penggajian_detail')->where('karyawan_id', 1)->value('id');

        $this->assertSame(0, DB::table('penggajian_komponen')->where('detail_id', $detail)->where('tipe', 'potongan')->count());
    }

    public function test_tingkat_kehadiran_kosong_bila_kuota_motoris_depo_belum_diisi(): void
    {
        $this->absen(1, '2026-10-21');
        $this->buat();
        $id = DB::table('penggajian')->value('id');

        $this->get("/penggajian_sales/{$id}")->assertInertia(fn (Assert $p) => $p
            ->where('penggajian.total_hadir', 1)
            ->where('penggajian.rata_hadir', 0) // 1 ÷ 27 dibulatkan 0,0
            ->where('penggajian.dasar_hadir', 'kuota')
            ->where('penggajian.pembagi_hadir', null)
            ->where('penggajian.tingkat_hadir', null));
    }

    public function test_kuota_yang_dipakai_adalah_kuota_bulan_label_periode(): void
    {
        $this->absen(1, '2026-10-21');
        // Kuota lama (berlaku sejak Oktober) lalu naik di November; periode berlabel November memakai kuota November.
        DB::table('master_kuota_motoris')->insert([
            ['depo_id' => 46, 'berlaku_mulai' => '2026-10', 'kuota' => 1],
            ['depo_id' => 46, 'berlaku_mulai' => '2026-11', 'kuota' => 2],
            ['depo_id' => 46, 'berlaku_mulai' => '2026-12', 'kuota' => 9],
        ]);
        $this->buat();
        $id = DB::table('penggajian')->value('id');

        $this->get("/penggajian_sales/{$id}")->assertInertia(fn (Assert $p) => $p
            ->where('penggajian.pembagi_hadir', 2)
            ->where('penggajian.tingkat_hadir', 1.9)); // 1 ÷ (27 × 2)
    }

    public function test_periode_tumpang_tindih_ditolak_dengan_menyebut_penggajian_lain(): void
    {
        $this->buat();
        $no = DB::table('penggajian')->value('no_penggajian');

        $this->from('/penggajian_sales/tambah')->buat(['periode_mulai' => '2026-11-10']) // masih periode yang sama
            ->assertSessionHasErrors('periode_mulai');

        $pesan = session('errors')->first('periode_mulai');
        $this->assertStringContainsString($no, $pesan);
        $this->assertStringContainsString('Rudi Hartono', $pesan);
        $this->assertSame(1, DB::table('penggajian')->count());
    }

    public function test_periode_berikutnya_dan_depo_lain_tidak_bentrok(): void
    {
        $this->buat();
        $this->buat(['periode_mulai' => '2026-11-20'])->assertSessionDoesntHaveErrors();
        $this->buat(['depo_id' => 22])->assertSessionDoesntHaveErrors();

        $this->assertSame(3, DB::table('penggajian')->count());
    }

    public function test_insentif_dan_penggajian_batal_tidak_menghalangi(): void
    {
        foreach (['insentif' => 'draf', 'gaji' => 'batal'] as $kategori => $status) {
            $id = DB::table('penggajian')->insertGetId([
                'jenis' => 'sales', 'kategori' => $kategori, 'no_penggajian' => "X-{$kategori}", 'periode_mulai' => '2026-10-01', 'periode_selesai' => '2026-10-31',
                'depo_id' => 46, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('penggajian_detail')->insert(['penggajian_id' => $id, 'karyawan_id' => 1, 'nama' => 'Rudi Hartono', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->buat()->assertSessionDoesntHaveErrors();
        $this->assertSame(3, DB::table('penggajian')->count());
    }

    public function test_depo_tanpa_motoris_aktif_ditolak(): void
    {
        DB::table('mk_depo')->insert(['id' => 99, 'nama' => 'Kosong']);

        $this->buat(['depo_id' => 99])->assertSessionHasErrors('depo_id');
        $this->assertSame(0, DB::table('penggajian')->count());
    }

    public function test_depo_di_luar_cakupan_akun_ditolak(): void
    {
        $role = $this->role('Admin Depo', 'depo', ['penggajian_sales' => ['lihat', 'tambah']]);
        $this->actingAs($this->akun('depo46', 'koordinator', 137, ['role_id' => $role]));

        $this->buat(['depo_id' => 22])->assertSessionHasErrors('depo_id');
        $this->assertSame(0, DB::table('penggajian')->count());

        $this->buat(['depo_id' => 46])->assertSessionDoesntHaveErrors();
        $this->assertSame(1, DB::table('penggajian')->count());
    }

    public function test_daftar_hanya_penggajian_baru_dengan_filter_depo_status_dan_periode(): void
    {
        $this->buat();
        $this->buat(['depo_id' => 22]);
        $this->buat(['periode_mulai' => '2026-11-20']);
        DB::table('penggajian')->where('depo_id', 22)->update(['status' => 'final']);

        $this->get('/penggajian_sales')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Penggajian/Index')
            ->where('baris.total', 3)
        );

        $this->get('/penggajian_sales?depo_id=46')->assertInertia(fn (Assert $p) => $p->where('baris.total', 2));
        $this->get('/penggajian_sales?status=final')->assertInertia(fn (Assert $p) => $p->where('baris.total', 1)->where('baris.data.0.depo', 'Balaraja'));
        $this->get('/penggajian_sales?periode=2026-12')->assertInertia(fn (Assert $p) => $p->where('baris.total', 1)); // 20 Nov–19 Des
    }

    public function test_halaman_tambah_menawarkan_periode_dan_depo_dalam_cakupan(): void
    {
        $this->get('/penggajian_sales/tambah')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Penggajian/Tambah')
            ->where('periode.0.mulai', '2026-10-20')
            ->where('periode.0.label', 'November 2026')
            ->has('depo', 2)
        );
    }

    public function test_selain_super_admin_ditolak(): void
    {
        $this->actingAs($this->akun('koor', 'koordinator', 137));

        $this->get('/penggajian_sales')->assertForbidden();
        $this->get('/penggajian_sales/tambah')->assertForbidden();
        $this->buat()->assertForbidden();
        $this->assertSame(0, DB::table('penggajian')->count());
    }

    public function test_penggajian_tidak_dikenal_atau_beda_jenis_404(): void
    {
        $this->get('/penggajian_sales/999')->assertNotFound();

        $id = DB::table('penggajian')->insertGetId([
            'jenis' => 'koordinator', 'kategori' => 'gaji', 'no_penggajian' => 'K-1', 'periode_mulai' => '2026-10-20', 'periode_selesai' => '2026-11-19',
            'depo_id' => 46, 'status' => 'draf', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get("/penggajian_sales/{$id}")->assertNotFound();
    }
}
