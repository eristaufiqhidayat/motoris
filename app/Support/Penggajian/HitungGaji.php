<?php

namespace App\Support\Penggajian;

use App\Support\Motoris\Insentif;
use Illuminate\Support\Facades\DB;

// Satu-satunya tempat rumus gaji dan insentif. Masukannya data karyawan dan kehadiran (atau penjualan), hasilnya
// komponen beserta asal angkanya (nilai bulanan, jumlah hari, total EC, tingkat rumus) supaya rincian bisa
// ditelusuri. Gaji sales: gaji pokok, tunjangan internet, dan tunjangan jabatan dibayar penuh; tunjangan BBM &
// makan dan sewa motor prorata (hadir + izin) ÷ hari kerja. Insentif sales: insentif EC dan nilai poin.
class HitungGaji
{
    /** Komponen gaji penuh: kode => [kolom mk_karyawan, nama]. */
    private const PENUH = [
        'gaji_pokok' => ['gaji_pokok', 'Gaji pokok'],
        'tunjangan_internet' => ['tunjangan_internet', 'Tunjangan kuota internet'],
        'tunjangan_jabatan' => ['tunjangan_jabatan', 'Tunjangan jabatan'],
    ];

    /** Komponen gaji prorata menurut kehadiran. */
    private const PRORATA = [
        'tunjangan_bbm_makan' => ['tunjangan_bbm_makan', 'Tunjangan BBM & makan'],
        'sewa_motor' => ['sewa_motor', 'Sewa motor'],
    ];

    /**
     * Toleransi keterlambatan absen: 3 kali per bulan kalender tanpa sanksi (BUSINESS_RULES §10). Mulai keterlambatan
     * ke-4 dan seterusnya dalam bulan yang sama, tunjangan BBM & makan dan sewa motor hari itu dipotong.
     */
    public const TOLERANSI_TERLAMBAT = 3;

    /** Potongan terlambat per komponen prorata: kode komponen prorata => [kode potongan, nama potongan]. */
    private const POTONGAN_TERLAMBAT = [
        'tunjangan_bbm_makan' => ['potongan_terlambat_bbm_makan', 'Potongan terlambat: BBM & makan'],
        'sewa_motor' => ['potongan_terlambat_sewa_motor', 'Potongan terlambat: sewa motor'],
    ];

    /**
     * Gaji satu sales untuk satu periode. Komponen yang nilainya kosong atau nol tidak dibuat. Sales dikenai potongan
     * terlambat: tiap hari keterlambatan di atas toleransi memotong tunjangan BBM & makan dan sewa motor sebesar
     * nilai bulanan ÷ hari kerja.
     *
     * @param  array{hadir: int, terlambat: int, terlambat_bulan?: array<string, int>}  $kehadiran  dari kehadiran()
     * @return array{hari_kerja: int, hari_hadir: int, hari_terlambat: int, hari_izin: int, komponen: list<array<string, mixed>>, total_pendapatan: float, total_potongan: float, total_bersih: float}
     */
    public static function sales(object $karyawan, array $kehadiran, int $hariKerja, int $hariIzin = 0): array
    {
        return self::gaji($karyawan, $kehadiran, $hariKerja, $hariIzin, true);
    }

