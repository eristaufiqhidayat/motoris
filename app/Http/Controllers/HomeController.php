<?php

namespace App\Http\Controllers;

use App\Support\Akses\Akses;
use App\Support\Menu\JamLokal;
use App\Support\Mobile\Pengaturan;
use App\Support\Motoris\Dashboard;
use App\Support\Motoris\SummaryDepo;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

// Menu Utama › Beranda: dashboard interaktif data motoris, menggantikan halaman placeholder generik slug
// "home" (lihat MenuController) yang sebelumnya tidak punya tabel (config('marketama.pages.home.tables')
// === []). Versi berbeda per cakupan role (lihat Akses::cakupan): akun bercakupan "semua"/"depo" (Super
// Admin, role "Lainnya", dst.) memakai Home/Dashboard — ringkasan nasional (KPI, funnel outlet, tren
// penjualan, depo teratas); akun bercakupan "downline" (Koordinator) memakai Home/DashboardKoordinator —
// tim sendiri (lihat SummaryDepo::detail, dipakai juga oleh Summary All Depo), bukan ranking antar-depo
// yang tidak relevan untuk cakupan satu tim.
class HomeController extends Controller
{
    public const MENU = 'home';

    private const CACHE_TTL = 300; // detik, sama dengan Dashboard::CACHE_TTL.

    private const RENTANG_MAKS_HARI = 62;

    public function index(Request $request): Response
    {
        if (Akses::cakupan($request->user()) === 'downline') {
            return self::koordinator($request);
        }

        [$dari, $sampai] = self::rentang($request);
        $summary = new SummaryDepo($dari, $sampai, $request->user());
        $dashboard = new Dashboard($dari, $sampai, $request->user());

        return Inertia::render('Home/Dashboard', [
            'slug' => self::MENU,
            'judul' => config('marketama.pages.'.self::MENU.'.title') ?? 'Beranda',
            'periode' => self::periode($summary),
            'filter' => ['date_from' => $dari, 'date_to' => $sampai],
            'target' => $summary->target,
            // Berat (query semua depo/outlet/tren); ditunda supaya halaman tampil dulu (lihat Home/Dashboard.vue).
            // kpi & topDepo digabung jadi satu prop (ringkasanDepo) supaya SummaryDepo::kartu() — yang query
            // semua depo/sales — cuma dihitung sekali, bukan sekali per prop.
            'ringkasanDepo' => Inertia::defer(fn () => self::ringkasanDepo($summary, $request), 'ringkasan'),
            'funnel' => Inertia::defer(fn () => $dashboard->funnelOutlet(), 'ringkasan'),
            'tren' => Inertia::defer(fn () => $dashboard->trenHarian(), 'ringkasan'),
        ]);
    }

