<?php

namespace App\Support\Ekspor;

use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

// Penulis berkas Excel (.xlsx) tanpa dependensi (satu sheet, atau beberapa sheet lewat tulisBanyak/unduhBanyak): paket zip berisi XML (ext-zip). Teks ditulis sebagai sel
// inlineStr, jadi isi seperti "=1+1" tetap teks dan tidak pernah dievaluasi sebagai rumus; angka ditulis sebagai sel numerik
// (opsional berformat ribuan #,##0). Mendukung header yang dibekukan dengan filter otomatis, baris tebal, dan lebar kolom otomatis.
//
// Opsi `$opsi` per sheet untuk tulis()/unduh() dan tiap entri tulisBanyak()/unduhBanyak():
//   header => indeks baris (0-based) header tabel: bergaya header, dibekukan, dan diberi filter otomatis;
//   tebal  => daftar indeks baris yang ditebalkan (mis. baris total);
//   uang   => daftar indeks kolom (0-based) berformat angka ribuan.
final class Xlsx
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const GAYA_TEBAL = 1;

    private const GAYA_HEADER = 2;

    private const GAYA_UANG = 3;

    private const GAYA_UANG_TEBAL = 4;

    private const LEBAR_MAKS = 60;

    /**
     * Tulis satu sheet ke berkas $jalan.
     *
     * @param  list<list<scalar|null>>  $baris
     * @param  array{header?: int, tebal?: list<int>, uang?: list<int>}  $opsi
     */
    public static function tulis(string $jalan, string $sheet, array $baris, array $opsi = []): void
    {
        self::tulisBanyak($jalan, [['nama' => $sheet, 'baris' => $baris, 'opsi' => $opsi]]);
    }

    /**
     * Tulis beberapa sheet (urut seperti diberikan) ke berkas $jalan. Nama sheet dibersihkan dan dibuat unik (tanpa
     * membedakan huruf besar kecil), karena Excel menolak nama ganda.
     *
     * @param  list<array{nama: string, baris: list<list<scalar|null>>, opsi?: array{header?: int, tebal?: list<int>, uang?: list<int>}}>  $lembar
     */
    public static function tulisBanyak(string $jalan, array $lembar): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekstensi zip PHP belum aktif, ekspor Excel tidak tersedia.');
        }

        if (! $lembar) {
            throw new RuntimeException('Berkas Excel butuh minimal satu sheet.');
        }

        $zip = new ZipArchive;

        if ($zip->open($jalan, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Berkas ekspor tidak bisa dibuat.');
        }

        $nama = self::namaUnik(array_column($lembar, 'nama'));

        $zip->addFromString('[Content_Types].xml', self::tipeKonten(count($lembar)));
        $zip->addFromString('_rels/.rels', self::relasiUtama());
        $zip->addFromString('xl/workbook.xml', self::workbook($nama));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::relasiWorkbook(count($lembar)));
        $zip->addFromString('xl/styles.xml', self::gaya());

        foreach (array_values($lembar) as $i => $l) {
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', self::lembar($l['baris'], $l['opsi'] ?? []));
        }

        $zip->close();
    }

    /**
     * Respons unduhan .xlsx satu sheet: berkas dibuat di folder sementara, dikirim, lalu dihapus.
     *
     * @param  list<list<scalar|null>>  $baris
     * @param  array{header?: int, tebal?: list<int>, uang?: list<int>}  $opsi
     */
    public static function unduh(string $namaBerkas, string $sheet, array $baris, array $opsi = []): StreamedResponse
    {
        return self::unduhBanyak($namaBerkas, [['nama' => $sheet, 'baris' => $baris, 'opsi' => $opsi]]);
    }

    /**
     * Respons unduhan .xlsx beberapa sheet (lihat tulisBanyak).
     *
     * @param  list<array{nama: string, baris: list<list<scalar|null>>, opsi?: array{header?: int, tebal?: list<int>, uang?: list<int>}}>  $lembar
     */
    public static function unduhBanyak(string $namaBerkas, array $lembar): StreamedResponse
    {
        $jalan = tempnam(sys_get_temp_dir(), 'xlsx');
        self::tulisBanyak($jalan, $lembar);

        return response()->streamDownload(function () use ($jalan) {
            readfile($jalan);
            @unlink($jalan);
        }, $namaBerkas, ['Content-Type' => self::MIME]);
    }

    /** Huruf kolom Excel dari indeks 0-based: 0 = A, 25 = Z, 26 = AA. */
    public static function huruf(int $indeks): string
    {
        $huruf = '';

        for ($n = $indeks + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $huruf = chr(65 + ($n - 1) % 26).$huruf;
        }

        return $huruf;
    }

    /** @param  list<list<scalar|null>>  $baris */
    private static function lembar(array $baris, array $opsi): string
    {
        $header = $opsi['header'] ?? null;
        $tebal = array_flip($opsi['tebal'] ?? []);
        $uang = array_flip($opsi['uang'] ?? []);
        $jumlahKolom = $baris ? max(array_map('count', $baris)) : 0;

        $data = '';

        foreach ($baris as $i => $sel) {
            $isi = '';

            foreach (array_values($sel) as $k => $nilai) {
                if ($nilai === null || $nilai === '') {
                    continue;
                }

                $ref = self::huruf($k).($i + 1);
                $gaya = match (true) {
                    $header === $i => self::GAYA_HEADER,
                    is_int($nilai) || is_float($nilai) => isset($uang[$k]) ? (isset($tebal[$i]) ? self::GAYA_UANG_TEBAL : self::GAYA_UANG) : (isset($tebal[$i]) ? self::GAYA_TEBAL : 0),
                    default => isset($tebal[$i]) ? self::GAYA_TEBAL : 0,
                };
                $s = $gaya ? ' s="'.$gaya.'"' : '';

                $isi .= is_int($nilai) || is_float($nilai)
                    ? '<c r="'.$ref.'"'.$s.'><v>'.$nilai.'</v></c>'
                    : '<c r="'.$ref.'"'.$s.' t="inlineStr"><is><t'.(trim((string) $nilai) !== (string) $nilai ? ' xml:space="preserve"' : '').'>'.self::teks((string) $nilai).'</t></is></c>';
            }

            $data .= '<row r="'.($i + 1).'">'.$isi.'</row>';
        }

        $beku = $header === null ? '' : '<pane ySplit="'.($header + 1).'" topLeftCell="A'.($header + 2).'" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft"/>';
        $filter = $header === null || $jumlahKolom === 0 ? '' : '<autoFilter ref="A'.($header + 1).':'.self::huruf($jumlahKolom - 1).count($baris).'"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0">'.$beku.'</sheetView></sheetViews>'
            .self::lebarKolom($baris, $jumlahKolom)
            .'<sheetData>'.$data.'</sheetData>'
            .$filter
            .'</worksheet>';
    }

    /** Lebar kolom menyesuaikan teks terpanjang (maksimum 60 karakter). */
    private static function lebarKolom(array $baris, int $jumlahKolom): string
    {
        if ($jumlahKolom === 0) {
            return '';
        }

        $kolom = '';

        for ($k = 0; $k < $jumlahKolom; $k++) {
            $panjang = 0;

            foreach ($baris as $sel) {
                $nilai = array_values($sel)[$k] ?? null;
                $panjang = max($panjang, $nilai === null ? 0 : mb_strlen(is_float($nilai) || is_int($nilai) ? number_format((float) $nilai, 0, ',', '.') : (string) $nilai));
            }

            $lebar = min(self::LEBAR_MAKS, max(8, $panjang + 2));
            $kolom .= '<col min="'.($k + 1).'" max="'.($k + 1).'" width="'.$lebar.'" customWidth="1"/>';
        }

        return '<cols>'.$kolom.'</cols>';
    }

    /** Buang karakter kontrol yang tidak boleh ada di XML 1.0, lalu escape. */
    private static function teks(string $nilai): string
    {
        $bersih = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $nilai) ?? '';

        return htmlspecialchars($bersih, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Nama sheet mentah: tanpa karakter terlarang Excel dan maksimum 31 karakter. */
    private static function bersihkanNamaSheet(string $nama): string
    {
        $bersih = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $nama) ?? '');

        return mb_substr($bersih === '' ? 'Data' : $bersih, 0, 31);
    }

    /**
     * Nama-nama sheet yang sudah bersih dan unik (huruf besar kecil dianggap sama); nama ganda diberi akhiran " (2)", " (3)", dst.
     * dengan memotong nama dasarnya supaya tetap maksimum 31 karakter.
     *
     * @param  list<string>  $nama
     * @return list<string> sudah di-escape untuk XML
     */
    private static function namaUnik(array $nama): array
    {
        $dipakai = [];
        $hasil = [];

        foreach ($nama as $n) {
            $dasar = self::bersihkanNamaSheet($n);
            $kandidat = $dasar;

            for ($i = 2; isset($dipakai[mb_strtolower($kandidat)]); $i++) {
                $akhiran = " ({$i})";
                $kandidat = mb_substr($dasar, 0, 31 - mb_strlen($akhiran)).$akhiran;
            }

            $dipakai[mb_strtolower($kandidat)] = true;
            $hasil[] = self::teks($kandidat);
        }

        return $hasil;
    }

    private static function tipeKonten(int $jumlahSheet): string
    {
        $sheet = '';

        for ($i = 1; $i <= $jumlahSheet; $i++) {
            $sheet .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$sheet
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private static function relasiUtama(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    /** @param  list<string>  $namaSheet  sudah bersih, unik, dan di-escape */
    private static function workbook(array $namaSheet): string
    {
        $sheet = '';

        foreach ($namaSheet as $i => $nama) {
            $sheet .= '<sheet name="'.$nama.'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheet.'</sheets>'
            .'</workbook>';
    }

    private static function relasiWorkbook(int $jumlahSheet): string
    {
        $sheet = '';

        for ($i = 1; $i <= $jumlahSheet; $i++) {
            $sheet .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$sheet
            .'<Relationship Id="rId'.($jumlahSheet + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /** Gaya sel: 0 biasa, 1 tebal, 2 header (tebal berlatar abu), 3 angka ribuan, 4 angka ribuan tebal. */
    private static function gaya(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE9EEF5"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="5">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="3" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