    /**
     * Hasil gaji: komponen pendapatan, potongan terlambat (bila $potongTerlambat), dan totalnya.
     *
     * @return array{hari_kerja: int, hari_hadir: int, hari_terlambat: int, hari_izin: int, komponen: list<array<string, mixed>>, total_pendapatan: float, total_potongan: float, total_bersih: float}
     */
    private static function gaji(object $karyawan, array $kehadiran, int $hariKerja, int $hariIzin, bool $potongTerlambat): array
    {
        $komponen = [];

        foreach (self::PENUH as $kode => [$kolom, $nama]) {
            $nilai = self::nilai($karyawan, $kolom);

            if ($nilai > 0) {
                $komponen[] = self::komponen($kode, $nama, $nilai, ['nilai_bulanan' => $nilai]);
            }
        }

        // Hari izin dihitung sebagai hadir; masuk di luar hari kerja (mis. Minggu) tidak melebihi nilai bulanan.
        $dasar = min($kehadiran['hadir'] + $hariIzin, $hariKerja);

        $bulan = $kehadiran['terlambat_bulan'] ?? null;
        $hariDipotong = $potongTerlambat ? self::hariTerlambatDipotong($kehadiran) : 0;
        $potongan = [];

        foreach (self::PRORATA as $kode => [$kolom, $nama]) {
            $nilai = self::nilai($karyawan, $kolom);

            if ($nilai > 0) {
                $nominal = $hariKerja > 0 ? round($nilai * $dasar / $hariKerja) : 0.0;
                $komponen[] = self::komponen($kode, $nama, (float) $nominal, [
                    'nilai_bulanan' => $nilai, 'hari_hadir' => $kehadiran['hadir'], 'hari_izin' => $hariIzin, 'hari_kerja' => $hariKerja,
                ]);

                if ($hariDipotong > 0 && $hariKerja > 0) {
                    [$kodePotongan, $namaPotongan] = self::POTONGAN_TERLAMBAT[$kode];
                    $rincian = ['hari_terlambat' => $kehadiran['terlambat'], 'toleransi_per_bulan' => self::TOLERANSI_TERLAMBAT, 'hari_dipotong' => $hariDipotong]
                        + ($bulan === null ? [] : ['per_bulan' => $bulan])
                        + ['nilai_bulanan' => $nilai, 'hari_kerja' => $hariKerja];

                    // Tidak pernah melebihi tunjangan prorata yang dibayar, supaya data janggal tidak membuat tunjangan negatif.
                    $potongan[] = self::komponen($kodePotongan, $namaPotongan, min((float) round($nilai * $hariDipotong / $hariKerja), (float) $nominal), $rincian, 'potongan');
                }
            }
        }

        $komponen = [...$komponen, ...$potongan];
        $pendapatan = (float) array_sum(array_column(array_filter($komponen, fn ($k) => $k['tipe'] === 'pendapatan'), 'nominal'));
        $totalPotongan = (float) array_sum(array_column($potongan, 'nominal'));

        return [
            'hari_kerja' => $hariKerja,
            'hari_hadir' => $kehadiran['hadir'],
            'hari_terlambat' => $kehadiran['terlambat'],
            'hari_izin' => $hariIzin,
            'komponen' => $komponen,
            'total_pendapatan' => $pendapatan,
            'total_potongan' => $totalPotongan,
            'total_bersih' => $pendapatan - $totalPotongan,
        ];
    }

    /**
     * Jumlah hari keterlambatan yang melewati toleransi, dihitung per bulan kalender (terlambat_bulan). Tanpa rincian
     * per bulan, total terlambat dianggap satu bulan.
     *
     * @param  array{terlambat: int, terlambat_bulan?: array<string, int>}  $kehadiran
     */
    private static function hariTerlambatDipotong(array $kehadiran): int
    {
        $bulan = $kehadiran['terlambat_bulan'] ?? ['_' => $kehadiran['terlambat']];

        return (int) array_sum(array_map(fn ($n) => max(0, $n - self::TOLERANSI_TERLAMBAT), $bulan));
    }

    /**
     * Gaji satu koordinator: komponen pendapatan yang sama dengan sales (data gaji, tunjangan, dan absensi koordinator
     * memakai kolom dan tabel yang sama). Aturan potongan terlambat hanya untuk motoris (sales), jadi koordinator
     * tidak dikenai potongan itu.
     *
     * @param  array{hadir: int, terlambat: int, terlambat_bulan?: array<string, int>}  $kehadiran  dari kehadiran()
     * @return array{hari_kerja: int, hari_hadir: int, hari_terlambat: int, hari_izin: int, komponen: list<array<string, mixed>>, total_pendapatan: float, total_potongan: float, total_bersih: float}
     */
    public static function koordinator(object $karyawan, array $kehadiran, int $hariKerja, int $hariIzin = 0): array
    {
        return self::gaji($karyawan, $kehadiran, $hariKerja, $hariIzin, false);
    }

