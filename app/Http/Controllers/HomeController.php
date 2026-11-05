<?php

namespace App\Http\Controllers;

use App\Support\Akses\Akses;
use App\Support\Kendala\LaporanKendala;
use App\Support\Menu\JamLokal;
use App\Support\Mobile\Pengaturan;
use App\Support\Motoris\Dashboard;
use App\Support\Motoris\KinerjaKoordinator;
use App\Support\Motoris\SummaryDepo;
use App\Support\Tracking\SinkronTemuan;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    // Beranda admin (spec 066): seluruh isi memakai periode tetap 30 hari terakhir, dibanding 30 hari sebelumnya.
    private const RENTANG_BERANDA_HARI = 30;

    // Sinkronisasi Data menjalankan deteksi per motoris per tanggal dalam satu permintaan, jadi rentangnya dibatasi pendek.
    private const RENTANG_TEMUAN_MAKS_HARI = 7;

    // Tab Beranda admin (spec 066/067): Ringkasan sebagai tab utama. Semua data tetap dimuat (tertunda dan di-cache), pergantian tab
    // dilakukan di browser; ?tab= hanya menentukan tab yang terbuka saat halaman dimuat.
    public const TAB_BERANDA = ['ringkasan', 'outlet', 'motoris', 'koordinator'];

    public function index(Request $request): Response
    {
        if (Akses::cakupan($request->user()) === 'downline') {
            return self::koordinator($request);
        }

        [$dari, $sampai] = self::periodeBeranda();
        $summary = new SummaryDepo($dari, $sampai, $request->user());
        $dashboard = new Dashboard($dari, $sampai, $request->user(), nooTerverifikasi: true);

        return Inertia::render('Home/Dashboard', [
            'slug' => self::MENU,
            'judul' => config('marketama.pages.'.self::MENU.'.title') ?? 'Beranda',
            'periode' => self::periode($summary),
            'tab' => in_array($request->query('tab'), self::TAB_BERANDA, true) ? $request->query('tab') : self::TAB_BERANDA[0],
            'target' => $summary->target,
            // Kehadiran hari ini: motoris aktif yang sudah absen datang di depo dibanding seluruh motoris aktif (ringan, dua COUNT).
            'absenHariIni' => self::absenHariIniAdmin($request),
            // Checkin pagi: motoris aktif yang sudah/belum checkin outlet pertama (batas jam 11 waktu setempat).
            'checkinPagi' => self::checkinPagiAdmin($request),
            // Kartu Laporan Kendala (spec 064): ringan, null bila akun tanpa hak Lihat laporan_kendala.
            'kendala' => LaporanKendala::ringkasan($request->user()),
            // Kartu Perlu Perhatian bertaut ke menu rinciannya hanya bila akun boleh membuka menu itu.
            'tautan' => collect(['laporan_kendala', 'outlet_oss', 'summary_all_depo', 'motoris_ditandai', 'outlet_all', 'outlet_ditandai'])
                ->mapWithKeys(fn ($slug) => [$slug => Akses::bisa($request->user(), $slug) ? "/{$slug}" : null])->all(),
            // Berat (query semua depo/outlet/tren); ditunda supaya halaman tampil dulu (lihat Home/Dashboard.vue).
            // kpi & depoTertinggal digabung jadi satu prop (ringkasanDepo) supaya SummaryDepo::kartu() — yang query
            // semua depo/sales — cuma dihitung sekali, bukan sekali per prop.
            'ringkasanDepo' => Inertia::defer(fn () => self::ringkasanDepo($summary, $request), 'ringkasan'),
            'funnel' => Inertia::defer(fn () => $dashboard->funnelOutlet(), 'ringkasan'),
            'tren' => Inertia::defer(fn () => $dashboard->trenHarian(), 'ringkasan'),
            'outletDepo' => Inertia::defer(fn () => $dashboard->outletPerDepo(), 'ringkasan'),
            'tandaMotoris' => Inertia::defer(fn () => self::tandaMotoris($request), 'ringkasan'),
            // Penyebaran & aktivitas (spec 066): 30 hari terakhir vs 30 hari sebelumnya, grup defer sendiri. Periode ini
            // memakai ulang cache ringkasanDepo dan Dashboard, jadi query tambahannya hanya untuk 30 hari sebelumnya.
            'perbandingan' => Inertia::defer(fn () => self::perbandinganNasional($summary, $dashboard, $request), 'perbandingan'),
            // Kinerja Koordinator (spec 067): grup defer sendiri; tindak lanjut temuan sistem, hasil tim, dan join visit.
            'koordinator' => Inertia::defer(fn () => self::kinerjaKoordinator($request), 'koordinator'),
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
        [$temuanDari, $temuanSampai] = self::rentangFilter($request, 'temuan_dari', 'temuan_sampai', self::RENTANG_TEMUAN_MAKS_HARI);
        $depoId = DB::table('mk_karyawan')->where('id', $request->user()?->karyawan_id ?? 0)->value('depo_id');
        $identitas = $depoId ? $summary->depo((int) $depoId) : null;
        $summaryTim = new SummaryDepo($timDari, $timSampai, $request->user());

        return Inertia::render('Home/DashboardKoordinator', [
            'slug' => self::MENU,
            'judul' => config('marketama.pages.'.self::MENU.'.title') ?? 'Beranda',
            'periode' => self::periode($summary),
            'target' => $summary->target,
            'depo' => $identitas,
            // Kartu Laporan Kendala (spec 064): ringan, null bila akun tanpa hak Lihat laporan_kendala.
            'kendala' => LaporanKendala::ringkasan($request->user()),
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
            // Temuan Sistem: antrean tanda Perlu dicek dari tombol Sinkronisasi Data (ringan), lihat sinkronTemuan/temuan.
            // temuanFilter = rentang yang disinkronkan (tanda motoris per tanggal), bukan penyaring antrean.
            'temuanFilter' => ['dari' => $temuanDari, 'sampai' => $temuanSampai, 'maks_hari' => self::RENTANG_TEMUAN_MAKS_HARI],
            'temuan' => self::temuan($request, $temuanDari, $temuanSampai),
        ]);
    }

    /**
     * Tombol "Sinkronisasi Data" di kartu Temuan Sistem Beranda Koordinator (spec 062): tambahkan tanda Perlu dicek yang
     * belum ada untuk tim — diduga lokasi palsu per motoris-hari pada rentang itu, dan titik outlet diduga fiktif sampai
     * antrean tim penuh (lihat SinkronTemuan) — lalu kembali ke beranda dengan rentang yang sama. Hanya untuk cakupan
     * downline: cakupan "semua" akan memeriksa seluruh motoris nasional dalam satu permintaan.
     */
    public function sinkronTemuan(Request $request): RedirectResponse
    {
        abort_unless(Akses::cakupan($request->user()) === 'downline', 403);

        $hariIni = Pengaturan::sekarang()->toDateString();
        $data = $request->validate([
            'dari' => ['required', 'date_format:Y-m-d', 'before_or_equal:sampai'],
            'sampai' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$hariIni],
        ]);

        if (Carbon::parse($data['dari'])->diffInDays(Carbon::parse($data['sampai'])) >= self::RENTANG_TEMUAN_MAKS_HARI) {
            return back()->withErrors(['dari' => 'Rentang sinkronisasi maksimal '.self::RENTANG_TEMUAN_MAKS_HARI.' hari.']);
        }

        $hasil = SinkronTemuan::jalankan(self::motorisCakupan($request), $data['dari'], $data['sampai'], $request->user());

        // Tanda baru langsung terlihat di kartu Motoris/Outlet Ditandai (di-cache CACHE_TTL, lihat tandaMotoris/tandaOutlet).
        self::lupakanTanda($request);

        $pesan = "Sinkronisasi selesai: {$hasil['diperiksa']} hari motoris diperiksa"
            .($hasil['dilewati'] ? ", {$hasil['dilewati']} dilewati karena sudah diperiksa" : '')
            .". Perlu dicek baru: {$hasil['tanda_motoris']} motoris, {$hasil['titik']} titik outlet ({$hasil['tanda_outlet']} outlet).";

        return redirect()->route('home', ['temuan_dari' => $data['dari'], 'temuan_sampai' => $data['sampai']])->with('sukses', $pesan);
    }

    /** Buang cache kartu Motoris/Outlet Ditandai akun ini (setelah sinkronisasi atau pemeriksaan tanda). */
    public static function lupakanTanda(Request $request): void
    {
        Cache::forget('beranda:tanda:user_'.$request->user()?->id);
        Cache::forget('beranda:tanda_outlet:user_'.$request->user()?->id);
    }

    /** Id sales motoris aktif dalam cakupan akun (Akses::batasiKaryawan, jadi koordinator bertingkat tetap tim sendiri). */
    private static function motorisCakupan(Request $request): array
    {
        return Akses::batasiKaryawan(DB::table('mk_karyawan as k'), $request->user())
            ->where('k.kategori', 'sales')->where('k.aktif', true)->pluck('k.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Antrean Perlu dicek tim: tanda motoris (terbaru dulu) dan titik outlet (satu baris per kelompok, skor tertinggi
     * dulu), beserta cakupan pemeriksaan motoris-hari pada rentang sinkronisasi.
     *
     * @return array{motoris: Collection<int, array<string, mixed>>, titik: Collection<int, array<string, mixed>>, pemeriksaan: array{diperiksa: int, total: int, terakhir: ?string}}
     */
    private static function temuan(Request $request, string $dari, string $sampai): array
    {
        $motoris = Akses::batasiKaryawan(DB::table('tanda_motoris as tm')->join('mk_karyawan as k', 'k.id', '=', 'tm.karyawan_id'), $request->user())
            ->where('tm.status', 'perlu_dicek')
            ->orderByDesc('tm.tanggal')->orderBy('k.nama')
            ->get(['tm.id', 'tm.karyawan_id', 'k.nama as motoris', 'tm.tanggal', 'tm.kategori', 'tm.catatan'])
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'karyawan_id' => (int) $t->karyawan_id,
                'motoris' => $t->motoris,
                'tanggal' => substr((string) $t->tanggal, 0, 10),
                'kategori_label' => config("marketama.tanda_motoris.{$t->kategori}", $t->kategori),
                'catatan' => $t->catatan,
            ]);

        $ids = self::motorisCakupan($request);
        $titik = SinkronTemuan::tandaOutletTim($ids)->join('mk_outlet as o', 'o.id', '=', 't.outlet_id')
            ->where('t.status', 'perlu_dicek')->whereNotNull('t.kelompok')
            ->orderByDesc('t.skor')->orderBy('t.kelompok')->orderBy('o.nama')
            ->get(['t.id', 't.kelompok', 't.skor', 't.catatan', 't.outlet_id', 'o.nama', 'o.dibuat_oleh', 'o.latitude', 'o.longitude'])
            ->groupBy('kelompok')
            ->map(function ($g) {
                $o = $g->first();

                return [
                    // Tanda mana pun di kelompok; memeriksanya menutup seluruh kelompok (TandaOutletController@periksa).
                    'tanda_id' => (int) $o->id,
                    'kelompok' => $o->kelompok,
                    'skor' => (int) $o->skor,
                    'catatan' => $o->catatan,
                    'pendaftar' => $o->dibuat_oleh,
                    'jumlah' => $g->count(),
                    'outlet' => $g->pluck('nama')->take(3)->values(),
                    'acuan' => ['id' => (int) $o->outlet_id, 'nama' => $o->nama, 'latitude' => (float) $o->latitude, 'longitude' => (float) $o->longitude],
                ];
            })->values();

        $diperiksa = DB::table('pemeriksaan_motoris')->whereIn('karyawan_id', $ids ?: [0])->whereBetween('tanggal', [$dari, $sampai]);
        $hari = Carbon::parse($dari)->diffInDays(Carbon::parse($sampai)) + 1;

        return [
            'motoris' => $motoris,
            'titik' => $titik,
            'antrean_titik' => (int) config('marketama.tanda_outlet_sistem.antrean_titik'),
            'pemeriksaan' => [
                'diperiksa' => (int) (clone $diperiksa)->count(),
                'total' => (int) (count($ids) * $hari),
                'terakhir' => JamLokal::format((clone $diperiksa)->max('diperiksa_at'), 'j M Y H:i', 'id'),
            ],
        ];
    }

    /**
     * Rentang tanggal yang bisa dipilih bebas di Beranda Koordinator (Tim Saya: tim_dari/tim_sampai; Tren
     * Harian: tren_dari/tren_sampai; Temuan Sistem: temuan_dari/temuan_sampai). Bawaan 7 hari terakhir; terbalik
     * ditukar; maksimal $maksHari hari (bawaan RENTANG_MAKS_HARI, batas awal digeser) supaya query detail
     * tim/penjualan tidak melebar tanpa batas.
     *
     * @return array{0: string, 1: string}
     */
    private static function rentangFilter(Request $request, string $kunciDari, string $kunciSampai, int $maksHari = self::RENTANG_MAKS_HARI, int $bawaanHari = 7): array
    {
        $hariIni = Pengaturan::sekarang()->toDateString();
        $valid = fn (?string $v): bool => (bool) $v && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
        $dari = $valid($request->query($kunciDari)) ? $request->query($kunciDari) : Carbon::parse($hariIni)->subDays($bawaanHari - 1)->toDateString();
        $sampai = $valid($request->query($kunciSampai)) ? $request->query($kunciSampai) : $hariIni;

        if ($sampai < $dari) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        $palingAwal = Carbon::parse($sampai)->subDays($maksHari - 1)->toDateString();

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

        // Diubah ke array murni (bukan Collection/stdClass): cache.serializable_classes=false membuat kelas-kelas itu
        // kembali sebagai __PHP_Incomplete_Class dari cache, sehingga tim di Vue bukan array.
        return Cache::remember($kunci, self::CACHE_TTL, fn () => json_decode(json_encode($summary->detail($identitas->id)), true));
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
     * Beranda admin: jumlah sales motoris aktif dalam cakupan akun (Akses::batasiKaryawan) yang sudah absen datang
     * hari ini (trx_absensi.masuk_at terisi), dibanding seluruh motoris aktif. Aturan hadir sama dengan absenHariIni.
     *
     * @return array{hadir: int, total: int, tanggal: string}
     */
    private static function absenHariIniAdmin(Request $request): array
    {
        $hariIni = Pengaturan::sekarang()->toDateString();
        $motoris = fn () => Akses::batasiKaryawan(DB::table('mk_karyawan as k'), $request->user())
            ->where('k.kategori', 'sales')->where('k.aktif', true);

        $hadir = (int) $motoris()->whereExists(fn ($e) => $e->selectRaw('1')->from('trx_absensi as a')
            ->whereColumn('a.karyawan_id', 'k.id')->where('a.tanggal', $hariIni)->whereNotNull('a.masuk_at'))->count();

        return ['hadir' => $hadir, 'total' => (int) $motoris()->count(), 'tanggal' => $hariIni];
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
        $batas = self::batasCheckinPagi();

        $sudah = $ids->isEmpty() ? 0
            : DB::table('trx_kunjungan')->whereIn('karyawan_id', $ids)->where('tanggal', $sekarang->toDateString())
                ->whereNotNull('checkin_at')->distinct()->count('karyawan_id');

        return [
            'sudah' => $sudah, 'belum' => $ids->count() - $sudah, 'total' => $ids->count(), 'batas' => $batas,
            'lewat' => $sekarang->format('H:i') >= $batas,
        ];
    }

    /** Batas checkin outlet pertama (HH:MM, waktu setempat) dari mobile.checkin_pagi_selesai; bawaan 11:00. */
    private static function batasCheckinPagi(): string
    {
        preg_match('/^(\d{1,2})[:.](\d{2})$/', trim((string) config('mobile.checkin_pagi_selesai')), $m);

        return isset($m[1]) ? sprintf('%02d:%s', $m[1], $m[2]) : '11:00';
    }

    /**
     * Beranda admin: motoris aktif dalam cakupan akun yang sudah/belum checkin outlet hari ini (ada trx_kunjungan
     * dengan checkin_at). Aturan batas jam sama dengan checkinOutletHariIni: lewat batas, yang belum = tidak akan checkin.
     *
     * @return array{sudah: int, belum: int, total: int, batas: string, lewat: bool}
     */
    private static function checkinPagiAdmin(Request $request): array
    {
        $sekarang = Pengaturan::sekarang();
        $batas = self::batasCheckinPagi();
        $motoris = fn () => Akses::batasiKaryawan(DB::table('mk_karyawan as k'), $request->user())
            ->where('k.kategori', 'sales')->where('k.aktif', true);

        $sudah = (int) $motoris()->whereExists(fn ($e) => $e->selectRaw('1')->from('trx_kunjungan as v')
            ->whereColumn('v.karyawan_id', 'k.id')->where('v.tanggal', $sekarang->toDateString())->whereNotNull('v.checkin_at'))->count();
        $total = (int) $motoris()->count();

        return ['sudah' => $sudah, 'belum' => $total - $sudah, 'total' => $total, 'batas' => $batas, 'lewat' => $sekarang->format('H:i') >= $batas];
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
     * Isi drawer kartu "Motoris Ditandai": tanda Perlu dicek pada tim (spec 062), sama dengan menu Rekap Motoris
     * Ditandai (App\Support\Menu\Motoris\SumberTandaMotoris) — hanya kolom yang perlu buat tinjauan cepat.
     */
    public function motorisDitandaiDetail(Request $request): JsonResponse
    {
        abort_unless(Akses::bisa($request->user(), 'motoris_ditandai'), 403);

        $baris = Akses::batasiKaryawan(DB::table('tanda_motoris as tm')->join('mk_karyawan as k', 'k.id', '=', 'tm.karyawan_id'), $request->user())
            ->where('tm.status', 'perlu_dicek')->orderByDesc('tm.tanggal')->orderByDesc('tm.id')
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
     * Isi drawer kartu "Outlet Ditandai": tanda Perlu dicek pada outlet milik tim, sama dengan menu Rekap Outlet
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
     * Tanda motoris (tanda_motoris, lihat App\Support\Menu\Motoris\SumberTandaMotoris) berstatus Perlu dicek pada
     * tim koordinator — lepas dari tanggal tanda itu sendiri, sama seperti tandaOutlet, supaya tanda lama yang belum
     * diperiksa tidak hilang dari ringkasan ini. Cakupannya sama dengan "Rekap Motoris
     * Ditandai" (Akses::batasiKaryawan), supaya berapa pun koordinator di-nest tetap melihat tim sendiri saja.
     *
     * @return array{motoris: int, total: int}
     */
    private static function tandaMotoris(Request $request): array
    {
        $kunci = 'beranda:tanda:user_'.$request->user()?->id;

        return Cache::remember($kunci, self::CACHE_TTL, function () use ($request) {
            $q = Akses::batasiKaryawan(DB::table('tanda_motoris as tm')->join('mk_karyawan as k', 'k.id', '=', 'tm.karyawan_id'), $request->user())
                ->where('tm.status', 'perlu_dicek');

            return ['motoris' => (int) (clone $q)->distinct()->count('tm.karyawan_id'), 'total' => (int) (clone $q)->count()];
        });
    }

    /**
     * Tanda outlet (tanda_outlet, lihat App\Support\Menu\SumberTandaOutlet) berstatus Perlu dicek pada outlet milik
     * tim koordinator — dicocokkan lewat motoris pendaftar outlet itu (trx_outlet_baru.karyawan_id,
     * atau mk_outlet.dibuat_oleh sebagai cadangan untuk data lama tanpa trx_outlet_baru), PERSIS sama dengan kolom
     * "Koordinator" di menu Rekap Outlet Ditandai (SumberTandaOutlet) — bukan lewat depo outlet, yang bisa beda
     * kalau area/depo outletnya tidak lengkap sehingga sebetulnya milik tim tapi tidak ketemu lewat depo — ditambah
     * titik outlet di rute tim (SinkronTemuan::tandaOutletTim), karena nama pendaftar data lama tidak selalu sama
     * dengan nama motoris.
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
     * tanda_outlet (alias t) Perlu dicek pada outlet milik tim koordinator — dicocokkan lewat motoris pendaftar
     * outlet atau rute tim (lihat catatan di tandaOutlet()). Null bila tim kosong (tidak ada karyawan dalam cakupan).
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
            ->where('t.status', 'perlu_dicek')
            ->where(fn ($w) => $w->whereExists(fn ($e) => $e->selectRaw('1')->from('trx_outlet_baru as ob')
                ->whereColumn('ob.outlet_id', 'o.id')->whereIn('ob.karyawan_id', $ids))
                ->orWhereIn('o.dibuat_oleh', $nama)
                ->orWhereIn('t.id', SinkronTemuan::tandaOutletTim($ids->map(fn ($id) => (int) $id)->all(), 'tt')->select('tt.id')));
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
                // Depo aktif dengan EC Achievement di bawah 75% (batas kuning/merah warnaCapai, sama dengan Summary All Depo).
                'depoTertinggal' => $kartu->where('total_motoris', '>', 0)->where('ec_achievement', '<', 75)->count(),
            ];
        });
    }

    /**
     * Perbandingan Beranda admin (spec 066): 30 hari terakhir vs 30 hari sebelumnya. Aktivitas (call, EC, IPT, AVG per
     * hari kerja) dari kpi() — rumus sama dengan Summary All Depo; penyebaran (outlet terjangkau, repeat order, outlet
     * baru) dari Dashboard::penyebaran(). Periode ini memakai ulang cache ringkasanDepo/penyebaran yang sama.
     *
     * @return array{sekarang: array<string, mixed>, sebelumnya: array<string, mixed>, outlet_terdaftar: int, menunggu_total: int, menunggu_umur: ?int}
     */
    private static function perbandinganNasional(SummaryDepo $summary, Dashboard $dashboard, Request $request): array
    {
        $hariIni = Pengaturan::sekarang();
        $sampaiLalu = $hariIni->copy()->subDays(self::RENTANG_BERANDA_HARI)->toDateString();
        $dariLalu = $hariIni->copy()->subDays(self::RENTANG_BERANDA_HARI * 2 - 1)->toDateString();
        $cakupan = Akses::cakupan($request->user()) === 'semua' ? 'semua' : 'user_'.($request->user()?->id ?? 0);

        // IPT = SKU ÷ EC periode itu (rasio, bukan jumlah).
        $ringkas = fn (string $d, string $s, array $kpi, array $sebar): array => [
            'dari' => $d, 'sampai' => $s,
            'call' => $kpi['call'], 'ec' => $kpi['ec'], 'ipt' => $kpi['avg_ipt'], 'hari_kerja' => $kpi['hari_kerja'],
            'avg_call' => $kpi['avg_call'], 'avg_ec' => $kpi['avg_ec'],
            'call_achievement' => $kpi['call_achievement'], 'ec_achievement' => $kpi['ec_achievement'], 'ipt_achievement' => $kpi['ipt_achievement'],
            'outlet' => $sebar['outlet'], 'ro' => $sebar['ro'], 'noo' => $sebar['noo'], 'noo_menunggu' => $sebar['noo_menunggu'],
            'fiktif_terbukti' => $sebar['fiktif_terbukti'], 'fiktif_wajar' => $sebar['fiktif_wajar'],
            'noo_disetujui' => $sebar['noo_disetujui'], 'noo_ditolak' => $sebar['noo_ditolak'],
        ];

        $sebelumnya = Cache::remember("beranda:perbandingan:{$dariLalu}:{$sampaiLalu}:{$cakupan}", self::CACHE_TTL, function () use ($request, $dariLalu, $sampaiLalu, $ringkas) {
            $lalu = new SummaryDepo($dariLalu, $sampaiLalu, $request->user());

            return $ringkas($dariLalu, $sampaiLalu, self::kpi($lalu->kartu(), $lalu->target), (new Dashboard($dariLalu, $sampaiLalu, $request->user(), nooTerverifikasi: true))->penyebaran());
        });

        $sebar = $dashboard->penyebaran();

        return [
            'sekarang' => $ringkas($summary->dari, $summary->sampai, self::ringkasanDepo($summary, $request)['kpi'], $sebar),
            'sebelumnya' => $sebelumnya,
            'outlet_terdaftar' => $sebar['outlet_terdaftar'],
            // Antrean verifikasi NOO saat ini (bukan per periode).
            'menunggu_total' => $sebar['menunggu_total'],
            'menunggu_umur' => $sebar['menunggu_umur'],
        ];
    }

    /**
     * Kinerja Koordinator (spec 067) 30 hari terakhir: ringkasan nasional (dengan pembanding 30 hari sebelumnya) dan 10
     * koordinator yang paling perlu perhatian. Array murni supaya aman di-cache.
     *
     * @return array{nasional: array<string, mixed>, sebelumnya: array<string, mixed>, perhatian: list<array<string, mixed>>}
     */
    private static function kinerjaKoordinator(Request $request): array
    {
        [$dari, $sampai] = self::periodeBeranda();
        $hariIni = Pengaturan::sekarang();
        $sampaiLalu = $hariIni->copy()->subDays(self::RENTANG_BERANDA_HARI)->toDateString();
        $dariLalu = $hariIni->copy()->subDays(self::RENTANG_BERANDA_HARI * 2 - 1)->toDateString();
        $cakupan = Akses::cakupan($request->user()) === 'semua' ? 'semua' : 'user_'.($request->user()?->id ?? 0);

        return Cache::remember("beranda:koordinator:{$dari}:{$sampai}:{$cakupan}", self::CACHE_TTL, function () use ($request, $dari, $sampai, $dariLalu, $sampaiLalu) {
            $kini = new KinerjaKoordinator($dari, $sampai, $request->user());

            return [
                'nasional' => $kini->nasional(),
                'sebelumnya' => (new KinerjaKoordinator($dariLalu, $sampaiLalu, $request->user()))->nasional(),
                'perhatian' => $kini->perhatian(10),
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

    /** Periode Beranda admin (spec 066): tetap 30 hari terakhir sampai hari ini, tidak bisa diubah dari halaman. */
    private static function periodeBeranda(): array
    {
        $hariIni = Pengaturan::sekarang();

        return [$hariIni->copy()->subDays(self::RENTANG_BERANDA_HARI - 1)->toDateString(), $hariIni->toDateString()];
    }
}
