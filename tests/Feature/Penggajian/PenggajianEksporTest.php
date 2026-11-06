<?php

namespace Tests\Feature\Penggajian;

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\AdminTestCase;
use ZipArchive;

// Ekspor ke Excel (.xlsx): rincian per orang dari penggajian yang DIPILIH, hanya yang berstatus Final.
// Satu penggajian = satu sheet "Rincian" (tabel datar: No., No. Penggajian, Depo, Periode, Tanggal Bayar, Nama, hari (gaji),
// satu kolom per komponen, total). Beberapa penggajian = sheet "Konsolidasi" (satu baris per penggajian) + satu sheet per depo.
// Depo 46 (Sidoarjo): Rudi (1), Tegar (3), koordinator Koor Satu (137). Depo 22 (Balaraja): Sinta (2).
class PenggajianEksporTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-25 10:00:00');

        DB::table('mk_karyawan')->whereIn('id', [1, 2, 3, 137])->update(['gaji_pokok' => 2500000, 'tunjangan_internet' => 100000]);
        DB::table('rumus_insentif_sales')->insert(['tot_ec_lower_bound' => '0', 'tot_ec_upper_bound' => '100', 'total_insentif' => '50000']);
        DB::table('parameter_pemotong_gaji')->insert(['id' => 5, 'nama' => 'Potongan BPJS Kesehatan']);
    }

    private function buat(string $kategori = 'gaji', int $depo = 46, string $mulai = '2026-10-20', string $jenis = 'sales'): int
    {
        $this->post("/penggajian_{$jenis}", ['kategori' => $kategori, 'depo_id' => $depo, 'periode_mulai' => $mulai]);

        return (int) DB::table('penggajian')->orderByDesc('id')->value('id');
    }

    /** Finalkan penggajian (ekspor hanya untuk yang berstatus Final). */
    private function final(int $id, string $jenis = 'sales'): int
    {
        $this->post("/penggajian_{$jenis}/{$id}/finalkan")->assertSessionHasNoErrors();

        return $id;
    }

    /**
     * Isi seluruh buku dari respons unduhan: [nama sheet => [nomor baris => [huruf kolom => nilai]]], urut seperti di berkas.
     *
     * @return array<string, array<int, array<string, scalar>>>
     */
    private function buku($res): array
    {
        $jalan = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($jalan, $res->streamedContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($jalan) === true, 'Unduhan harus berupa berkas zip/xlsx yang valid.');

        $hapusNs = fn (string $xml) => preg_replace(['/ xmlns(:\w+)?="[^"]*"/', '/ r:id="[^"]*"/'], '', $xml);
        $workbook = simplexml_load_string($hapusNs($zip->getFromName('xl/workbook.xml')));
        $buku = [];

        foreach ($workbook->sheets->sheet as $i => $s) {
            $nomor = array_search((string) $s['name'], array_map(fn ($x) => (string) $x['name'], iterator_to_array($workbook->sheets->sheet, false)), true) + 1;
            $sheet = simplexml_load_string($hapusNs($zip->getFromName("xl/worksheets/sheet{$nomor}.xml")));
            $baris = [];

            foreach ($sheet->sheetData->row as $row) {
                foreach ($row->c as $c) {
                    $kolom = preg_replace('/\d+/', '', (string) $c['r']);
                    $baris[(int) $row['r']][$kolom] = isset($c->is) ? (string) $c->is->t : (0 + (string) $c->v);
                }
            }

            $buku[(string) $s['name']] = $baris;
        }

        $zip->close();
        unlink($jalan);

        return $buku;
    }

    /** Isi sheet pertama (satu penggajian = satu sheet). */
    private function sheet($res): array
    {
        return array_values($this->buku($res))[0];
    }

    /** Baris header (baris pertama) sebagai [huruf kolom => nama kolom]. */
    private function header(array $sheet): array
    {
        return $sheet[1];
    }

    /** Huruf kolom untuk nama header. */
    private function kolom(array $sheet, string $nama): string
    {
        $k = array_search($nama, $this->header($sheet), true);
        $this->assertNotFalse($k, "Kolom '{$nama}' tidak ada.");

        return $k;
    }

    /** Baris data (bukan header) yang nilai kolom $kolomNama-nya sama dengan $nilai. */
    private function baris(array $sheet, string $nilai, string $kolomNama = 'Nama'): ?array
    {
        $kolom = $this->kolom($sheet, $kolomNama);

        return collect($sheet)->skip(1)->first(fn ($b) => ($b[$kolom] ?? null) === $nilai);
    }

    private function orang(array $sheet, string $nama): ?array
    {
        return $this->baris($sheet, $nama);
    }

    private function ekspor(array $ids)
    {
        return $this->get('/penggajian_sales/ekspor?'.http_build_query(['ids' => $ids]));
    }

    // ------------------------------------------------------------------ satu penggajian (detail)

    public function test_ekspor_satu_penggajian_gaji_berupa_satu_sheet_tabel_datar_dengan_identitas_penggajian(): void
    {
        foreach (['2026-10-21', '2026-10-22'] as $t) {
            DB::table('trx_absensi')->insert(['karyawan_id' => 1, 'tanggal' => $t, 'masuk_at' => "{$t} 08:00:00", 'terlambat' => false]);
        }

        DB::table('mk_karyawan')->where('id', 1)->update(['nik' => '3515012345670001']);
        $id = $this->final($this->buat());
        $no = DB::table('penggajian')->find($id)->no_penggajian;
        $res = $this->get("/penggajian_sales/{$id}/ekspor");

        $res->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $res->headers->get('Content-Type'));
        $this->assertStringContainsString("{$no}.xlsx", $res->headers->get('Content-Disposition'));

        $buku = $this->buku($res);
        $this->assertSame(['Rincian'], array_keys($buku));

        $sheet = $buku['Rincian'];
        $header = array_values($this->header($sheet));
        $this->assertSame(['No', 'No. Penggajian', 'Depo', 'Periode', 'Tanggal Bayar', 'Nama', 'NIK/KTP', 'Hari Kerja', 'Hadir', 'Tidak Hadir', 'Terlambat', 'Izin'], array_slice($header, 0, 12));
        $this->assertSame(['Total Pendapatan', 'Total Potongan', 'Total Bersih'], array_slice($header, -3));

        $rudi = $this->orang($sheet, 'Rudi Hartono');
        $this->assertSame([$no, 'Sidoarjo', '2026-11-25'], [$rudi[$this->kolom($sheet, 'No. Penggajian')], $rudi[$this->kolom($sheet, 'Depo')], $rudi[$this->kolom($sheet, 'Tanggal Bayar')]]);
        $this->assertStringStartsWith('November 2026', $rudi[$this->kolom($sheet, 'Periode')]);
        $this->assertSame([27, 2, 25], [$rudi[$this->kolom($sheet, 'Hari Kerja')], $rudi[$this->kolom($sheet, 'Hadir')], $rudi[$this->kolom($sheet, 'Tidak Hadir')]]);
        $this->assertSame(2500000, $rudi[$this->kolom($sheet, 'Gaji pokok')]);
        $this->assertNotNull($this->orang($sheet, 'Tegar Mahendra'));
        // NIK 16 digit tersimpan sebagai teks (bukan angka, yang kehilangan presisi di Excel); yang belum punya NIK kosong.
        $this->assertSame('3515012345670001', $rudi[$this->kolom($sheet, 'NIK/KTP')]);
        $this->assertArrayNotHasKey($this->kolom($sheet, 'NIK/KTP'), $this->orang($sheet, 'Tegar Mahendra'));
    }

    public function test_ekspor_menyertakan_baris_total_dan_komponen_manual_sebagai_kolom(): void
    {
        $id = $this->buat();
        $detail = (int) DB::table('penggajian_detail')->where('karyawan_id', 1)->value('id');
        $this->post("/penggajian_sales/{$id}/detail/{$detail}/komponen", ['tipe' => 'potongan', 'parameter_pemotong_id' => 5, 'nominal' => 150000]);
        $this->post("/penggajian_sales/{$id}/detail/{$detail}/komponen", ['tipe' => 'pendapatan', 'nama' => 'Bonus target', 'nominal' => 500000]);
        $this->final($id);

        $sheet = $this->sheet($this->get("/penggajian_sales/{$id}/ekspor"));

        $kolomPotongan = $this->kolom($sheet, 'Potongan BPJS Kesehatan');
        $this->assertContains('Bonus target', $this->header($sheet));
        $this->assertSame(150000, $this->orang($sheet, 'Rudi Hartono')[$kolomPotongan]);
        $this->assertArrayNotHasKey($kolomPotongan, $this->orang($sheet, 'Tegar Mahendra')); // tidak punya: sel kosong

        $total = $this->orang($sheet, 'Total');
        $this->assertSame((int) DB::table('penggajian_detail')->where('penggajian_id', $id)->sum('total_bersih'), $total[$this->kolom($sheet, 'Total Bersih')]);
        $this->assertSame(150000, $total[$kolomPotongan]);
    }

    public function test_ekspor_insentif_tanpa_kolom_hari(): void
    {
        $id = $this->final($this->buat('insentif', 46, '2026-10-01'));

        $sheet = $this->sheet($this->get("/penggajian_sales/{$id}/ekspor"));

        $this->assertNotContains('Hari Kerja', $this->header($sheet));
        $this->assertNotContains('Hadir', $this->header($sheet));
        $this->assertContains('Insentif EC', $this->header($sheet));
    }

    // ------------------------------------------------------------------ banyak penggajian: sheet per depo + konsolidasi

    public function test_beberapa_penggajian_menghasilkan_sheet_konsolidasi_lalu_satu_sheet_per_depo(): void
    {
        $a = $this->final($this->buat('gaji', 46));
        $b = $this->final($this->buat('gaji', 22));
        $c = $this->final($this->buat('gaji', 46, '2026-09-20')); // depo 46 lagi, periode sebelumnya

        $res = $this->ekspor([$a, $b, $c]);
        $res->assertOk();
        $this->assertStringContainsString('penggajian_sales_gaji_', $res->headers->get('Content-Disposition'));

        $buku = $this->buku($res);
        $this->assertSame(['Konsolidasi', 'Balaraja', 'Sidoarjo'], array_keys($buku));

        // Sheet depo: semua penggajian terpilih milik depo itu dalam satu sheet.
        $sidoarjo = $buku['Sidoarjo'];
        $this->assertCount(4, collect($sidoarjo)->skip(1)->filter(fn ($b) => in_array($b[$this->kolom($sidoarjo, 'Nama')] ?? null, ['Rudi Hartono', 'Tegar Mahendra'], true)));
        $this->assertNull($this->orang($sidoarjo, 'Sinta Wulandari'));
        $this->assertSame(['Sidoarjo'], collect($sidoarjo)->skip(1)->pluck($this->kolom($sidoarjo, 'Depo'))->filter()->unique()->values()->all());

        $balaraja = $buku['Balaraja'];
        $this->assertNotNull($this->orang($balaraja, 'Sinta Wulandari'));
        $this->assertNull($this->orang($balaraja, 'Rudi Hartono'));
    }

    public function test_sheet_konsolidasi_berisi_rincian_semua_orang_dari_semua_depo_dengan_total(): void
    {
        $a = $this->final($this->buat('gaji', 46));
        $b = $this->final($this->buat('gaji', 22));

        $konsolidasi = $this->buku($this->ekspor([$a, $b]))['Konsolidasi'];

        $this->assertSame(['No', 'No. Penggajian', 'Depo', 'Periode', 'Tanggal Bayar', 'Nama'], array_slice(array_values($this->header($konsolidasi)), 0, 6));

        $depo = fn (string $nama) => $this->orang($konsolidasi, $nama)[$this->kolom($konsolidasi, 'Depo')];
        $this->assertSame(['Sidoarjo', 'Sidoarjo', 'Balaraja'], [$depo('Rudi Hartono'), $depo('Tegar Mahendra'), $depo('Sinta Wulandari')]);

        $total = $this->orang($konsolidasi, 'Total');
        $this->assertSame(
            (int) DB::table('penggajian_detail')->whereIn('penggajian_id', [$a, $b])->sum('total_bersih'),
            $total[$this->kolom($konsolidasi, 'Total Bersih')],
        );
        $this->assertCount(5, $konsolidasi); // header + 3 orang + total
    }

    public function test_komponen_manual_hanya_jadi_kolom_di_sheet_depo_yang_memilikinya(): void
    {
        $a = $this->buat('gaji', 46);
        $detail = (int) DB::table('penggajian_detail')->where('penggajian_id', $a)->where('karyawan_id', 1)->value('id');
        $this->post("/penggajian_sales/{$a}/detail/{$detail}/komponen", ['tipe' => 'pendapatan', 'nama' => 'Bonus target', 'nominal' => 500000]);
        $this->final($a);
        $b = $this->final($this->buat('gaji', 22));

        $buku = $this->buku($this->ekspor([$a, $b]));

        $this->assertContains('Bonus target', $this->header($buku['Sidoarjo']));
        $this->assertNotContains('Bonus target', $this->header($buku['Balaraja']));
    }

    public function test_hanya_penggajian_yang_dipilih_yang_diekspor(): void
    {
        $a = $this->final($this->buat('gaji', 46));
        $this->final($this->buat('gaji', 22)); // tidak dipilih

        $buku = $this->buku($this->ekspor([$a]));

        $this->assertSame(['Rincian'], array_keys($buku)); // satu penggajian: satu sheet, tanpa konsolidasi
        $this->assertNotNull($this->orang($buku['Rincian'], 'Rudi Hartono'));
        $this->assertNull($this->orang($buku['Rincian'], 'Sinta Wulandari'));
    }

    public function test_ekspor_pilihan_menolak_yang_bukan_final_beda_kategori_tidak_dikenal_dan_tanpa_pilihan(): void
    {
        $final = $this->final($this->buat('gaji', 46));
        $draf = $this->buat('gaji', 22);
        $this->ekspor([$final, $draf])->assertStatus(409);

        $insentif = $this->final($this->buat('insentif', 46, '2026-10-01'));
        $this->ekspor([$final, $insentif])->assertStatus(422);

        $this->ekspor([$final, 9999])->assertNotFound();
        $this->get('/penggajian_sales/ekspor')->assertSessionHasErrors('ids');
        $this->ekspor([])->assertSessionHasErrors('ids');
        $this->get('/penggajian_sales/ekspor?ids[]=abc')->assertSessionHasErrors('ids.0');
    }

    public function test_ekspor_pilihan_hanya_jenis_halaman_ini(): void
    {
        $sales = $this->final($this->buat());
        $koor = $this->buat('gaji', 46, '2026-10-20', 'koordinator');
        $this->final($koor, 'koordinator');

        $this->ekspor([$koor])->assertNotFound();
        $this->get('/penggajian_koordinator/ekspor?'.http_build_query(['ids' => [$sales]]))->assertNotFound();
        $this->get('/penggajian_koordinator/ekspor?'.http_build_query(['ids' => [$koor]]))->assertOk();
    }

    public function test_ekspor_detail_hanya_untuk_penggajian_final(): void
    {
        $draf = $this->buat();
        $this->get("/penggajian_sales/{$draf}/ekspor")->assertStatus(409);

        $this->post("/penggajian_sales/{$draf}/batalkan");
        $this->get("/penggajian_sales/{$draf}/ekspor")->assertStatus(409);

        $final = $this->final($this->buat());
        $this->get("/penggajian_sales/{$final}/ekspor")->assertOk();
        $this->get('/penggajian_sales/999/ekspor')->assertNotFound();
    }

    public function test_ekspor_detail_koordinator_hanya_lewat_halaman_koordinator(): void
    {
        $sales = $this->final($this->buat());
        $koor = $this->buat('gaji', 46, '2026-10-20', 'koordinator');
        $this->final($koor, 'koordinator');

        $this->get("/penggajian_koordinator/{$koor}/ekspor")->assertOk();
        $this->get("/penggajian_sales/{$koor}/ekspor")->assertNotFound();
        $this->get("/penggajian_koordinator/{$sales}/ekspor")->assertNotFound();
    }

    // ------------------------------------------------------------------ hak dan cakupan

    public function test_ekspor_ditolak_tanpa_hak_ekspor_selain_super_admin_dan_depo_di_luar_cakupan(): void
    {
        $id = $this->final($this->buat());

        $this->actingAs($this->akun('koor', 'koordinator', 137));
        $this->get("/penggajian_sales/{$id}/ekspor")->assertForbidden();
        $this->ekspor([$id])->assertForbidden();

        // Hak lihat saja tanpa hak ekspor.
        $role = $this->role('Pelihat', 'semua', ['penggajian_sales' => ['lihat']]);
        $this->actingAs($this->akun('pelihat', 'koordinator', 137, ['role_id' => $role]));
        $this->get("/penggajian_sales/{$id}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('bisaEkspor', false));
        $this->get("/penggajian_sales/{$id}/ekspor")->assertForbidden();
        $this->ekspor([$id])->assertForbidden();

        // Hak ekspor, tetapi penggajian milik depo lain.
        $role = $this->role('Ekspor Depo 22', 'depo', ['penggajian_sales' => ['lihat', 'ekspor']]);
        $this->actingAs($this->akun('depo22', 'sales', 2, ['role_id' => $role]));
        $this->get("/penggajian_sales/{$id}/ekspor")->assertForbidden();
        $this->ekspor([$id])->assertForbidden();
    }

    public function test_satu_penggajian_di_luar_cakupan_menggagalkan_seluruh_pilihan(): void
    {
        $a = $this->final($this->buat('gaji', 46));
        $b = $this->final($this->buat('gaji', 22));

        $role = $this->role('Ekspor Depo 46', 'depo', ['penggajian_sales' => ['lihat', 'ekspor']]);
        $this->actingAs($this->akun('depo46', 'koordinator', 137, ['role_id' => $role]));

        $this->ekspor([$a])->assertOk();
        $this->ekspor([$a, $b])->assertForbidden();
    }

    // ------------------------------------------------------------------ kolom pilihan (checkbox) di halaman daftar

    public function test_baris_daftar_menandai_hanya_penggajian_final_yang_bisa_dipilih(): void
    {
        $this->final($this->buat('gaji', 46));
        $this->buat('gaji', 22); // draf

        $this->get('/penggajian_sales?tab=gaji')->assertInertia(fn (Assert $p) => $p
            ->where('bisaEkspor', true)
            ->where('baris.data', fn ($baris) => collect($baris)->pluck('final', 'status')->sortKeys()->all() === ['Draf' => false, 'Final' => true]));
    }

    public function test_draf_dan_final_bisa_dipilih_tetapi_yang_batal_tidak(): void
    {
        $this->buat('gaji', 46);
        $batal = $this->buat('gaji', 22);
        $this->post("/penggajian_sales/{$batal}/batalkan");

        $this->get('/penggajian_sales?tab=gaji')->assertInertia(fn (Assert $p) => $p
            ->where('bisaUbah', true)
            ->where('baris.data', fn ($baris) => collect($baris)->pluck('bisa_dipilih', 'status')->sortKeys()->all() === ['Batal' => false, 'Draf' => true]));
    }

    public function test_finalkan_pilihan_memfinalkan_semua_draf_dan_melewati_yang_sudah_final(): void
    {
        $a = $this->buat('gaji', 46);
        $b = $this->buat('gaji', 22);
        $c = $this->final($this->buat('gaji', 46, '2026-09-20'));

        $this->post('/penggajian_sales/finalkan', ['ids' => [$a, $b, $c]])->assertSessionHasNoErrors()->assertRedirect('/penggajian_sales?tab=gaji');

        $this->assertSame(['final', 'final', 'final'], DB::table('penggajian')->whereIn('id', [$a, $b, $c])->orderBy('id')->pluck('status')->all());
    }

    public function test_finalkan_pilihan_menolak_batal_semua_sudah_final_dan_pilihan_kosong(): void
    {
        $batal = $this->buat('gaji', 22);
        $this->post("/penggajian_sales/{$batal}/batalkan");
        $draf = $this->buat('gaji', 46);
        $final = $this->final($this->buat('gaji', 46, '2026-09-20'));

        $this->post('/penggajian_sales/finalkan', ['ids' => [$draf, $batal]])->assertStatus(409);
        $this->assertSame('draf', DB::table('penggajian')->find($draf)->status);
        $this->post('/penggajian_sales/finalkan', ['ids' => [$final]])->assertStatus(409);
        $this->post('/penggajian_sales/finalkan', ['ids' => [9999]])->assertNotFound();
        $this->post('/penggajian_sales/finalkan', ['ids' => []])->assertSessionHasErrors('ids');
    }

    public function test_finalkan_pilihan_semua_atau_tidak_sama_sekali_bila_ada_data_belum_lengkap(): void
    {
        $a = $this->buat('gaji', 46);
        $b = $this->buat('gaji', 22);
        DB::table('mk_karyawan')->where('id', 2)->update(['gaji_pokok' => null]);
        $this->post("/penggajian_sales/{$b}/hitung-ulang");

        $this->post('/penggajian_sales/finalkan', ['ids' => [$a, $b]])->assertSessionHasErrors('penggajian');

        $this->assertSame(['draf', 'draf'], DB::table('penggajian')->whereIn('id', [$a, $b])->orderBy('id')->pluck('status')->all());
    }

    public function test_finalkan_pilihan_butuh_hak_ubah_dan_cakupan(): void
    {
        $a = $this->buat('gaji', 46);
        $b = $this->buat('gaji', 22);

        $role = $this->role('Pelihat', 'semua', ['penggajian_sales' => ['lihat', 'ekspor']]);
        $this->actingAs($this->akun('pelihat', 'koordinator', 137, ['role_id' => $role]));
        $this->post('/penggajian_sales/finalkan', ['ids' => [$a]])->assertForbidden();

        $role = $this->role('Ubah Depo 46', 'depo', ['penggajian_sales' => ['lihat', 'ubah']]);
        $this->actingAs($this->akun('depo46', 'koordinator', 137, ['role_id' => $role]));
        $this->post('/penggajian_sales/finalkan', ['ids' => [$a, $b]])->assertForbidden();
        $this->assertSame(['draf', 'draf'], DB::table('penggajian')->whereIn('id', [$a, $b])->orderBy('id')->pluck('status')->all());
    }

    public function test_halaman_mengirim_tanda_boleh_ekspor(): void
    {
        $id = $this->buat();

        $this->get("/penggajian_sales/{$id}")->assertInertia(fn (Assert $p) => $p->where('bisaEkspor', true));
        $this->get('/penggajian_sales')->assertInertia(fn (Assert $p) => $p->where('bisaEkspor', true));
    }
}