    /**
     * Insentif satu sales untuk satu bulan: insentif EC (Rumus Insentif Sales dari total EC sebulan) dan nilai poin
     * (Konversi Poin Per Depo). Dihitung dengan aturan yang sama dengan Lap. Insentif Sales. Insentif EC selalu ada
     * barisnya (bernilai 0 bila di bawah rentang pertama) supaya asal angkanya terlihat; nilai poin hanya ada bila
     * depo punya konversi. Hari kerja, hadir, izin, dan terlambat tidak dipakai.
     *
     * @param  array{ec: int, poin: int}  $penjualan  dari Insentif::penjualanPerSales
     * @return array{hari_kerja: null, hari_hadir: null, hari_terlambat: null, hari_izin: null, komponen: list<array<string, mixed>>, total_pendapatan: float, total_potongan: float, total_bersih: float}
     */
    public static function insentifSales(?string $namaDepo, array $penjualan): array
    {
        $komponen = [];

        $ec = Insentif::ec($penjualan['ec']);

        if ($ec !== null) {
            $komponen[] = self::komponen('insentif_ec', 'Insentif EC', (float) $ec, [
                'total_ec' => $penjualan['ec'], 'tingkat' => Insentif::tingkatEc($penjualan['ec']),
            ]);
        }

        $nilaiPoin = Insentif::nilaiPoin($namaDepo, $penjualan['poin']);

        if ($nilaiPoin !== null) {
            $komponen[] = self::komponen('insentif_poin', 'Nilai poin', (float) $nilaiPoin, [
                'total_poin' => $penjualan['poin'], 'rupiah_per_poin' => Insentif::rupiahPerPoin($namaDepo),
            ]);
        }

        $pendapatan = (float) array_sum(array_column($komponen, 'nominal'));

        return [
            'hari_kerja' => null, 'hari_hadir' => null, 'hari_terlambat' => null, 'hari_izin' => null,
            'komponen' => $komponen,
            'total_pendapatan' => $pendapatan,
            'total_potongan' => 0.0,
            'total_bersih' => $pendapatan,
        ];
    }

    /**
     * Hari hadir (ada jam masuk), hari terlambat, dan keterlambatan per bulan kalender ('Y-m' => jumlah) per karyawan
     * dalam periode, dari trx_absensi. Absensi di luar periode tidak dihitung; karyawan tanpa absensi bernilai nol.
     * Rincian per bulan dipakai untuk toleransi keterlambatan, yang berlaku per bulan.
     *
     * @param  list<int>  $karyawanIds
     * @return array<int, array{hadir: int, terlambat: int, terlambat_bulan: array<string, int>}>
     */
    public static function kehadiran(array $karyawanIds, string $mulai, string $selesai): array
    {
        $hasil = array_fill_keys($karyawanIds, ['hadir' => 0, 'terlambat' => 0, 'terlambat_bulan' => []]);

        $baris = DB::table('trx_absensi')->whereIn('karyawan_id', $karyawanIds)
            ->whereBetween('tanggal', [$mulai, $selesai])->whereNotNull('masuk_at')
            ->groupBy('karyawan_id')
            ->selectRaw('karyawan_id, count(*) as hadir')->get();

        foreach ($baris as $b) {
            $hasil[(int) $b->karyawan_id]['hadir'] = (int) $b->hadir;
        }

        $telat = DB::table('trx_absensi')->whereIn('karyawan_id', $karyawanIds)
            ->whereBetween('tanggal', [$mulai, $selesai])->whereNotNull('masuk_at')->where('terlambat', true)
            ->orderBy('tanggal')->get(['karyawan_id', 'tanggal']);

        foreach ($telat as $t) {
            $bulan = substr((string) $t->tanggal, 0, 7);
            $hasil[(int) $t->karyawan_id]['terlambat_bulan'][$bulan] = ($hasil[(int) $t->karyawan_id]['terlambat_bulan'][$bulan] ?? 0) + 1;
            $hasil[(int) $t->karyawan_id]['terlambat']++;
        }

        return $hasil;
    }

    private static function nilai(object $karyawan, string $kolom): float
    {
        return (float) ($karyawan->{$kolom} ?? 0);
    }

    /** @return array<string, mixed> */
    private static function komponen(string $kode, string $nama, float $nominal, array $rincian, string $tipe = 'pendapatan'): array
    {
        return ['tipe' => $tipe, 'kode' => $kode, 'nama' => $nama, 'nominal' => $nominal, 'sumber' => 'otomatis', 'rincian' => $rincian];
    }
}
