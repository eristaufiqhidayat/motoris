<?php

namespace App\Support\Motoris;

use App\Models\User;
use App\Support\Akses\Cakupan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Beranda (home): ringkasan nasional data motoris untuk kartu KPI (lewat SummaryDepo), funnel outlet, dan
// tren harian. Funnel outlet (NOO → AC → EC → RO, OSS sebagai peringatan terpisah) memakai definisi yang
// sama dengan App\Support\Menu\Motoris\SumberOutletStatus — status seumur hidup outlet, bukan dibatasi
// tanggal, kecuali NOO yang memang punya tanggal daftar. Dibatasi cakupan akun lewat depo outlet (sama dengan
// SummaryDepo / SumberOutletStatus, lihat Cakupan::depoIds), KECUALI NOO yang dibatasi lewat motoris pendaftarnya
// (lihat outletBaru) karena kolom depo_id di trx_outlet_baru tidak selalu terisi. funnelOutlet() dan
// trenHarian() scan trx_penjualan/trx_kunjungan yang di produksi bisa sampai jutaan baris (lihat CLAUDE.md
// § Data loading) dan Beranda dibuka jauh lebih sering daripada menu lain, jadi hasilnya di-cache singkat
// (lihat CACHE_TTL) per rentang tanggal + cakupan akun.
class Dashboard
{
    private const CACHE_TTL = 300; // detik; Beranda tidak perlu detik-per-detik real-time.

    /**
     * @param  bool  $nooTerverifikasi  NOO di funnel/tren hanya pendaftaran yang sudah disetujui (Beranda admin, spec 067);
     *                                  Beranda Koordinator menghitung semua pendaftaran karena koordinatorlah yang memantaunya.
     */
    public function __construct(public readonly string $dari, public readonly string $sampai, private readonly ?User $user, private readonly bool $nooTerverifikasi = false) {}

