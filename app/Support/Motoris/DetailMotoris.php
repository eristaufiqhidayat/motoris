<?php

namespace App\Support\Motoris;

use App\Models\User;
use App\Support\Akses\Akses;
use App\Support\Karyawan\TanggalBebas;
use App\Support\Menu\JamLokal;
use App\Support\Mobile\Pengaturan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Detail satu motoris untuk analisa kinerja oleh koordinator (modal Detail Motoris di Lacak Motoris, spec 060):
 * profil dan historis transaksi pada periode [dari, sampai]. Definisi angka sama dengan Motoris Report
 * (LaporanMotoris): hadir = trx_absensi, kunjungan = trx_kunjungan, transaksi/EC/qty/nilai dari trx_penjualan
 * yang tidak dibatalkan.
 */
class DetailMotoris
{
    public const MAKS_TRANSAKSI = 500;

    public const MAKS_PRODUK = 10;

    private function __construct(private readonly object $motoris, private readonly string $dari, private readonly string $sampai) {}

    /** Motoris (kategori sales) dalam cakupan role akun, atau null. */
    public static function untuk(?User $user, int $id, string $dari, string $sampai): ?self
    {
        $motoris = Akses::batasiKaryawan(
            DB::table('mk_karyawan as k')
                ->leftJoin('mk_depo as d', 'd.id', '=', 'k.depo_id')
                ->leftJoin('mk_karyawan as ko', 'ko.id', '=', 'k.koordinator_id')
                ->where('k.kategori', 'sales')->where('k.id', $id),
            $user
        )->first(['k.id', 'k.nama', 'k.no_hp', 'k.tanggal_mulai', 'k.aktif', 'k.foto', 'd.nama as depo', 'ko.nama as koordinator']);

        return $motoris ? new self($motoris, $dari, $sampai) : null;
    }

    public function toArray(?User $user): array
    {
        $penjualan = DB::table('trx_penjualan as p')->where('p.karyawan_id', $this->motoris->id)->whereBetween('p.tanggal', [$this->dari, $this->sampai]);

        return [
            'profil' => $this->profil($user),
            'periode' => ['dari' => $this->dari, 'sampai' => $this->sampai],
            'ringkasan' => $this->ringkasan(),
            'harian' => $this->harian(),
            'transaksi' => $this->transaksi(clone $penjualan),
            'transaksi_total' => (clone $penjualan)->count(),
            'produk' => $this->produk(),
        ];
    }

    private function profil(?User $user): array
    {
        $m = $this->motoris;
        $masuk = TanggalBebas::keYmd($m->tanggal_mulai);
        $area = DB::table('mk_karyawan_area as ka')->join('mk_area as a', 'a.id', '=', 'ka.area_id')
            ->where('ka.karyawan_id', $m->id)->orderBy('a.nama')->get(['a.nama', 'ka.aktif']);
        // Sama dengan Karyawan Sales: area aktif, atau semua area bila tidak ada yang aktif.
        $aktif = $area->filter(fn ($a) => (bool) $a->aktif);

        return [
            'id' => (int) $m->id,
            'nama' => $m->nama,
            'no_hp' => $m->no_hp,
            'depo' => $m->depo,
            'koordinator' => $m->koordinator,
            'area' => ($aktif->isNotEmpty() ? $aktif : $area)->pluck('nama')->values()->all(),
            'tanggal_masuk' => $masuk,
            'masa_kerja' => $masuk ? self::masaKerja($masuk) : null,
            'status' => $m->aktif === null ? '-' : ($m->aktif ? 'Aktif' : 'Tidak aktif'),
            'foto' => $m->foto && Akses::bisa($user, 'karyawan_sales', 'lihat')
                ? "/karyawan_sales/{$m->id}/foto?v=".substr(md5((string) $m->foto), 0, 8) : null,
        ];
    }

    private static function masaKerja(string $masuk): string
    {
        $selisih = CarbonImmutable::parse($masuk, Pengaturan::zona())->diff(Pengaturan::sekarang()->startOfDay());

        if ($selisih->invert) {
            return 'Belum mulai';
        }

        $bagian = array_filter([$selisih->y ? "{$selisih->y} tahun" : null, $selisih->m ? "{$selisih->m} bulan" : null]);

        return $bagian ? implode(' ', $bagian) : 'Kurang dari 1 bulan';
    }

    private function ringkasan(): array
    {
        $id = $this->motoris->id;
        $rentang = [$this->dari, $this->sampai];
        $absen = DB::table('trx_absensi')->where('karyawan_id', $id)->whereBetween('tanggal', $rentang)
            ->selectRaw('COUNT(*) AS hadir, SUM(CASE WHEN terlambat THEN 1 ELSE 0 END) AS terlambat')->first();
        $kunjungan = DB::table('trx_kunjungan')->where('karyawan_id', $id)->whereBetween('tanggal', $rentang)->count();
        $jual = DB::table('trx_penjualan')->where('karyawan_id', $id)->whereBetween('tanggal', $rentang)->whereNull('dibatalkan_at')
            ->selectRaw('COUNT(*) AS transaksi, SUM(CASE WHEN is_effective_call THEN 1 ELSE 0 END) AS ec, SUM(total_qty) AS qty, SUM(total) AS nilai')
            ->first();

        $hadir = (int) $absen->hadir;
        $ec = (int) $jual->ec;
        $nilai = (int) $jual->nilai;
        $hariIni = Pengaturan::sekarang()->toDateString();

        return [
            'hari_kerja' => LaporanMotoris::hariKerja($this->dari, min($this->sampai, $hariIni)),
            'hadir' => $hadir,
            'terlambat' => (int) $absen->terlambat,
            'kunjungan' => $kunjungan,
            'transaksi' => (int) $jual->transaksi,
            'ec' => $ec,
            'rasio_ec' => $kunjungan ? round($ec / $kunjungan * 100, 1) : 0,
            'penjualan' => $nilai,
            'qty' => self::angka($jual->qty),
            'rata_penjualan' => $hadir ? (int) round($nilai / $hadir) : 0,
        ];
    }

