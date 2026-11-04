<?php

namespace App\Support\Kendala;

use App\Models\User;
use App\Support\Akses\Akses;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

// Laporan Kendala Lapangan (spec 064): kategori dan status sebagai konstanta (bukan tabel master).
class LaporanKendala
{
    public const MENU = 'laporan_kendala';

    public const STATUS_TERBUKA = 'terbuka';

    public const STATUS_SELESAI = 'selesai';

    /** Kode status → label. */
    public const STATUS = [
        self::STATUS_TERBUKA => 'Terbuka',
        self::STATUS_SELESAI => 'Selesai',
    ];

    /** Kode kategori → label. */
    public const KATEGORI = [
        'stok_habis' => 'Stok barang canvassing habis / kurang',
        'kendaraan' => 'Kendaraan / armada',
        'akses_cuaca' => 'Akses jalan / cuaca',
        'outlet' => 'Outlet (tutup, menolak, pindah)',
        'aplikasi' => 'Aplikasi / HP / GPS',
        'sdm' => 'Motoris (sakit, izin, kekurangan orang)',
        'lainnya' => 'Lainnya',
    ];

    /** Laporan (alias l) yang pelapornya (alias k) masuk cakupan role akun. */
    public static function dalamCakupan(?User $user): Builder
    {
        return Akses::batasiKaryawan(
            DB::table('laporan_kendala as l')->join('mk_karyawan as k', 'k.id', '=', 'l.karyawan_id'),
            $user,
        );
    }

    /** Laporan Terbuka dalam cakupan yang dilaporkan orang lain dan belum pernah dibuka akun ini (dasar lencana Belum dibaca). */
    public static function belumDibaca(User $user): Builder
    {
        return self::dalamCakupan($user)
            ->leftJoin('laporan_kendala_dibaca as b', fn ($j) => $j->on('b.laporan_kendala_id', '=', 'l.id')->where('b.user_id', $user->id))
            ->where('l.status', self::STATUS_TERBUKA)->where('l.user_id', '!=', $user->id)->whereNull('b.id');
    }

    /**
     * Ringkasan kartu Beranda. Cakupan downline (koordinator): jumlah Terbuka tim dan 5 terbaru. Cakupan lain
     * (supervisor/admin): jumlah Terbuka, jumlah Belum dibaca, dan 5 terbaru yang belum dibaca. Null tanpa hak Lihat.
     *
     * @return array<string, mixed>|null
     */
    public static function ringkasan(?User $user): ?array
    {
        if (! $user || ! Akses::bisa($user, self::MENU, 'lihat')) {
            return null;
        }

        $terbuka = self::dalamCakupan($user)->where('l.status', self::STATUS_TERBUKA);
        $tim = Akses::cakupan($user) === 'downline';
        $daftar = $tim ? (clone $terbuka) : self::belumDibaca($user);

        return [
            'tim' => $tim,
            'terbuka' => (clone $terbuka)->count(),
            'belum_dibaca' => $tim ? null : (clone $daftar)->count(),
            'terbaru' => $daftar->orderByDesc('l.id')->limit(5)->get(['l.id', 'l.judul', 'l.kategori', 'l.pelapor_nama', 'l.tanggal_kejadian'])
                ->map(fn ($l) => [
                    'id' => $l->id, 'judul' => $l->judul, 'kategori_label' => self::labelKategori($l->kategori),
                    'pelapor' => $l->pelapor_nama, 'tanggal_kejadian' => $l->tanggal_kejadian,
                ])->values()->all(),
            'bisa_tambah' => Akses::bisa($user, self::MENU, 'tambah'),
        ];
    }

    /** Angka Belum dibaca di sidebar; null untuk akun tanpa hak Lihat atau cakupan downline (koordinator melihat timnya sendiri). */
    public static function angkaSidebar(?User $user): ?int
    {
        if (! $user || ! Akses::bisa($user, self::MENU, 'lihat') || Akses::cakupan($user) === 'downline') {
            return null;
        }

        return self::belumDibaca($user)->count();
    }

    public static function labelKategori(?string $kode): string
    {
        return self::KATEGORI[$kode] ?? (string) $kode;
    }

    public static function labelStatus(?string $kode): string
    {
        return self::STATUS[$kode] ?? (string) $kode;
    }
}
