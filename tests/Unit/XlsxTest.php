<?php

namespace Tests\Unit;

use App\Support\Ekspor\Xlsx;
use PHPUnit\Framework\TestCase;
use ZipArchive;

// Penulis berkas Excel (.xlsx) tanpa dependensi: paket zip berisi XML, sel teks sebagai inlineStr, angka sebagai sel numerik.
class XlsxTest extends TestCase
{
    private function buat(array $baris, array $opsi = [], string $sheet = 'Data'): array
    {
        $jalan = tempnam(sys_get_temp_dir(), 'xlsx');
        Xlsx::tulis($jalan, $sheet, $baris, $opsi);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($jalan) === true, 'Berkas harus berupa zip yang valid.');
        $isi = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $isi[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }

        $zip->close();
        unlink($jalan);

        return $isi;
    }

    public function test_berkas_berisi_bagian_wajib_xlsx_dan_semua_xml_valid(): void
    {
        $isi = $this->buat([['Nama', 'Gaji'], ['Rudi', 2500000]]);

        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $bagian) {
            $this->assertArrayHasKey($bagian, $isi);
            $this->assertNotFalse(simplexml_load_string($isi[$bagian]), "{$bagian} harus XML yang valid.");
        }
    }

    public function test_teks_menjadi_inline_string_dan_angka_menjadi_sel_numerik(): void
    {
        $xml = $this->buat([['Nama', 'Gaji'], ['Rudi', 2500000], ['Tegar', 1234.5], ['Kosong', null]], ['uang' => [1]])['xl/worksheets/sheet1.xml'];

        $this->assertStringContainsString('<c r="A2" t="inlineStr"><is><t>Rudi</t></is></c>', $xml);
        $this->assertStringContainsString('<c r="B2" s="3"><v>2500000</v></c>', $xml); // format uang
        $this->assertStringContainsString('<c r="B3" s="3"><v>1234.5</v></c>', $xml);
        $this->assertStringNotContainsString('r="B4"', $xml); // null tidak menulis sel
    }

    public function test_karakter_xml_di_escape_dan_karakter_kontrol_dibuang(): void
    {
        $xml = $this->buat([["A & B <C> \"D\"\x01\x08"]])['xl/worksheets/sheet1.xml'];

        $this->assertStringContainsString('A &amp; B &lt;C&gt; &quot;D&quot;', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertStringNotContainsString("\x01", $xml);
    }

    public function test_teks_berawalan_rumus_tetap_teks_bukan_rumus(): void
    {
        $xml = $this->buat([['=1+1', '+SUM(A1)', '@x', '-2+3']])['xl/worksheets/sheet1.xml'];

        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertSame(4, substr_count($xml, 't="inlineStr"'));
    }

    public function test_header_membeku_dan_punya_filter_otomatis(): void
    {
        $isi = $this->buat([['Judul'], [], ['Nama', 'Gaji'], ['Rudi', 1], ['Tegar', 2]], ['header' => 2]);
        $xml = $isi['xl/worksheets/sheet1.xml'];

        $this->assertStringContainsString('<pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/>', $xml);
        $this->assertStringContainsString('<autoFilter ref="A3:B5"/>', $xml);
        $this->assertStringContainsString('<c r="A3" s="2" t="inlineStr">', $xml); // gaya header
    }

    public function test_baris_tebal_memakai_gaya_tebal_dan_angkanya_tetap_berformat_uang(): void
    {
        $xml = $this->buat([['Nama', 'Gaji'], ['Total', 3000000]], ['header' => 0, 'tebal' => [1], 'uang' => [1]])['xl/worksheets/sheet1.xml'];

        $this->assertStringContainsString('<c r="A2" s="1" t="inlineStr">', $xml);
        $this->assertStringContainsString('<c r="B2" s="4"><v>3000000</v></c>', $xml);
    }

    public function test_lebar_kolom_menyesuaikan_isi_dengan_batas(): void
    {
        $xml = $this->buat([['Nama', 'Keterangan'], ['Rudi', str_repeat('x', 200)]])['xl/worksheets/sheet1.xml'];

        $this->assertMatchesRegularExpression('/<col min="1" max="1" width="[\d.]+" customWidth="1"\/>/', $xml);
        $this->assertStringContainsString('width="60"', $xml); // dibatasi 60 karakter
    }

    public function test_nama_sheet_dibersihkan_dan_dipotong_31_karakter(): void
    {
        $workbook = $this->buat([['x']], [], 'Rincian: [Gaji]/Oktober?*\\ sangat panjang sekali nama sheetnya')['xl/workbook.xml'];

        preg_match('/<sheet name="([^"]+)"/', $workbook, $m);

        $this->assertLessThanOrEqual(31, mb_strlen($m[1]));
        $this->assertDoesNotMatchRegularExpression('/[\[\]:*?\/\\\\]/', $m[1]);
    }

    public function test_kolom_setelah_z_memakai_huruf_ganda(): void
    {
        $baris = [array_map(fn ($i) => "k{$i}", range(1, 30))];

        $xml = $this->buat($baris)['xl/worksheets/sheet1.xml'];

        $this->assertStringContainsString('<c r="Z1"', $xml);
        $this->assertStringContainsString('<c r="AA1"', $xml);
        $this->assertStringContainsString('<c r="AD1"', $xml);
    }

    private function buatBanyak(array $lembar): array
    {
        $jalan = tempnam(sys_get_temp_dir(), 'xlsx');
        Xlsx::tulisBanyak($jalan, $lembar);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($jalan) === true);
        $isi = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $isi[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }

        $zip->close();
        unlink($jalan);

        return $isi;
    }

    public function test_beberapa_sheet_urut_dengan_relasi_dan_tipe_konten_masing_masing(): void
    {
        $isi = $this->buatBanyak([
            ['nama' => 'Konsolidasi', 'baris' => [['Depo', 'Total'], ['A', 1]], 'opsi' => ['header' => 0]],
            ['nama' => 'Sidoarjo', 'baris' => [['Nama'], ['Rudi']]],
            ['nama' => 'Balaraja', 'baris' => [['Nama'], ['Sinta']]],
        ]);

        foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/worksheets/sheet3.xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', '[Content_Types].xml'] as $bagian) {
            $this->assertNotFalse(simplexml_load_string($isi[$bagian]), "{$bagian} harus XML yang valid.");
        }

        preg_match_all('/<sheet name="([^"]+)" sheetId="(\d+)" r:id="(rId\d+)"/', $isi['xl/workbook.xml'], $m, PREG_SET_ORDER);
        $this->assertSame([['Konsolidasi', '1', 'rId1'], ['Sidoarjo', '2', 'rId2'], ['Balaraja', '3', 'rId3']], array_map(fn ($x) => [$x[1], $x[2], $x[3]], $m));

        $this->assertStringContainsString('Target="worksheets/sheet3.xml"', $isi['xl/_rels/workbook.xml.rels']);
        $this->assertStringContainsString('Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"', $isi['xl/_rels/workbook.xml.rels']);
        $this->assertSame(3, substr_count($isi['[Content_Types].xml'], 'worksheet+xml'));

        $this->assertStringContainsString('<t>Rudi</t>', $isi['xl/worksheets/sheet2.xml']);
        $this->assertStringContainsString('<t>Sinta</t>', $isi['xl/worksheets/sheet3.xml']);
        $this->assertStringNotContainsString('Sinta', $isi['xl/worksheets/sheet2.xml']);
    }

    public function test_nama_sheet_ganda_dibuat_unik_tanpa_membedakan_huruf_dan_tetap_maksimum_31_karakter(): void
    {
        $panjang = str_repeat('x', 40);
        $isi = $this->buatBanyak([
            ['nama' => 'Depo A', 'baris' => [['a']]],
            ['nama' => 'depo a', 'baris' => [['b']]],
            ['nama' => 'DEPO A', 'baris' => [['c']]],
            ['nama' => $panjang, 'baris' => [['d']]],
            ['nama' => $panjang, 'baris' => [['e']]],
        ]);

        preg_match_all('/<sheet name="([^"]+)"/', $isi['xl/workbook.xml'], $m);
        $nama = $m[1];

        $this->assertSame(5, count(array_unique(array_map('mb_strtolower', $nama))));
        $this->assertSame(['Depo A', 'depo a (2)', 'DEPO A (3)'], array_slice($nama, 0, 3));

        foreach ($nama as $n) {
            $this->assertLessThanOrEqual(31, mb_strlen($n));
        }

        $this->assertStringEndsWith(' (2)', $nama[4]);
    }

    public function test_tanpa_sheet_ditolak(): void
    {
        $this->expectException(\RuntimeException::class);

        Xlsx::tulisBanyak(tempnam(sys_get_temp_dir(), 'xlsx'), []);
    }
}