    /** Satu baris per tanggal yang punya absensi, kunjungan, atau penjualan, urut tanggal. */
    private function harian(): array
    {
        $id = $this->motoris->id;
        $rentang = [$this->dari, $this->sampai];
        $tgl = fn ($t) => substr((string) $t, 0, 10);

        $absen = DB::table('trx_absensi')->where('karyawan_id', $id)->whereBetween('tanggal', $rentang)
            ->get(['tanggal', 'masuk_at', 'terlambat'])->keyBy(fn ($r) => $tgl($r->tanggal));
        $kunjungan = DB::table('trx_kunjungan')->where('karyawan_id', $id)->whereBetween('tanggal', $rentang)
            ->groupBy('tanggal')->selectRaw('tanggal, COUNT(*) AS n')->get()->keyBy(fn ($r) => $tgl($r->tanggal));
        $jual = DB::table('trx_penjualan')->where('karyawan_id', $id)->whereBetween('tanggal', $rentang)->whereNull('dibatalkan_at')
            ->groupBy('tanggal')->selectRaw('tanggal, COUNT(*) AS transaksi, SUM(CASE WHEN is_effective_call THEN 1 ELSE 0 END) AS ec, SUM(total) AS nilai')
            ->get()->keyBy(fn ($r) => $tgl($r->tanggal));

        $tanggal = collect([...$absen->keys(), ...$kunjungan->keys(), ...$jual->keys()])->unique()->sort()->values();

        return $tanggal->map(fn ($t) => [
            'tanggal' => $t,
            'masuk' => JamLokal::format($absen[$t]->masuk_at ?? null, 'H:i'),
            'terlambat' => (bool) ($absen[$t]->terlambat ?? false),
            'kunjungan' => (int) ($kunjungan[$t]->n ?? 0),
            'ec' => (int) ($jual[$t]->ec ?? 0),
            'transaksi' => (int) ($jual[$t]->transaksi ?? 0),
            'penjualan' => (int) ($jual[$t]->nilai ?? 0),
        ])->all();
    }

    private function transaksi(Builder $penjualan): array
    {
        return $penjualan->leftJoin('mk_outlet as o', 'o.id', '=', 'p.outlet_id')
            ->orderByDesc('p.tanggal')->orderByDesc('p.recorded_at')->orderByDesc('p.id')->limit(self::MAKS_TRANSAKSI)
            ->get(['p.kode', 'p.tanggal', 'p.recorded_at', 'p.received_at', 'o.nama as outlet', 'p.jumlah_sku', 'p.total_qty', 'p.total', 'p.total_diskon', 'p.dibatalkan_at'])
            ->map(fn ($p) => [
                'kode' => $p->kode,
                'waktu' => JamLokal::format($p->recorded_at ?? $p->received_at) ?? substr((string) $p->tanggal, 0, 10),
                'outlet' => $p->outlet,
                'sku' => (int) $p->jumlah_sku,
                'qty' => self::angka($p->total_qty),
                'total' => (int) $p->total,
                'diskon' => (int) $p->total_diskon,
                'status' => $p->dibatalkan_at ? 'Batal' : 'Sah',
            ])->all();
    }

    private function produk(): array
    {
        return DB::table('trx_penjualan_item as i')->join('trx_penjualan as p', 'p.id', '=', 'i.penjualan_id')
            ->leftJoin('mk_produk as pr', 'pr.id', '=', 'i.produk_id')
            ->where('p.karyawan_id', $this->motoris->id)->whereBetween('p.tanggal', [$this->dari, $this->sampai])->whereNull('p.dibatalkan_at')
            ->groupBy('i.produk_id', 'pr.nama')
            ->selectRaw('i.produk_id, pr.nama, SUM(i.qty) AS qty, SUM(i.total) AS nilai')
            ->orderByDesc('qty')->orderBy('pr.nama')->limit(self::MAKS_PRODUK)->get()
            ->map(fn ($r) => ['nama' => $r->nama ?? "Produk #{$r->produk_id}", 'qty' => self::angka($r->qty), 'nilai' => (int) $r->nilai])
            ->all();
    }

    /** Qty desimal (12,2) jadi int bila bulat, supaya JSON rapi (5, bukan "5.00"). */
    private static function angka(mixed $n): int|float
    {
        $f = (float) $n;

        return floor($f) === $f ? (int) $f : $f;
    }
}
