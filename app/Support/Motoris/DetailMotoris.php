<?php

namespace App\Support\Motoris;

use App\Models\User;
use App\Support\Akses\Akses;
use App\Support\Karyawan\TanggalBebas;
use App\Support\Menu\JamLokal;
use App\Support\Mobile\Pengaturan;
use App\Support\Tracking\TandaOutletMotoris;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Detail satu motoris untuk analisa kinerja oleh koordinator (modal Detail Motoris di Lacak Motoris, spec 060):
 * profil dan historis transaksi pada periode [dari, sampai]. Definisi angka sama dengan Motoris Report
 * (LaporanMotoris): hadir = trx_absensi, kunjungan = trx_kunjungan, transaksi/EC/qty/nilai dari trx_penjualan
 * yang tidak dibatalkan.
 */
class DetailMotoris
{
    /** Batas pengaman transaksi satu motoris dalam satu hari (wajarnya puluhan). */
    public const MAKS_TRANSAKSI = 200;

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
        )->first(['k.id', 'k.nama', 'k.no_hp', 'k.tanggal_mulai', 'k.aktif', 'k.foto', 'k.depo_id', 'd.nama as depo', 'ko.nama as koordinator']);

        return $motoris ? new self($motoris, $dari, $sampai) : null;
    }

    public function toArray(?User $user): array
    {
        return [
            'profil' => $this->profil($user),
            'periode' => ['dari' => $this->dari, 'sampai' => $this->sampai],
            'ringkasan' => $this->ringkasan(),
            'harian' => $this->harian(),
            'produk' => $this->produk(),
            'target' => $this->targetHarian(),
            'tanda_motoris' => $this->tandaMotoris(),
            'tanda_outlet' => $this->tandaOutlet(),
        ];
    }

    /** Target Call dan EC per hari kerja dari pengaturan depo motoris (sama dengan KPI di aplikasi HP dan Motoris Report). */
    private function targetHarian(): array
    {
        $target = Pengaturan::depo($this->motoris->depo_id ? (int) $this->motoris->depo_id : null)['target'] ?? config('mobile.target_default');

        return ['call' => (int) $target['call'], 'ec' => (int) $target['ec'], 'ipt' => (float) $target['ipt']];
    }

    /** Tanda motoris pada tanggal dalam periode (yang sudah dicabut tetap tampil sebagai riwayat), terbaru dulu. */
    private function tandaMotoris(): array
    {
        return DB::table('tanda_motoris')->where('karyawan_id', $this->motoris->id)->whereBetween('tanggal', [$this->dari, $this->sampai])
            ->orderByDesc('tanggal')->orderByDesc('id')->get()
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'tanggal' => substr((string) $t->tanggal, 0, 10),
                'kategori_label' => config("marketama.tanda_motoris.{$t->kategori}", $t->kategori),
                'catatan' => $t->catatan,
                'oleh' => $t->dibuat_nama,
                'waktu' => JamLokal::format($t->created_at),
                'dicabut' => $t->dicabut_at !== null,
            ])->all();
    }

    /**
     * Tanda outlet aktif pada outlet yang didaftarkan motoris ini (outlet dari HP atau pendaftar Marketama), dalam jendela
     * hari terakhir yang sama dengan lencana Ditandai di Lacak Motoris — tidak mengikuti periode.
     */
    private function tandaOutlet(): array
    {
        return TandaOutletMotoris::aktif(false, $this->motoris->id, $this->motoris->nama)
            ->orderByDesc('tout.created_at')->orderByDesc('tout.id')->limit(50)
            ->get(['tout.id', 'tout.outlet_id', 'o.nama as outlet', 'tout.kategori', 'tout.catatan', 'tout.dibuat_nama', 'tout.created_at'])
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'outlet_id' => (int) $t->outlet_id,
                'outlet' => $t->outlet,
                'kategori_label' => config("marketama.tanda_outlet.{$t->kategori}", $t->kategori),
                'catatan' => $t->catatan,
                'oleh' => $t->dibuat_nama,
                'waktu' => JamLokal::format($t->created_at),
            ])->all();
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

    /**
     * Pembagi kehadiran dan target: jumlah hari antara dua tanggal (termasuk) selain hari Minggu dan hari libur nasional
     * (holiday_dates). Sengaja tidak mengikuti waktu_aktif_app seperti LaporanMotoris::hariKerja.
     */
    public static function hariKerja(string $dari, string $sampai): int
    {
        if ($sampai < $dari) {
            return 0;
        }

        $libur = Pengaturan::libur($dari, $sampai);
        $zona = Pengaturan::zona();
        $akhir = CarbonImmutable::parse($sampai, $zona);
        $jumlah = 0;

        for ($hari = CarbonImmutable::parse($dari, $zona); $hari->lte($akhir); $hari = $hari->addDay()) {
            if (! $hari->isSunday() && ! isset($libur[$hari->toDateString()])) {
                $jumlah++;
            }
        }

        return $jumlah;
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
            ->selectRaw('COUNT(*) AS transaksi, SUM(CASE WHEN is_effective_call THEN 1 ELSE 0 END) AS ec, AVG(jumlah_sku) AS ipt, SUM(total_qty) AS qty, SUM(total) AS nilai')
            ->first();

        $hadir = (int) $absen->hadir;
        $ec = (int) $jual->ec;
        $nilai = (int) $jual->nilai;
        $hariIni = Pengaturan::sekarang()->toDateString();
        $hariKerja = self::hariKerja($this->dari, min($this->sampai, $hariIni));
        $target = $this->targetHarian();

        return [
            'hari_kerja' => $hariKerja,
            'target_call' => $target['call'] * $hariKerja,
            'target_ec' => $target['ec'] * $hariKerja,
            // IPT = rata-rata jenis SKU per transaksi aktif; rata-rata, jadi targetnya per transaksi (tidak dikali hari kerja).
            'target_ipt' => $target['ipt'],
            'hadir' => $hadir,
            'terlambat' => (int) $absen->terlambat,
            'kunjungan' => $kunjungan,
            'transaksi' => (int) $jual->transaksi,
            'ec' => $ec,
            'ipt' => $jual->ipt === null ? 0.0 : round((float) $jual->ipt, 1),
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
            ->get(['tanggal', 'masuk_at', 'pulang_at', 'terlambat'])->keyBy(fn ($r) => $tgl($r->tanggal));
        $kunjungan = DB::table('trx_kunjungan')->where('karyawan_id', $id)->whereBetween('tanggal', $rentang)
            ->groupBy('tanggal')->selectRaw('tanggal, COUNT(*) AS n')->get()->keyBy(fn ($r) => $tgl($r->tanggal));
        $jual = DB::table('trx_penjualan')->where('karyawan_id', $id)->whereBetween('tanggal', $rentang)->whereNull('dibatalkan_at')
            ->groupBy('tanggal')->selectRaw('tanggal, COUNT(*) AS transaksi, SUM(CASE WHEN is_effective_call THEN 1 ELSE 0 END) AS ec, AVG(jumlah_sku) AS ipt, SUM(total) AS nilai')
            ->get()->keyBy(fn ($r) => $tgl($r->tanggal));

        $tanggal = collect([...$absen->keys(), ...$kunjungan->keys(), ...$jual->keys()])->unique()->sort()->values();

        return $tanggal->map(fn ($t) => [
            'tanggal' => $t,
            'masuk' => JamLokal::format($absen[$t]->masuk_at ?? null, 'H:i'),
            'pulang' => JamLokal::format($absen[$t]->pulang_at ?? null, 'H:i'),
            'terlambat' => (bool) ($absen[$t]->terlambat ?? false),
            'kunjungan' => (int) ($kunjungan[$t]->n ?? 0),
            'ec' => (int) ($jual[$t]->ec ?? 0),
            'ipt' => round((float) ($jual[$t]->ipt ?? 0), 1),
            'transaksi' => (int) ($jual[$t]->transaksi ?? 0),
            'penjualan' => (int) ($jual[$t]->nilai ?? 0),
        ])->all();
    }

    /** Transaksi penjualan motoris pada satu tanggal (termasuk yang batal), terbaru dulu; dimuat saat baris Harian diklik. */
    public function transaksiTanggal(string $tanggal): array
    {
        return DB::table('trx_penjualan as p')->where('p.karyawan_id', $this->motoris->id)->where('p.tanggal', $tanggal)
            ->leftJoin('mk_outlet as o', 'o.id', '=', 'p.outlet_id')
            ->orderByDesc('p.recorded_at')->orderByDesc('p.id')->limit(self::MAKS_TRANSAKSI)
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
