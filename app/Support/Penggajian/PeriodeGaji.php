<?php

namespace App\Support\Penggajian;

use App\Models\User;
use App\Support\Akses\Akses;
use App\Support\Mobile\Pengaturan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Satu-satunya tempat aturan tanggal penggajian: periode gaji (cut off + tanggal bayar), periode insentif
// (cut off tetap akhir bulan kalender + tanggal bayar), label periode, dan hari kerja. Pengaturan dibaca per tanggal yang ditanyakan, jadi
// pengaturan bertanggal masa depan tidak memengaruhi periode yang sedang berjalan.
class PeriodeGaji
{
    public const KUNCI = [
        'cutoff_gaji' => 'Tanggal cut off gaji',
        'bayar_gaji' => 'Tanggal bayar gaji',
        'bayar_insentif' => 'Tanggal bayar insentif',
    ];

    private const BAWAAN = ['cutoff_gaji' => 20, 'bayar_gaji' => 25, 'bayar_insentif' => 15];

    /**
     * Periode gaji yang memuat tanggal itu: dari tanggal mulai sampai sehari sebelum tanggal mulai berikutnya.
     * Tanggal mulai tiap bulan mengikuti cut off yang berlaku pada tanggal mulai itu sendiri, sehingga
     * periode di sekitar pergantian pengaturan tetap bersambung tanpa tumpang tindih.
     *
     * @return array{mulai: string, selesai: string, tanggal_bayar: string, label: string}
     */
    public static function gaji(string $tanggal): array
    {
        $tgl = CarbonImmutable::parse($tanggal)->startOfDay();
        $mulai = null;

        // Mundur beberapa bulan: bulan tanpa tanggal mulai hanya terjadi tepat di pergantian pengaturan.
        for ($bulan = $tgl->startOfMonth(), $i = 0; $i < 4 && $mulai === null; $i++, $bulan = $bulan->subMonth()) {
            $awal = self::mulaiDiBulan($bulan);
            $mulai = $awal && $awal <= $tgl ? $awal : null;
        }

        $mulai ??= $tgl->startOfMonth();
        $berikut = null;

        for ($bulan = $mulai->startOfMonth()->addMonth(), $i = 0; $i < 4 && $berikut === null; $i++, $bulan = $bulan->addMonth()) {
            $berikut = self::mulaiDiBulan($bulan);
        }

        $selesai = ($berikut ?? $mulai->addMonth())->subDay();

        return [
            'mulai' => $mulai->toDateString(),
            'selesai' => $selesai->toDateString(),
            // Gaji dibayar pada bulan label periode (bulan tanggal akhir).
            'tanggal_bayar' => $selesai->day(self::nilai('bayar_gaji', $selesai->toDateString()))->toDateString(),
            'label' => self::label($selesai->toDateString()),
        ];
    }

    /** Tanggal mulai periode gaji yang jatuh di bulan itu (null bila pergantian cut off membuatnya terlewat). */
    private static function mulaiDiBulan(CarbonImmutable $bulan): ?CarbonImmutable
    {
        $awal = $bulan->startOfMonth();
        $perubahan = DB::table('pengaturan_tanggal_penggajian')->where('kunci', 'cutoff_gaji')
            ->whereBetween('berlaku_mulai', [$awal->toDateString(), $bulan->endOfMonth()->toDateString()])
            ->pluck('berlaku_mulai')->all();

        $kandidat = [];

        foreach ([$awal->toDateString(), ...$perubahan] as $pada) {
            $c = self::nilai('cutoff_gaji', $pada);
            $tgl = $awal->day($c);

            // Cut off itu sah hanya bila memang berlaku pada tanggal mulai yang dihasilkannya.
            if (self::nilai('cutoff_gaji', $tgl->toDateString()) === $c) {
                $kandidat[] = $tgl;
            }
        }

        return $kandidat ? min($kandidat) : null;
    }

    /**
     * Periode insentif: bulan kalender yang memuat tanggal itu, dibayar pada tanggal bayar bulan berikutnya.
     *
     * @return array{mulai: string, selesai: string, tanggal_bayar: string, label: string}
     */
    public static function insentif(string $tanggal): array
    {
        $tgl = CarbonImmutable::parse($tanggal);
        $bayar = self::nilai('bayar_insentif', $tgl->toDateString());

        return [
            'mulai' => $tgl->startOfMonth()->toDateString(),
            'selesai' => $tgl->endOfMonth()->toDateString(),
            'tanggal_bayar' => $tgl->startOfMonth()->addMonth()->day($bayar)->toDateString(),
            'label' => self::label($tgl->endOfMonth()->toDateString()),
        ];
    }

    /** Nama periode mengikuti bulan tanggal akhir: 20 Okt–19 Nov adalah "November 2026". */
    public static function label(string $periodeSelesai): string
    {
        return CarbonImmutable::parse($periodeSelesai)->locale('id')->translatedFormat('F Y');
    }

