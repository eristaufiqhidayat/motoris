<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Akses\Akses;
use App\Support\Menu\JamLokal;
use App\Support\Mobile\Lokasi;
use App\Support\Mobile\Pengaturan;
use App\Support\Outlet\RiwayatTransaksi;
use App\Support\Tracking\HasilPeriksa;
use App\Support\Tracking\TandaOutletMotoris;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Tanda motoris di drawer Riwayat Lacak Motoris. Sejak spec 062 tanda hanya dibuat sistem (Sinkronisasi Data di Beranda
// Koordinator, App\Support\Tracking\SinkronTemuan) dengan status Perlu dicek; koordinator tidak lagi menandai manual,
// tetapi memeriksa tanda itu (periksa: Terbukti / Wajar + catatan). Hak mengikuti menu tracking_sales: Lihat = melihat
// tanda, Tambah = memeriksa tanda. Hanya motoris dalam cakupan role akun.
class TandaMotorisController extends Controller
{
    private const MENU = 'tracking_sales';

    /** Tanda aktif satu motoris pada satu tanggal. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sales_id' => ['required', 'integer'],
            'tanggal' => ['required', 'date'],
        ]);

        $motoris = $this->motoris($request, (int) $data['sales_id']);

        $tanggal = substr($data['tanggal'], 0, 10);
        $tanda = DB::table('tanda_motoris')
            ->where('karyawan_id', $motoris->id)->where('tanggal', $tanggal)->whereNull('dicabut_at')
            ->orderBy('id')->get()
            ->map(fn ($t) => $this->bentuk($t, $request->user()));

        // Tanda aktif motoris yang sama pada tanggal lain dalam jendela hari terakhir, supaya pola lintas hari terlihat
        // dan koordinator bisa langsung meninjau (ubah catatan / cabut) tanpa pindah tanggal dulu.
        $awal = Pengaturan::sekarang()->subDays((int) config('marketama.tanda_motoris_hari') - 1)->toDateString();
        $lainnya = DB::table('tanda_motoris')
            ->where('karyawan_id', $motoris->id)->whereNull('dicabut_at')->where('tanggal', '>=', $awal)->where('tanggal', '<>', $tanggal)
            ->orderByDesc('tanggal')->orderByDesc('id')->limit(50)->get()
            ->map(fn ($t) => [...$this->bentuk($t, $request->user()), 'tanggal' => substr((string) $t->tanggal, 0, 10)]);

        // Tanda outlet pada outlet yang didaftarkan motoris ini (ikut terhitung di kolom Ditandai). Dikelola di menu
        // All Outlet (hak outlet_all), jadi di sini hanya untuk ditinjau.
        $outlet = TandaOutletMotoris::aktif(false, $motoris->id, $motoris->nama)
            ->orderByDesc('tout.created_at')->orderByDesc('tout.id')->limit(50)
            ->select(['tout.id', 'tout.outlet_id', 'o.nama as outlet', 'o.latitude', 'o.longitude', 'tout.kategori', 'tout.catatan', 'o.dibuat_pada', 'tout.dibuat_nama', 'tout.created_at'])
            ->selectSub(DB::table('trx_outlet_baru as nb')->whereColumn('nb.outlet_id', 'o.id')->selectRaw('MIN(COALESCE(nb.recorded_at, nb.received_at))'), 'daftar_hp')
            ->get()
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'outlet_id' => (int) $t->outlet_id,
                'outlet' => $t->outlet,
                'latitude' => $t->latitude !== null ? (float) $t->latitude : null,
                'longitude' => $t->longitude !== null ? (float) $t->longitude : null,
                'kategori_label' => config("marketama.tanda_outlet.{$t->kategori}", $t->kategori),
                // Kapan outlet didaftarkan sebagai NOO: dari HP (UTC -> lokal) bila ada, selain itu data Marketama apa adanya.
                'didaftarkan' => JamLokal::format($t->daftar_hp, 'Y-m-d H:i') ?? $t->dibuat_pada,
                'catatan' => $t->catatan,
                'oleh' => $t->dibuat_nama,
                'waktu' => JamLokal::format($t->created_at, 'Y-m-d H:i'),
                'tanggal' => JamLokal::format($t->created_at, 'Y-m-d'),
            ]);

        return response()->json([
            'tanda' => $tanda->values(),
            'lainnya' => $lainnya->values(),
            'outlet' => $outlet->values(),
        ]);
    }

    /** Batas jumlah outlet bertanda di sekitar yang ditampilkan. */
    public const MAKS_SEKITAR = 20;

    /**
     * Outlet lain yang punya tanda outlet aktif di sekitar satu outlet (terdekat dulu), untuk konteks di peta.
     * Radius = ambang "jarak dekat" yang diatur pengguna di browser-nya (localStorage), dikirim sebagai radius_m;
     * tanpa itu memakai bawaan config('marketama.deteksi_jarak_kunjungan.jarak_dekat_meter').
     *
     * Bila outlet itu bagian titik temuan sistem (tanda_outlet.kelompok, spec 062), outlet lain di titik itu dikirim
     * lengkap sebagai `titik` (tanpa batas MAKS_SEKITAR) dan tidak diulang di `sekitar`: koordinatnya identik, jadi di
     * peta semuanya satu penanda berangka.
     */
    public function outletTerdekat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'integer'],
            'radius_m' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $radius = (int) ($data['radius_m'] ?? config('marketama.deteksi_jarak_kunjungan.jarak_dekat_meter'));

        $acuan = DB::table('mk_outlet as o')->where('o.id', $data['outlet_id'])
            ->select(['o.id', 'o.latitude', 'o.longitude', 'o.dibuat_oleh', 'o.dibuat_pada'])
            ->selectSub(self::daftarHp(), 'daftar_hp')->first();

        abort_unless($acuan, 404);

        $kelompok = DB::table('tanda_outlet')->where('outlet_id', $acuan->id)->whereNotNull('kelompok')->orderByDesc('id')->value('kelompok');
        $titik = $kelompok === null ? collect() : DB::table('tanda_outlet as tout')
            ->join('mk_outlet as o', 'o.id', '=', 'tout.outlet_id')
            ->where('tout.kelompok', $kelompok)->where('o.id', '<>', $acuan->id)
            ->distinct()->orderBy('o.nama')
            ->select(['o.id as outlet_id', 'o.nama as outlet', 'o.dibuat_oleh as pendaftar', 'o.dibuat_pada'])
            ->selectSub(self::daftarHp(), 'daftar_hp')->get()
            ->map(fn ($o) => ['outlet_id' => (int) $o->outlet_id, 'outlet' => $o->outlet, 'pendaftar' => $o->pendaftar, 'didaftarkan' => self::didaftarkan($o)])
            ->values();

        // Ringkasan transaksi acuan + outlet satu titik (bukti alasan "N bertransaksi"); rincian dimuat lewat outletTransaksi.
        $ringkasan = RiwayatTransaksi::ringkasan([(int) $acuan->id, ...$titik->pluck('outlet_id')->all()], $request->user());
        $titik = $titik->map(fn ($o) => $o + ['ringkasan' => $ringkasan[$o['outlet_id']] ?? null])->values();
        $ringkasanAcuan = $ringkasan[(int) $acuan->id] ?? null;

        if (! Lokasi::sah($acuan->latitude, $acuan->longitude)) {
            return response()->json(['sekitar' => [], 'titik' => $titik, 'ringkasan' => $ringkasanAcuan, 'pendaftar' => $acuan->dibuat_oleh, 'didaftarkan' => self::didaftarkan($acuan), 'radius_m' => $radius]);
        }

        $lat = (float) $acuan->latitude;
        $lng = (float) $acuan->longitude;
        [$latMin, $latMax, $lngMin, $lngMax] = Lokasi::kotak($lat, $lng, $radius);

        $sekitar = DB::table('tanda_outlet as tout')
            ->join('mk_outlet as o', 'o.id', '=', 'tout.outlet_id')
            ->whereNull('tout.dicabut_at')->whereNotIn('o.id', [$acuan->id, ...$titik->pluck('outlet_id')])
            ->whereBetween('o.latitude', [$latMin, $latMax])->whereBetween('o.longitude', [$lngMin, $lngMax])
            ->orderBy('tout.id')
            ->get(['o.id as outlet_id', 'o.nama as outlet', 'o.latitude', 'o.longitude', 'tout.kategori', 'tout.catatan', 'tout.created_at'])
            ->groupBy('outlet_id')
            ->map(function ($tanda) use ($lat, $lng) {
                $o = $tanda->first();

                return [
                    'outlet_id' => (int) $o->outlet_id,
                    'outlet' => $o->outlet,
                    'latitude' => (float) $o->latitude,
                    'longitude' => (float) $o->longitude,
                    'jarak_m' => Lokasi::jarak($lat, $lng, (float) $o->latitude, (float) $o->longitude),
                    'jumlah_tanda' => $tanda->count(),
                    'kategori_label' => $tanda->map(fn ($t) => config("marketama.tanda_outlet.{$t->kategori}", $t->kategori))->unique()->implode(', '),
                    'ditandai_pada' => JamLokal::format($tanda->max('created_at'), 'Y-m-d'),
                ];
            })
            ->filter(fn ($o) => $o['jarak_m'] <= $radius)
            ->sortBy('jarak_m')->take(self::MAKS_SEKITAR)->values();

        return response()->json(['sekitar' => $sekitar, 'titik' => $titik, 'ringkasan' => $ringkasanAcuan, 'pendaftar' => $acuan->dibuat_oleh, 'didaftarkan' => self::didaftarkan($acuan), 'radius_m' => $radius]);
    }

    /** Subquery waktu outlet (alias o) pertama kali didaftarkan dari HP (trx_outlet_baru), UTC. */
    private static function daftarHp(): Builder
    {
        return DB::table('trx_outlet_baru as nb')->whereColumn('nb.outlet_id', 'o.id')->selectRaw('MIN(COALESCE(nb.recorded_at, nb.received_at))');
    }

    /** Kapan outlet didaftarkan: dari HP (UTC -> lokal) bila ada, selain itu teks Marketama apa adanya (bisa null). */
    private static function didaftarkan(object $o): ?string
    {
        return JamLokal::format($o->daftar_hp, 'Y-m-d H:i') ?? $o->dibuat_pada;
    }

    /** Transaksi terbaru satu outlet beserta item produknya (RiwayatTransaksi), dari motoris dalam cakupan role akun. */
    public function outletTransaksi(Request $request): JsonResponse
    {
        $data = $request->validate(['outlet_id' => ['required', 'integer']]);

        abort_unless(DB::table('mk_outlet')->where('id', $data['outlet_id'])->exists(), 404);

        return response()->json(RiwayatTransaksi::daftar((int) $data['outlet_id'], $request->user()) + ['maks' => RiwayatTransaksi::MAKS_TRANSAKSI]);
    }

    /**
     * Hasil cek koordinator atas satu tanda Perlu dicek: Terbukti (tetap berlencana Ditandai) atau Wajar (dicabut_at
     * diisi, jadi tidak lagi dihitung sebagai tanda aktif dan tidak dibuat ulang oleh sinkronisasi). Catatan wajib.
     */
    public function periksa(Request $request, int $tanda): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['terbukti', 'wajar'])],
            'catatan' => ['required', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $baris = DB::table('tanda_motoris')->where('id', $tanda)->where('status', 'perlu_dicek')->first();

        abort_unless($baris, 404);

        $motoris = $this->motoris($request, (int) $baris->karyawan_id);

        abort_unless(Akses::bisa($user, self::MENU, 'tambah'), 403, 'Anda tidak boleh memeriksa tanda ini.');

        // Dua pemeriksa bersamaan: hanya yang pertama berhasil.
        $berubah = DB::table('tanda_motoris')->where('id', $tanda)->where('status', 'perlu_dicek')
            ->update(HasilPeriksa::kolom($data['status'], $data['catatan'], $user));

        abort_unless($berubah, 404);

        Akses::catat($user, 'tanda_motoris', $tanda, 'ubah', "Periksa tanda {$motoris->nama} (".substr((string) $baris->tanggal, 0, 10).'): '
            .config("marketama.status_tanda.{$data['status']}"));
        HomeController::lupakanTanda($request);

        return response()->json($this->bentuk(DB::table('tanda_motoris')->find($tanda), $user));
    }

    /** Motoris (kategori sales) dalam cakupan role akun; selain itu 404. */
    private function motoris(Request $request, int $id): object
    {
        $motoris = Akses::batasiKaryawan(DB::table('mk_karyawan as k')->where('k.id', $id)->where('k.kategori', 'sales'), $request->user())
            ->first(['k.id', 'k.nama']);

        abort_unless($motoris, 404);

        return $motoris;
    }

    private function label(string $kategori): string
    {
        return config("marketama.tanda_motoris.{$kategori}", $kategori);
    }

    /** @return array<string, mixed> */
    private function bentuk(object $tanda, ?User $user): array
    {
        return [
            'id' => (int) $tanda->id,
            'kategori' => $tanda->kategori,
            'kategori_label' => $this->label($tanda->kategori),
            'catatan' => $tanda->catatan,
            'oleh' => $tanda->dibuat_nama,
            'waktu' => JamLokal::format($tanda->created_at, 'Y-m-d H:i'),
        ] + HasilPeriksa::bentuk($tanda, Akses::bisa($user, self::MENU, 'tambah'));
    }
}