    /**
     * Beranda Koordinator: status operasional hari ini (absen, checkin, tanda) — koordinator cuma perlu lihat
     * hari ini untuk itu (lihat absenHariIni/checkinOutletHariIni/tandaMotoris/tandaOutlet). Tim Saya dan Tren
     * Harian berbeda: keduanya punya rentang tanggal sendiri yang bisa dipilih bebas (bawaan 7 hari terakhir,
     * lihat rentangTim/rentangTren), karena ringkasan tim & tren memang lebih berarti dilihat dalam rentang,
     * bukan satu hari saja.
     */
    private static function koordinator(Request $request): Response
    {
        $hariIni = Pengaturan::sekarang()->toDateString();
        $summary = new SummaryDepo($hariIni, $hariIni, $request->user());
        $dashboard = new Dashboard($hariIni, $hariIni, $request->user());
        [$trenDari, $trenSampai] = self::rentangFilter($request, 'tren_dari', 'tren_sampai');
        [$timDari, $timSampai] = self::rentangFilter($request, 'tim_dari', 'tim_sampai');
        $depoId = DB::table('mk_karyawan')->where('id', $request->user()?->karyawan_id ?? 0)->value('depo_id');
        $identitas = $depoId ? $summary->depo((int) $depoId) : null;
        $summaryTim = new SummaryDepo($timDari, $timSampai, $request->user());

        return Inertia::render('Home/DashboardKoordinator', [
            'slug' => self::MENU,
            'judul' => config('marketama.pages.'.self::MENU.'.title') ?? 'Beranda',
            'periode' => self::periode($summary),
            'target' => $summary->target,
            'depo' => $identitas,
            // Ringan (dua COUNT); dikirim langsung, tidak ikut ditunda, supaya status absen hari ini langsung terlihat.
            'absenHariIni' => self::absenHariIni($request),
            'checkinOutlet' => self::checkinOutletHariIni($request),
            // Kartu Motoris/Outlet Ditandai hanya bisa diklik (membuka drawer rincian) bila akun boleh membuka
            // menu itu — diperiksa lagi di controller drawer-nya (defense in depth), ini cuma untuk tampilan.
            'tautan' => collect(['motoris_ditandai', 'outlet_ditandai'])
                ->mapWithKeys(fn ($slug) => [$slug => Akses::bisa($request->user(), $slug) ? "/{$slug}" : null])->all(),
            'timFilter' => ['dari' => $timDari, 'sampai' => $timSampai],
            // Berat (query absen/kunjungan/penjualan tim); ditunda supaya halaman tampil dulu.
            'detail' => Inertia::defer(fn () => self::detailTim($summaryTim, $identitas, $request), 'ringkasan'),
            'funnel' => Inertia::defer(fn () => $dashboard->funnelOutlet(), 'ringkasan'),
            'trenFilter' => ['dari' => $trenDari, 'sampai' => $trenSampai],
            'tren' => Inertia::defer(fn () => (new Dashboard($trenDari, $trenSampai, $request->user()))->trenHarian(), 'ringkasan'),
            'tandaMotoris' => Inertia::defer(fn () => self::tandaMotoris($request), 'ringkasan'),
            'tandaOutlet' => Inertia::defer(fn () => self::tandaOutlet($request), 'ringkasan'),
        ]);
    }

    /**
     * Rentang tanggal yang bisa dipilih bebas di Beranda Koordinator (Tim Saya: tim_dari/tim_sampai; Tren
     * Harian: tren_dari/tren_sampai). Bawaan 7 hari terakhir; terbalik ditukar; maksimal RENTANG_MAKS_HARI
     * hari (batas awal digeser) supaya query detail tim/penjualan tidak melebar tanpa batas.
     *
     * @return array{0: string, 1: string}
     */
    private static function rentangFilter(Request $request, string $kunciDari, string $kunciSampai): array
    {
        $hariIni = Pengaturan::sekarang()->toDateString();
        $valid = fn (?string $v): bool => (bool) $v && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
        $dari = $valid($request->query($kunciDari)) ? $request->query($kunciDari) : Carbon::parse($hariIni)->subDays(6)->toDateString();
        $sampai = $valid($request->query($kunciSampai)) ? $request->query($kunciSampai) : $hariIni;

        if ($sampai < $dari) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        $palingAwal = Carbon::parse($sampai)->subDays(self::RENTANG_MAKS_HARI - 1)->toDateString();

        return [max($dari, $palingAwal), $sampai];
    }

    /**
     * Sama dengan SummaryDepoController@detail, tapi untuk depo tempat koordinator yang login bertugas,
     * di-cache per akun (bukan per depo) karena hasilnya sudah dibatasi cakupan downline-nya.
     *
     * @return array{total: object, tim: Collection<int, object>}|null
     */
    private static function detailTim(SummaryDepo $summary, ?object $identitas, Request $request): ?array
    {
        if (! $identitas) {
            return null;
        }

        $kunci = "beranda:tim:{$summary->dari}:{$summary->sampai}:user_{$request->user()?->id}";

        return Cache::remember($kunci, self::CACHE_TTL, fn () => $summary->detail($identitas->id));
    }