    /** Jumlah hari Senin–Sabtu antara dua tanggal (termasuk ujung), dikurangi hari libur. */
    public static function hariKerja(string $dari, string $sampai): int
    {
        $libur = Pengaturan::libur($dari, $sampai);
        $jumlah = 0;

        for ($t = CarbonImmutable::parse($dari), $akhir = CarbonImmutable::parse($sampai); $t <= $akhir; $t = $t->addDay()) {
            if (! $t->isSunday() && ! isset($libur[$t->toDateString()])) {
                $jumlah++;
            }
        }

        return $jumlah;
    }

    /** Nilai pengaturan yang berlaku pada tanggal itu (baris terbaru dengan berlaku_mulai <= tanggal). */
    public static function nilai(string $kunci, string $pada): int
    {
        $nilai = DB::table('pengaturan_tanggal_penggajian')->where('kunci', $kunci)->where('berlaku_mulai', '<=', $pada)
            ->orderByDesc('berlaku_mulai')->value('tanggal');

        return (int) ($nilai ?? self::BAWAAN[$kunci]);
    }

    /**
     * Tambah pengaturan baru. Menolak nilai di luar 1–28, tanggal berlaku lampau, dan tanggal berlaku yang
     * sudah dipakai pengaturan lain (riwayat tidak ditimpa).
     *
     * @throws ValidationException
     */
    public static function ubahPengaturan(string $kunci, int $tanggal, string $berlakuMulai, User $aktor): void
    {
        if (! array_key_exists($kunci, self::KUNCI)) {
            throw ValidationException::withMessages(['kunci' => 'Pengaturan tidak dikenal.']);
        }

        if ($tanggal < 1 || $tanggal > 28) {
            throw ValidationException::withMessages(['tanggal' => 'Tanggal harus antara 1 dan 28.']);
        }

        if ($berlakuMulai < Pengaturan::sekarang()->toDateString()) {
            throw ValidationException::withMessages(['berlaku_mulai' => 'Tanggal berlaku tidak boleh di masa lalu.']);
        }

        if (DB::table('pengaturan_tanggal_penggajian')->where('kunci', $kunci)->where('berlaku_mulai', $berlakuMulai)->exists()) {
            throw ValidationException::withMessages(['berlaku_mulai' => 'Sudah ada pengaturan yang berlaku mulai tanggal itu. Pilih tanggal berlaku lain.']);
        }

        // Disimpan dulu lalu diperiksa bersama pengaturan lain yang sudah terjadwal; ditolak = dibatalkan.
        $id = DB::transaction(function () use ($kunci, $tanggal, $berlakuMulai, $aktor) {
            $id = DB::table('pengaturan_tanggal_penggajian')->insertGetId([
                'kunci' => $kunci, 'tanggal' => $tanggal, 'berlaku_mulai' => $berlakuMulai,
                'diubah_oleh' => $aktor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            if (in_array($kunci, ['cutoff_gaji', 'bayar_gaji'], true)) {
                self::pastikanBayarSetelahCutoff($berlakuMulai);
            }

            return $id;
        });

        Akses::catat($aktor, 'pengaturan_penggajian', $id, 'ubah', self::KUNCI[$kunci].": {$tanggal} mulai {$berlakuMulai}");
    }

    /**
     * Gaji dibayar pada bulan label periode, setelah periode berakhir (sehari sebelum tanggal cut off), jadi
     * tanggal bayar tidak boleh lebih awal dari tanggal cut off. Diperiksa pada tanggal berlaku itu dan pada
     * setiap pergantian pengaturan gaji sesudahnya. Cut off 1 membuat periode berakhir di akhir bulan, yang
     * tidak pernah mendahului tanggal bayar (maks. 28), jadi ditolak juga.
     *
     * @throws ValidationException
     */
    private static function pastikanBayarSetelahCutoff(string $dari): void
    {
        $tanggalPeriksa = DB::table('pengaturan_tanggal_penggajian')->whereIn('kunci', ['cutoff_gaji', 'bayar_gaji'])
            ->where('berlaku_mulai', '>=', $dari)->distinct()->orderBy('berlaku_mulai')->pluck('berlaku_mulai');

        foreach ($tanggalPeriksa as $pada) {
            $cutoff = self::nilai('cutoff_gaji', $pada);
            $bayar = self::nilai('bayar_gaji', $pada);

            if ($cutoff === 1) {
                throw ValidationException::withMessages(['tanggal' => 'Cut off tanggal 1 membuat periode gaji berakhir di akhir bulan, sehingga gaji tidak bisa dibayar setelah periode berakhir. Pilih cut off tanggal 2–28.']);
            }

            if ($bayar < $cutoff) {
                throw ValidationException::withMessages(['tanggal' => "Tanggal bayar gaji ({$bayar}) tidak boleh lebih awal dari tanggal cut off gaji ({$cutoff}), karena gaji dibayar setelah periode berakhir. Berlaku mulai {$pada}."]);
            }
        }
    }
}