    /**
     * Funnel outlet: NOO (outlet baru didaftarkan dalam rentang tanggal dashboard), AC (pernah dikunjungi,
     * belum pernah effective call), EC (pernah effective call), RO (effective call di ≥2 tanggal berbeda).
     * OSS (outlet AC yang kunjungan terakhirnya tidak tercatat stok produk sendiri) adalah peringatan kualitas,
     * ditampilkan terpisah dari funnel utama — sama dengan cakupan mode 'oss' di SumberOutletStatus (AC saja,
     * bukan semua outlet).
     *
     * Satu query agregat (outlet LEFT JOIN subquery kunjungan-terakhir + subquery EC + subquery stok), bukan
     * 4 query COUNT terpisah yang masing-masing scan ulang trx_penjualan/trx_kunjungan secara penuh.
     *
     * @return array<int, array{key: string, label: string, jumlah: int}>
     */
    public function funnelOutlet(): array
    {
        return Cache::remember($this->kunci('funnel'), self::CACHE_TTL, function () {
            $depo = Cakupan::untuk($this->user)->depoIds();

            $kunjunganTerakhir = DB::table('trx_kunjungan')->whereNotNull('outlet_id')
                ->groupBy('outlet_id')->selectRaw('outlet_id, MAX(id) AS kunjungan_id');

            $ec = DB::table('trx_penjualan')->whereNull('dibatalkan_at')->where('is_effective_call', true)->whereNotNull('outlet_id')
                ->groupBy('outlet_id')->selectRaw('outlet_id, COUNT(DISTINCT tanggal) AS hari_ec');

            $adaStokSendiri = DB::table('trx_stok_outlet')->whereNotNull('produk_id')->where('qty', '>', 0)
                ->groupBy('kunjungan_id')->selectRaw('kunjungan_id, 1 AS ada');

            $baris = $this->outlet($depo)
                ->leftJoinSub($kunjunganTerakhir, 'kt', 'kt.outlet_id', '=', 'o.id')
                ->leftJoinSub($ec, 'e', 'e.outlet_id', '=', 'o.id')
                ->leftJoinSub($adaStokSendiri, 's', 's.kunjungan_id', '=', 'kt.kunjungan_id')
                ->selectRaw('
                    COALESCE(SUM(CASE WHEN e.outlet_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS ec,
                    COALESCE(SUM(CASE WHEN kt.outlet_id IS NOT NULL AND e.outlet_id IS NULL THEN 1 ELSE 0 END), 0) AS ac,
                    COALESCE(SUM(CASE WHEN e.hari_ec >= 2 THEN 1 ELSE 0 END), 0) AS ro,
                    COALESCE(SUM(CASE WHEN kt.outlet_id IS NOT NULL AND e.outlet_id IS NULL AND s.kunjungan_id IS NULL THEN 1 ELSE 0 END), 0) AS oss
                ')->first();

            $jumlahNoo = $this->outletBaru()
                ->whereBetween(DB::raw('DATE(b.recorded_at)'), [$this->dari, $this->sampai])
                ->count();

            return [
                ['key' => 'noo', 'label' => 'Outlet Baru (NOO)', 'jumlah' => $jumlahNoo],
                ['key' => 'ac', 'label' => 'Dikunjungi, Belum EC (AC)', 'jumlah' => (int) $baris->ac],
                ['key' => 'ec', 'label' => 'Effective Call (EC)', 'jumlah' => (int) $baris->ec],
                ['key' => 'ro', 'label' => 'Repeat Order (RO)', 'jumlah' => (int) $baris->ro],
                ['key' => 'oss', 'label' => 'Stok Kosong Saat Kunjungan Terakhir (OSS)', 'jumlah' => (int) $baris->oss],
            ];
        });
    }

    /**
     * Total sales (Rp), jumlah EC, dan jumlah outlet baru didaftarkan (NOO, sama dengan definisi di
     * funnelOutlet) per tanggal dalam rentang, dibatasi cakupan depo. Tanggal tanpa transaksi tetap muncul
     * dengan nilai 0, supaya grafik tidak putus.
     *
     * @return array<int, array{tanggal: string, sales: int, ec: int, noo: int}>
     */
    public function trenHarian(): array
    {
        return Cache::remember($this->kunci('tren'), self::CACHE_TTL, function () {
            $depo = Cakupan::untuk($this->user)->depoIds();

            $baris = DB::table('trx_penjualan as p')
                ->join('mk_karyawan as k', 'k.id', '=', 'p.karyawan_id')
                ->whereBetween('p.tanggal', [$this->dari, $this->sampai])
                ->whereNull('p.dibatalkan_at')
                ->when($depo !== null, fn ($q) => $q->whereIn('k.depo_id', $depo ?: [0]))
                ->groupBy('p.tanggal')
                ->selectRaw('p.tanggal, COALESCE(SUM(p.total), 0) AS sales, SUM(CASE WHEN p.is_effective_call THEN 1 ELSE 0 END) AS ec')
                ->get()->keyBy('tanggal');

            $noo = $this->outletBaru()
                ->whereBetween(DB::raw('DATE(b.recorded_at)'), [$this->dari, $this->sampai])
                ->groupBy(DB::raw('DATE(b.recorded_at)'))
                ->selectRaw('DATE(b.recorded_at) AS tanggal, COUNT(*) AS jumlah')
                ->get()->keyBy('tanggal');

            // Array murni (bukan Collection/stdClass): aman di-cache lewat PHP serialize() apa pun cache
            // driver-nya, dan Inertia men-JSON-kan array persis sama dengan Collection.
            $hasil = [];

            for ($t = CarbonImmutable::parse($this->dari); $t->lte(CarbonImmutable::parse($this->sampai)); $t = $t->addDay()) {
                $tgl = $t->toDateString();
                $b = $baris->get($tgl);
                $hasil[] = [
                    'tanggal' => $tgl, 'sales' => (int) ($b->sales ?? 0), 'ec' => (int) ($b->ec ?? 0),
                    'noo' => (int) ($noo->get($tgl)->jumlah ?? 0),
                ];
            }

            return $hasil;
        });
    }

    /**
     * Penyebaran produk pada rentang (spec 066): outlet unik yang membeli (EC, tidak dibatalkan), outlet yang membeli di
     * ≥2 tanggal berbeda dalam rentang (repeat order), outlet baru didaftarkan (NOO, sama dengan funnelOutlet), dan
     * jumlah outlet terdaftar dalam cakupan (penyebut % jangkauan). Beda dengan funnelOutlet yang EC/RO-nya seumur hidup
     * outlet: di sini dibatasi rentang supaya dua periode bisa dibandingkan. Cakupan lewat depo motoris penjual
     * (sama dengan trenHarian / SummaryDepo). Outlet yang sudah terbukti fiktif (tanda outlet Terbukti) tidak dihitung, dan
     * NOO hanya yang disetujui; yang masih menunggu verifikasi dilaporkan terpisah (noo_menunggu).
     *
     * Kualitas data outlet (spec 067): hasil pemeriksaan tanda outlet yang diputuskan dalam rentang (fiktif_terbukti / fiktif_wajar,
     * lewat dicek_at), pendaftaran NOO yang diverifikasi dalam rentang (noo_disetujui / noo_ditolak, lewat diverifikasi_pada), dan
     * antrean verifikasi saat ini (menunggu_total, menunggu_umur = umur pendaftaran tertua dalam hari; bukan per rentang).
     *
     * @return array{outlet: int, ro: int, noo: int, noo_menunggu: int, outlet_terdaftar: int, fiktif_terbukti: int, fiktif_wajar: int, noo_disetujui: int, noo_ditolak: int, menunggu_total: int, menunggu_umur: ?int}
     */
    public function penyebaran(): array
    {
        return Cache::remember($this->kunci('penyebaran'), self::CACHE_TTL, function () {
            $depo = Cakupan::untuk($this->user)->depoIds();

            $perOutlet = $this->penjualanEc($depo)->groupBy('p.outlet_id')
                ->selectRaw('p.outlet_id, COUNT(DISTINCT p.tanggal) AS hari');

            $baris = DB::query()->fromSub($perOutlet, 'x')
                ->selectRaw('COUNT(*) AS outlet, COALESCE(SUM(CASE WHEN x.hari >= 2 THEN 1 ELSE 0 END), 0) AS ro')->first();

            return [
                'outlet' => (int) $baris->outlet,
                'ro' => (int) $baris->ro,
                'noo' => $this->outletBaru('disetujui')->whereBetween(DB::raw('DATE(b.recorded_at)'), [$this->dari, $this->sampai])->count(),
                'noo_menunggu' => $this->outletBaru('menunggu')->whereBetween(DB::raw('DATE(b.recorded_at)'), [$this->dari, $this->sampai])->count(),
                'outlet_terdaftar' => $this->outlet($depo)->count(),
            ] + $this->kualitasOutlet($depo);
        });
    }

    /** @return array{fiktif_terbukti: int, fiktif_wajar: int, noo_disetujui: int, noo_ditolak: int, menunggu_total: int, menunggu_umur: ?int} */
    private function kualitasOutlet(?array $depo): array
    {
        $periksa = DB::table('tanda_outlet as t')
            ->leftJoin('mk_outlet as o', 'o.id', '=', 't.outlet_id')
            ->leftJoin('mk_area as a', 'a.id', '=', 'o.area_id')
            ->whereIn('t.status', ['terbukti', 'wajar'])
            ->whereBetween('t.dicek_at', ["{$this->dari} 00:00:00", "{$this->sampai} 23:59:59"])
            ->when($depo !== null, fn ($q) => $q->whereIn('a.depo_id', $depo ?: [0]))
            ->groupBy('t.status')->selectRaw('t.status, COUNT(DISTINCT t.outlet_id) AS n')->pluck('n', 'status');

        $verifikasi = $this->outletBaru(semuaStatus: true)->whereIn('b.status', ['disetujui', 'ditolak'])
            ->whereBetween('b.diverifikasi_pada', ["{$this->dari} 00:00:00", "{$this->sampai} 23:59:59"])
            ->groupBy('b.status')->selectRaw('b.status, COUNT(*) AS n')->pluck('n', 'status');

        $menunggu = $this->outletBaru('menunggu')->selectRaw('COUNT(*) AS n, MIN(b.recorded_at) AS tertua')->first();
        $umur = $menunggu->tertua ? (int) CarbonImmutable::parse($menunggu->tertua)->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay()) : null;

        return [
            'fiktif_terbukti' => (int) ($periksa['terbukti'] ?? 0),
            'fiktif_wajar' => (int) ($periksa['wajar'] ?? 0),
            'noo_disetujui' => (int) ($verifikasi['disetujui'] ?? 0),
            'noo_ditolak' => (int) ($verifikasi['ditolak'] ?? 0),
            'menunggu_total' => (int) $menunggu->n,
            'menunggu_umur' => $umur,
        ];
    }

    /**
     * Outlet terjangkau per depo pada rentang (spec 066): outlet unik ber-EC dan motoris yang menjualnya, dikelompokkan
     * per depo motoris. Dibagi per motoris supaya depo besar dan kecil bisa dibandingkan adil. Urut dari terendah.
     *
     * @return list<array{id: int, nama: string, outlet: int, motoris: int, per_motoris: float}>
     */
    public function outletPerDepo(): array
    {
        return Cache::remember($this->kunci('outlet_depo'), self::CACHE_TTL, function () {
            return $this->penjualanEc(Cakupan::untuk($this->user)->depoIds())
                ->join('mk_depo as d', 'd.id', '=', 'k.depo_id')
                ->groupBy('d.id', 'd.nama')
                ->selectRaw('d.id, d.nama, COUNT(DISTINCT p.outlet_id) AS outlet, COUNT(DISTINCT p.karyawan_id) AS motoris')
                ->get()
                ->map(fn ($d) => [
                    'id' => (int) $d->id, 'nama' => $d->nama, 'outlet' => (int) $d->outlet, 'motoris' => (int) $d->motoris,
                    'per_motoris' => $d->motoris > 0 ? round($d->outlet / $d->motoris, 1) : 0.0,
                ])
                ->sortBy([['per_motoris', 'asc'], ['nama', 'asc']])->values()->all();
        });
    }

    /** trx_penjualan (p) effective call yang tidak dibatalkan pada rentang, join motoris (k), dibatasi cakupan depo. */
    private function penjualanEc(?array $depo): Builder
    {
        return DB::table('trx_penjualan as p')
            ->join('mk_karyawan as k', 'k.id', '=', 'p.karyawan_id')
            ->whereBetween('p.tanggal', [$this->dari, $this->sampai])
            ->whereNull('p.dibatalkan_at')
            ->where('p.is_effective_call', true)
            ->whereNotNull('p.outlet_id')
            ->whereNotIn('p.outlet_id', self::outletTerbuktiFiktif())
            ->when($depo !== null, fn ($q) => $q->whereIn('k.depo_id', $depo ?: [0]));
    }

    /** Outlet yang sudah terbukti fiktif (tanda outlet berstatus Terbukti dan belum dicabut), dipakai sebagai subquery pengecualian. */
    public static function outletTerbuktiFiktif(): Builder
    {
        return DB::table('tanda_outlet')->where('status', 'terbukti')->whereNull('dicabut_at')->select('outlet_id');
    }

    /** Kunci cache: dipisah per rentang tanggal + cakupan akun (akun dengan cakupan "semua" berbagi satu entri). */
    private function kunci(string $bagian): string
    {
        $cakupan = Cakupan::untuk($this->user)->kunci();

        return "beranda:{$bagian}:{$this->dari}:{$this->sampai}:{$cakupan}".($this->nooTerverifikasi ? ':v' : '');
    }

    private function outlet(?array $depo): Builder
    {
        return DB::table('mk_outlet as o')
            ->leftJoin('mk_area as a', 'a.id', '=', 'o.area_id')
            ->when($depo !== null, fn ($q) => $q->whereIn('a.depo_id', $depo ?: [0]));
    }

    /**
     * trx_outlet_baru (alias b) dibatasi cakupan lewat motoris yang mendaftarkannya (b.karyawan_id), sama
     * dengan App\Support\Menu\Motoris\SumberOutletNoo (Lap. Outlet NOO) — BUKAN lewat b.depo_id seperti
     * metrik funnel lain, karena kolom itu tidak selalu terisi untuk data outlet baru yang lama/migrasi,
     * sehingga NOO bisa salah tampil 0 untuk akun bercakupan selain "semua" kalau dibatasi lewat depo_id.
     *
     * Di Beranda admin ($nooTerverifikasi) NOO hanya menghitung pendaftaran yang sudah DISETUJUI (spec 067): outlet yang masih
     * menunggu verifikasi atau ditolak belum boleh dihitung sebagai keberhasilan penyebaran.
     *
     * CATATAN: trx_outlet_baru hanya terisi dari pendaftaran outlet lewat aplikasi HP motoris Enesis (lihat
     * App\Http\Controllers\Mobile\OutletController), BUKAN dari riwayat outlet hasil tarikan Marketama
     * (mk_outlet.dibuat_pada, teks bebas "02 Mar 2026 09:23" tanpa parser tanggal) — jadi NOO bisa tetap 0
     * untuk akun yang belum pernah pakai pendaftaran outlet lewat aplikasi ini (keputusan produk: dibiarkan
     * begini, tidak ikut riwayat Marketama).
     */
    private function outletBaru(?string $status = null, bool $semuaStatus = false): Builder
    {
        $status ??= $this->nooTerverifikasi && ! $semuaStatus ? 'disetujui' : null;

        return Cakupan::untuk($this->user)->batasi(DB::table('trx_outlet_baru as b')->join('mk_karyawan as k', 'k.id', '=', 'b.karyawan_id'))
            ->when($status !== null, fn ($q) => $q->where('b.status', $status));
    }
}