    /**
     * Jumlah sales motoris aktif di tim (mk_karyawan.koordinator_id) yang sudah absen datang (trx_absensi.masuk_at
     * terisi) hari ini, sama dengan "checked_in" pada App\Http\Controllers\Mobile\KoordinatorController@tim di
     * aplikasi HP.
     *
     * @return array{hadir: int, total: int, tanggal: string}
     */
    private static function absenHariIni(Request $request): array
    {
        $hariIni = Pengaturan::sekarang()->toDateString();
        $ids = self::salesTim($request);

        $hadir = $ids->isEmpty() ? 0
            : DB::table('trx_absensi')->whereIn('karyawan_id', $ids)->where('tanggal', $hariIni)->whereNotNull('masuk_at')->count();

        return ['hadir' => $hadir, 'total' => $ids->count(), 'tanggal' => $hariIni];
    }

    /**
     * Jumlah sales motoris aktif di tim yang sudah/belum checkin outlet hari ini (ada trx_kunjungan hari ini).
     * Aturan bisnis (BUSINESS_RULES §5): checkin outlet paling lambat jam 11.00 waktu setempat (zona mobile.zona_waktu,
     * batas dari mobile.checkin_pagi_selesai, sama dengan menu Sales Checkin Outlet Pagi); lewat jam itu motoris tidak
     * bisa checkin sama sekali hari itu, jadi "belum" yang tersisa setelah batas = tidak akan checkin hari ini ('lewat').
     *
     * @return array{sudah: int, belum: int, total: int, batas: string, lewat: bool}
     */
    private static function checkinOutletHariIni(Request $request): array
    {
        $sekarang = Pengaturan::sekarang();
        $ids = self::salesTim($request);
        preg_match('/^(\d{1,2})[:.](\d{2})$/', trim((string) config('mobile.checkin_pagi_selesai')), $m);
        $batas = isset($m[1]) ? sprintf('%02d:%s', $m[1], $m[2]) : '11:00';

        $sudah = $ids->isEmpty() ? 0
            : DB::table('trx_kunjungan')->whereIn('karyawan_id', $ids)->where('tanggal', $sekarang->toDateString())
                ->whereNotNull('checkin_at')->distinct()->count('karyawan_id');

        return [
            'sudah' => $sudah, 'belum' => $ids->count() - $sudah, 'total' => $ids->count(), 'batas' => $batas,
            'lewat' => $sekarang->format('H:i') >= $batas,
        ];
    }

    /** Id sales motoris aktif bawahan koordinator yang login (mk_karyawan.koordinator_id). */
    private static function salesTim(Request $request): Collection
    {
        $karyawanId = $request->user()?->karyawan_id;

        return $karyawanId
            ? DB::table('mk_karyawan')->where('koordinator_id', $karyawanId)->where('kategori', 'sales')->where('aktif', true)->pluck('id')
            : collect();
    }

    /**
     * Isi drawer kartu "Absen Datang": tiap sales di tim beserta status absen datang hari ini, belum dulu
     * di atas supaya yang perlu ditindaklanjuti terlihat duluan.
     */
    public function absenDetail(Request $request): JsonResponse
    {
        $hariIni = Pengaturan::sekarang()->toDateString();
        $ids = self::salesTim($request);
        $nama = $ids->isEmpty() ? collect() : DB::table('mk_karyawan')->whereIn('id', $ids)->orderBy('nama')->pluck('nama', 'id');
        $absen = $ids->isEmpty() ? collect()
            : DB::table('trx_absensi')->whereIn('karyawan_id', $ids)->where('tanggal', $hariIni)->get()->keyBy('karyawan_id');

        $baris = $nama->map(fn ($nama, $id) => [
            'nama' => $nama,
            'sudah' => isset($absen[$id]) && $absen[$id]->masuk_at !== null,
            'waktu' => JamLokal::format($absen[$id]->masuk_at ?? null, 'H:i'),
            'terlambat' => (bool) ($absen[$id]->terlambat ?? false),
        ])->values()->sortBy(fn ($b) => $b['sudah'] ? 1 : 0)->values();

        return response()->json(['baris' => $baris]);
    }

