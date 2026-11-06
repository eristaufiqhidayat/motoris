<?php

namespace App\Http\Controllers;

use App\Support\Akses\Cakupan;
use App\Support\Tracking\TitikGps;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// Riwayat lokasi koordinator: data lama Marketama (tracking_koordinator_lokasi) digabung dengan
// titik GPS dari aplikasi HP koordinator (trx_tracking, karyawan_id = koordinator_id).
class TrackingKoordinatorController extends Controller
{
    public function index(Request $request)
    {
        $tanggal = $request->query('tanggal') ?? TitikGps::tanggalTerakhir('tracking_koordinator_lokasi', 'koordinator');

        return Inertia::render('Tracking/History', [
            'slug' => 'tracking_koordinator_history_location',
            'title' => config('marketama.pages.tracking_koordinator_history_location.title'),
            'label' => 'Koordinator',
            'idParam' => 'koordinator_id',
            'people' => $this->daftarKoordinator($request)->values()->all(),
            'groups' => [],
            'locationsUrl' => route('tracking-koordinator.locations'),
            'countsUrl' => route('tracking-koordinator.counts'),
            'tanggal' => $tanggal,
            'counts' => (object) $this->countsFor($request, $tanggal),
            'pilihId' => $request->integer('koordinator_id') ?: null,
        ]);
    }

    /**
     * Jumlah titik lokasi per koordinator pada satu tanggal, untuk menandai koordinator yang punya data.
     */
    public function counts(Request $request)
    {
        $data = $request->validate(['tanggal' => ['required', 'date']]);

        return response()->json($this->countsFor($request, $data['tanggal']));
    }

    public function locations(Request $request)
    {
        $data = $request->validate([
            'koordinator_id' => ['required', 'integer'],
            'tanggal' => ['required', 'date'],
        ]);

        $id = (int) $data['koordinator_id'];
        abort_unless(Cakupan::untuk($request->user())->mencakup($id), 404);

        $koordinator = DB::table('tracking_koordinator')->where('koordinator_id', $id)->first()
            ?? $this->daftarKoordinator($request)->firstWhere('id', $id);

        $depo = TitikGps::depoMaster($id);

        $lama = DB::table('tracking_koordinator_lokasi')
            ->where('koordinator_id', $id)
            ->whereDate('tanggal', $data['tanggal'])
            ->get(['lat', 'lng', 'waktu', 'akurasi']);

        $locations = $lama->concat(TitikGps::untuk($id, $data['tanggal']))
            ->sortBy('waktu')->values();

        return response()->json([
            'koordinator' => $koordinator,
            'depo' => $depo,
            'total' => $locations->count(),
            'locations' => $locations,
        ]);
    }

    // ------------------------------------------------------------------

    /** Koordinator dari data lama + master karyawan (yang memakai aplikasi HP), tanpa ganda. */
    private function daftarKoordinator(Request $request): Collection
    {
        $dalam = $this->idDalamCakupan($request);
        $lama = DB::table('tracking_koordinator')->get(['koordinator_id as id', 'nama', 'telepon', 'depo'])
            ->filter(fn ($k) => $dalam === null || isset($dalam[$k->id]));
        $ada = $lama->pluck('id')->flip();

        $master = Cakupan::untuk($request->user())->karyawan()->leftJoin('mk_depo as d', 'd.id', '=', 'k.depo_id')
            ->where('k.kategori', 'koordinator')->where('k.aktif', true)
            ->get(['k.id', 'k.nama', 'k.no_hp as telepon', 'd.nama as depo'])
            ->reject(fn ($k) => isset($ada[$k->id]));

        return $lama->concat($master)->sortBy('nama', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /** Id karyawan dalam cakupan akun sebagai kunci (id => true); null = tidak dibatasi. */
    private function idDalamCakupan(Request $request): ?array
    {
        $cakupan = Cakupan::untuk($request->user());

        return $cakupan->semua() ? null : $cakupan->karyawan()->pluck('k.id')->flip()->all();
    }

    private function countsFor(Request $request, string $tanggal): array
    {
        $hasil = DB::table('tracking_koordinator_lokasi')
            ->whereDate('tanggal', $tanggal)
            ->groupBy('koordinator_id')
            ->selectRaw('koordinator_id, count(*) as total')
            ->pluck('total', 'koordinator_id')
            ->map(fn ($total) => (int) $total)
            ->all();

        $hasil = TitikGps::tambahJumlah($hasil, 'koordinator', $tanggal);
        $dalam = $this->idDalamCakupan($request);

        return $dalam === null ? $hasil : array_intersect_key($hasil, $dalam);
    }
}