    /** Isi drawer kartu "Checkin Outlet": tiap sales di tim beserta jam checkin outlet pertama hari ini, bila ada. */
    public function checkinOutletDetail(Request $request): JsonResponse
    {
        $hariIni = Pengaturan::sekarang()->toDateString();
        $ids = self::salesTim($request);
        $nama = $ids->isEmpty() ? collect() : DB::table('mk_karyawan')->whereIn('id', $ids)->orderBy('nama')->pluck('nama', 'id');
        $pertama = $ids->isEmpty() ? collect()
            : DB::table('trx_kunjungan')->whereIn('karyawan_id', $ids)->where('tanggal', $hariIni)->whereNotNull('checkin_at')
                ->groupBy('karyawan_id')->selectRaw('karyawan_id, MIN(checkin_at) AS waktu')->get()->keyBy('karyawan_id');

        $baris = $nama->map(fn ($nama, $id) => [
            'nama' => $nama,
            'sudah' => isset($pertama[$id]),
            'waktu' => JamLokal::format($pertama[$id]->waktu ?? null, 'H:i'),
        ])->values()->sortBy(fn ($b) => $b['sudah'] ? 1 : 0)->values();

        return response()->json(['baris' => $baris]);
    }

    /**
     * Isi drawer kartu "Motoris Ditandai": tanda aktif pada tim, sama dengan menu Rekap Motoris Ditandai
     * (App\Support\Menu\Motoris\SumberTandaMotoris) — hanya kolom yang perlu buat tinjauan cepat.
     */
    public function motorisDitandaiDetail(Request $request): JsonResponse
    {
        abort_unless(Akses::bisa($request->user(), 'motoris_ditandai'), 403);

        $baris = Akses::batasiKaryawan(DB::table('tanda_motoris as tm')->join('mk_karyawan as k', 'k.id', '=', 'tm.karyawan_id'), $request->user())
            ->whereNull('tm.dicabut_at')->orderByDesc('tm.tanggal')->orderByDesc('tm.id')
            ->get(['tm.id', 'k.nama as motoris', 'tm.tanggal', 'tm.kategori', 'tm.dibuat_nama as ditandai_oleh'])
            ->map(fn ($b) => [
                'motoris' => $b->motoris,
                'kategori' => config("marketama.tanda_motoris.{$b->kategori}", $b->kategori),
                'tanggal' => $b->tanggal,
                'ditandai_oleh' => $b->ditandai_oleh,
            ]);

        return response()->json(['baris' => $baris]);
    }

    /**
     * Isi drawer kartu "Outlet Ditandai": tanda aktif pada outlet milik tim, sama dengan menu Rekap Outlet
     * Ditandai (App\Support\Menu\SumberTandaOutlet) — cakupannya sama dengan tandaOutlet() di atas.
     */
    public function outletDitandaiDetail(Request $request): JsonResponse
    {
        abort_unless(Akses::bisa($request->user(), 'outlet_ditandai'), 403);

        $q = self::outletTimQuery($request);
        $baris = $q === null ? collect() : $q->orderByDesc('t.created_at')->orderByDesc('t.id')
            ->get(['t.id', 'o.nama as outlet', 't.kategori', 't.dibuat_nama as ditandai_oleh', 't.created_at'])
            ->map(fn ($b) => [
                'outlet' => $b->outlet,
                'kategori' => config("marketama.tanda_outlet.{$b->kategori}", $b->kategori),
                'waktu' => JamLokal::format($b->created_at, 'j M Y H:i', 'id'),
                'ditandai_oleh' => $b->ditandai_oleh,
            ]);

        return response()->json(['baris' => $baris]);
    }

    /**
     * Tanda motoris (tanda_motoris, lihat App\Support\Menu\Motoris\SumberTandaMotoris) yang masih aktif (belum
     * dicabut) pada tim koordinator — lepas dari tanggal tanda itu sendiri, sama seperti tandaOutlet, supaya
     * tanda lama yang belum dicabut tidak hilang dari ringkasan ini. Cakupannya sama dengan "Rekap Motoris
     * Ditandai" (Akses::batasiKaryawan), supaya berapa pun koordinator di-nest tetap melihat tim sendiri saja.
     *
     * @return array{motoris: int, total: int}
     */
    private static function tandaMotoris(Request $request): array
    {
        $kunci = 'beranda:tanda:user_'.$request->user()?->id;

        return Cache::remember($kunci, self::CACHE_TTL, function () use ($request) {
            $q = Akses::batasiKaryawan(DB::table('tanda_motoris as tm')->join('mk_karyawan as k', 'k.id', '=', 'tm.karyawan_id'), $request->user())
                ->whereNull('tm.dicabut_at');

            return ['motoris' => (int) (clone $q)->distinct()->count('tm.karyawan_id'), 'total' => (int) (clone $q)->count()];
        });
    }

    /**
     * Tanda outlet (tanda_outlet, lihat App\Support\Menu\SumberTandaOutlet) yang masih aktif (belum dicabut) pada
     * outlet milik tim koordinator — dicocokkan lewat motoris pendaftar outlet itu (trx_outlet_baru.karyawan_id,
     * atau mk_outlet.dibuat_oleh sebagai cadangan untuk data lama tanpa trx_outlet_baru), PERSIS sama dengan kolom
     * "Koordinator" di menu Rekap Outlet Ditandai (SumberTandaOutlet) — bukan lewat depo outlet, yang bisa beda
     * kalau area/depo outletnya tidak lengkap sehingga sebetulnya milik tim tapi tidak ketemu lewat depo.
     *
     * @return array{outlet: int, total: int}
     */
    private static function tandaOutlet(Request $request): array
    {
        $kunci = 'beranda:tanda_outlet:user_'.$request->user()?->id;

        return Cache::remember($kunci, self::CACHE_TTL, function () use ($request) {
            $q = self::outletTimQuery($request);

            if ($q === null) {
                return ['outlet' => 0, 'total' => 0];
            }

            return ['outlet' => (int) (clone $q)->distinct()->count('t.outlet_id'), 'total' => (int) (clone $q)->count()];
        });
    }

    /**
     * tanda_outlet (alias t) aktif pada outlet milik tim koordinator — dicocokkan lewat motoris pendaftar
     * outlet (lihat catatan di tandaOutlet()). Null bila tim kosong (tidak ada karyawan dalam cakupan).
     */
    private static function outletTimQuery(Request $request): ?Builder
    {
        $tim = Akses::batasiKaryawan(DB::table('mk_karyawan as k'), $request->user())->pluck('k.nama', 'k.id');

        if ($tim->isEmpty()) {
            return null;
        }

        $ids = $tim->keys();
        $nama = $tim->values();

        return DB::table('tanda_outlet as t')->join('mk_outlet as o', 'o.id', '=', 't.outlet_id')
            ->whereNull('t.dicabut_at')
            ->where(fn ($w) => $w->whereExists(fn ($e) => $e->selectRaw('1')->from('trx_outlet_baru as ob')
                ->whereColumn('ob.outlet_id', 'o.id')->whereIn('ob.karyawan_id', $ids))
                ->orWhereIn('o.dibuat_oleh', $nama));
    }

    /**
     * kpi (KPI nasional) + topDepo (8 depo EC Achievement tertinggi), dari satu kartu() yang sama — lihat catatan
     * di atas. Hasilnya singkat di-cache per rentang tanggal + cakupan akun, sama seperti Dashboard::funnelOutlet.
     *
     * @return array{kpi: array<string, int|float>, topDepo: array<int, array<string, mixed>>}
     */
    private static function ringkasanDepo(SummaryDepo $summary, Request $request): array
    {
        $cakupan = Akses::cakupan($request->user()) === 'semua' ? 'semua' : 'user_'.($request->user()?->id ?? 0);
        $kunci = "beranda:depo:{$summary->dari}:{$summary->sampai}:{$cakupan}";

        return Cache::remember($kunci, self::CACHE_TTL, function () use ($summary) {
            $kartu = $summary->kartu();

            // topDepo diubah ke array murni (bukan Collection berisi stdClass): aman di-cache lewat PHP
            // serialize() apa pun cache driver-nya, dan Inertia men-JSON-kan array persis sama dengan Collection.
            return [
                'kpi' => self::kpi($kartu, $summary->target),
                'topDepo' => $kartu->take(8)->values()->map(fn ($d) => (array) $d)->all(),
            ];
        });
    }

    /**
     * KPI nasional: total dari kartu per depo (lihat SummaryDepo::kartu), lalu AVG dan achievement dihitung
     * ulang dari total gabungan — rumus yang sama dengan SummaryDepo::ringkas, hanya pada skala nasional.
     *
     * @param  array{call: float, ec: float, ipt: float}  $target
     * @return array<string, int|float>
     */
    private static function kpi(Collection $kartu, array $target): array
    {
        $bagi = fn (float|int $a, float|int $b, int $d = 1): float => $b > 0 ? round($a / $b, $d) : 0.0;

        $hariKerja = (int) $kartu->sum('hari_kerja');
        $call = (int) $kartu->sum('call');
        $ec = (int) $kartu->sum('ec');
        $sales = (int) $kartu->sum('sales');
        $sku = (int) $kartu->sum('sku');
        $point = (int) $kartu->sum('point');

        $avgCall = $bagi($call, $hariKerja);
        $avgEc = $bagi($ec, $hariKerja);
        $avgIpt = $bagi($sku, $ec);

        return [
            'depo_aktif' => $kartu->count(),
            'motoris_aktif' => (int) $kartu->sum('total_motoris'),
            'sk_aktif' => (int) $kartu->sum('total_sk'),
            'hari_kerja' => $hariKerja,
            'call' => $call,
            'ec' => $ec,
            'sales' => $sales,
            'sku' => $sku,
            'point' => $point,
            'avg_call' => $avgCall,
            'avg_ec' => $avgEc,
            'avg_sales' => (int) round($bagi($sales, $hariKerja, 2)),
            'avg_ipt' => $avgIpt,
            'call_achievement' => $bagi($avgCall * 100, $target['call']),
            'ec_achievement' => $bagi($avgEc * 100, $target['ec']),
            'ipt_achievement' => $bagi($avgIpt * 100, $target['ipt']),
        ];
    }

    private static function periode(SummaryDepo $summary): array
    {
        $tanggal = fn (string $t) => Carbon::parse($t)->locale('id')->translatedFormat('d M Y');

        return [
            'dari' => $tanggal($summary->dari), 'sampai' => $tanggal($summary->sampai),
            'sama' => $summary->dari === $summary->sampai,
        ];
    }

    /** Bawaan 7 hari terakhir (beda dengan SummaryDepoController::rentang yang bawaannya hari ini saja), supaya tren harian punya isi tanpa filter. */
    public static function rentang(Request $request): array
    {
        $hariIni = Pengaturan::sekarang()->toDateString();
        $bawaanDari = Carbon::parse($hariIni)->subDays(6)->toDateString();
        $valid = fn (?string $v): bool => (bool) $v && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
        $dari = $valid($request->query('date_from')) ? $request->query('date_from') : $bawaanDari;
        $sampai = $valid($request->query('date_to')) ? $request->query('date_to') : $hariIni;

        return $sampai < $dari ? [$sampai, $dari] : [$dari, $sampai];
    }
}
