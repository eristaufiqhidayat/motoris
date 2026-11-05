<?php

namespace App\Support\Motoris;

use App\Models\User;
use App\Support\Akses\Akses;
use App\Support\Mobile\Pengaturan;
use App\Support\Tracking\SinkronTemuan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Kinerja koordinator di Beranda admin (spec 067). Koordinator membina tim dan mengendalikan kualitas data, jadi diukur dari:
// - tindak lanjut temuan sistem: temuan tanda_motoris / tanda_outlet buatan sistem (lihat SinkronTemuan) yang dibuat pada
//   periode, berapa yang sudah diperiksa (Terbukti / Wajar), seberapa cepat, dan berapa yang menunggu terlalu lama;
// - hasil tim: outlet unik ber-EC per motoris tim (tanpa outlet terbukti fiktif);
// - pembinaan: cakupan motoris tim yang di-join visit dan keabsahan join visit;
// - verifikasi NOO: koordinator yang menyetujui/menolak pendaftaran outlet baru motoris timnya, diukur dari median hari sampai
//   diverifikasi dan pendaftaran yang menunggu terlalu lama.
// Tim = motoris aktif dengan mk_karyawan.koordinator_id koordinator itu. Tanda outlet milik tim bila outlet pernah dikunjungi
// motoris tim. Semua dihitung dengan agregasi untuk seluruh koordinator sekaligus, bukan satu query per koordinator.
//
// Rasio Terbukti hanya penanda audit, bukan nilai: rendah bisa berarti timnya bersih, atau koordinatornya menutup semua temuan
// sebagai Wajar. Dibandingkan dengan rasio nasional (config marketama.kinerja_koordinator).
class KinerjaKoordinator
{
    /** @var array{baris: list<array<string, mixed>>, nasional: array<string, mixed>}|null */
    private ?array $hasil = null;

    public function __construct(public readonly string $dari, public readonly string $sampai, private readonly ?User $user) {}

    /** @return list<array<string, mixed>> satu baris per koordinator aktif dalam cakupan akun */
    public function baris(): array
    {
        return $this->hitung()['baris'];
    }

    /** @return array<string, mixed> */
    public function nasional(): array
    {
        return $this->hitung()['nasional'];
    }

    /**
     * Koordinator yang paling perlu perhatian: tindak lanjut terendah dulu (yang tanpa temuan paling akhir), lalu cakupan join visit.
     *
     * @return list<array<string, mixed>>
     */
    public function perhatian(int $batas = 10): array
    {
        return collect($this->baris())
            ->sortBy([
                fn ($a, $b) => ($a['tindak_lanjut'] ?? 101) <=> ($b['tindak_lanjut'] ?? 101),
                fn ($a, $b) => $a['cakupan_join_visit'] <=> $b['cakupan_join_visit'],
                fn ($a, $b) => strcmp((string) $a['nama'], (string) $b['nama']),
            ])
            ->take($batas)->values()->all();
    }

    /** @return array{baris: list<array<string, mixed>>, nasional: array<string, mixed>} */
    private function hitung(): array
    {
        if ($this->hasil !== null) {
            return $this->hasil;
        }

        $atur = config('marketama.kinerja_koordinator');
        $koordinator = Akses::batasiKaryawan(
            DB::table('mk_karyawan as k')->leftJoin('mk_depo as d', 'd.id', '=', 'k.depo_id')->where('k.kategori', 'koordinator')->where('k.aktif', true),
            $this->user,
        )->orderBy('k.nama')->get(['k.id', 'k.nama', 'd.nama as depo']);
        $ids = $koordinator->pluck('id')->map(fn ($id) => (int) $id)->all();

        $tim = DB::table('mk_karyawan')->where('kategori', 'sales')->where('aktif', true)->whereIn('koordinator_id', $ids ?: [0])
            ->get(['id', 'koordinator_id'])->groupBy('koordinator_id')->map(fn ($a) => $a->pluck('id')->map(fn ($id) => (int) $id)->all());
        $temuan = $this->temuan($ids)->groupBy('koordinator_id');
        $hasilTim = $this->hasilTim($ids);
        $joinVisit = $this->joinVisit($ids)->groupBy('koordinator_id');
        $noo = $this->noo($ids)->groupBy('koordinator_id');
        $sekarang = Pengaturan::sekarang();
        $batasMenunggu = $sekarang->subDays((int) $atur['menunggu_hari']);
        $batasNoo = $sekarang->subDays((int) $atur['noo_menunggu_hari']);

        // Per koordinator: hitungan mentah dulu (median nasional dan rasio nasional butuh semua baris).
        $mentah = $koordinator->map(function ($k) use ($tim, $temuan, $hasilTim, $joinVisit, $noo, $batasMenunggu, $batasNoo) {
            $anggota = $tim[$k->id] ?? [];
            $t = $temuan[$k->id] ?? collect();
            $dalamPeriode = $t->filter(fn ($x) => $x->dalam_periode);
            $diperiksa = $dalamPeriode->whereIn('status', ['terbukti', 'wajar']);
            $durasi = $diperiksa->filter(fn ($x) => $x->dicek_at)->map(fn ($x) => (CarbonImmutable::parse($x->dicek_at)->getTimestamp() - CarbonImmutable::parse($x->created_at)->getTimestamp()) / 86400)->values()->all();
            $v = $joinVisit[$k->id] ?? collect();
            $sah = $v->filter(fn ($x) => $this->sah($x))->count();
            $n = $noo[$k->id] ?? collect();
            $durasiNoo = $n->whereIn('status', ['disetujui', 'ditolak'])->filter(fn ($x) => $x->diverifikasi_pada)
                ->map(fn ($x) => (CarbonImmutable::parse($x->diverifikasi_pada)->getTimestamp() - CarbonImmutable::parse($x->recorded_at)->getTimestamp()) / 86400)->values()->all();

            return [
                'id' => (int) $k->id, 'nama' => $k->nama, 'depo' => $k->depo,
                'motoris' => count($anggota),
                'temuan' => $dalamPeriode->count(),
                'diperiksa' => $diperiksa->count(),
                'terbukti' => $diperiksa->where('status', 'terbukti')->count(),
                'durasi' => $durasi,
                'menunggu_lama' => $t->where('status', 'perlu_dicek')->filter(fn ($x) => CarbonImmutable::parse($x->created_at)->lte($batasMenunggu))->count(),
                'outlet_per_motoris' => isset($hasilTim[$k->id]) && $hasilTim[$k->id]->motoris > 0 ? round($hasilTim[$k->id]->outlet / $hasilTim[$k->id]->motoris, 1) : 0.0,
                'join_visit' => $v->count(),
                'dijoin' => $v->pluck('sales_id')->map(fn ($id) => (int) $id)->unique()->intersect($anggota)->count(),
                'sah' => $sah,
                'durasi_noo' => $durasiNoo,
                'noo_menunggu_lama' => $n->where('status', 'menunggu')->filter(fn ($x) => CarbonImmutable::parse($x->recorded_at)->lte($batasNoo))->count(),
            ];
        });

        $totalDiperiksa = $mentah->sum('diperiksa');
        $rasioNasional = $totalDiperiksa > 0 ? $mentah->sum('terbukti') / $totalDiperiksa : null;

        $baris = $mentah->map(function ($m) use ($atur, $rasioNasional) {
            $rasio = $m['diperiksa'] > 0 ? $m['terbukti'] / $m['diperiksa'] : null;

            return [
                'id' => $m['id'], 'nama' => $m['nama'], 'depo' => $m['depo'], 'motoris' => $m['motoris'],
                'outlet_per_motoris' => $m['outlet_per_motoris'],
                'temuan' => $m['temuan'], 'diperiksa' => $m['diperiksa'], 'terbukti' => $m['terbukti'],
                'tindak_lanjut' => $m['temuan'] > 0 ? round($m['diperiksa'] / $m['temuan'] * 100, 1) : null,
                'median_hari' => self::median($m['durasi']),
                'menunggu_lama' => $m['menunggu_lama'],
                'rasio_terbukti' => $rasio === null ? null : round($rasio * 100, 1),
                'perlu_audit' => $rasio !== null && $rasioNasional !== null && $m['diperiksa'] >= $atur['audit_min_diperiksa'] && $rasio < $atur['audit_rasio'] * $rasioNasional,
                'join_visit' => $m['join_visit'],
                'cakupan_join_visit' => $m['motoris'] > 0 ? round($m['dijoin'] / $m['motoris'] * 100, 1) : 0.0,
                'join_visit_sah' => $m['join_visit'] > 0 ? round($m['sah'] / $m['join_visit'] * 100, 1) : null,
                'noo_median_hari' => self::median($m['durasi_noo']),
                'noo_menunggu_lama' => $m['noo_menunggu_lama'],
            ];
        })->values();

        $totalTemuan = $mentah->sum('temuan');
        $totalMotoris = $mentah->sum('motoris');

        return $this->hasil = [
            'baris' => $baris->all(),
            'nasional' => [
                'koordinator' => $baris->count(),
                'temuan' => $totalTemuan,
                'diperiksa' => $totalDiperiksa,
                'tindak_lanjut' => $totalTemuan > 0 ? round($totalDiperiksa / $totalTemuan * 100, 1) : null,
                'median_hari' => self::median($mentah->pluck('durasi')->flatten()->all()),
                'menunggu_lama' => $mentah->sum('menunggu_lama'),
                'rasio_terbukti' => $rasioNasional === null ? null : round($rasioNasional * 100, 1),
                'cakupan_join_visit' => $totalMotoris > 0 ? round($mentah->sum('dijoin') / $totalMotoris * 100, 1) : 0.0,
                'perlu_audit' => $baris->where('perlu_audit', true)->count(),
                'noo_median_hari' => self::median($mentah->pluck('durasi_noo')->flatten()->all()),
                'noo_menunggu_lama' => $mentah->sum('noo_menunggu_lama'),
            ],
        ];
    }

    /**
     * Temuan sistem (tanda motoris + tanda outlet) milik tim: dibuat pada periode, atau masih Perlu dicek (untuk hitungan menunggu
     * lama). Tanda outlet dicocokkan lewat motoris tim yang pernah mengunjungi outletnya; DISTINCT supaya kunjungan ganda tidak
     * menggandakan tanda.
     *
     * @param  list<int>  $ids  koordinator
     * @return Collection<int, object{koordinator_id: int, status: string, created_at: string, dicek_at: ?string, dalam_periode: bool}>
     */
    private function temuan(array $ids): Collection
    {
        $dari = "{$this->dari} 00:00:00";
        $sampai = "{$this->sampai} 23:59:59";

        $motoris = DB::table('tanda_motoris as t')->join('mk_karyawan as m', 'm.id', '=', 't.karyawan_id')
            ->whereIn('m.koordinator_id', $ids ?: [0])->where('t.dibuat_nama', SinkronTemuan::NAMA_SISTEM)
            ->where(fn ($w) => $w->whereBetween('t.created_at', [$dari, $sampai])->orWhere('t.status', 'perlu_dicek'))
            ->select('t.id', 'm.koordinator_id', 't.status', 't.created_at', 't.dicek_at');

        $outlet = DB::table('tanda_outlet as t')
            ->join('trx_kunjungan as kj', 'kj.outlet_id', '=', 't.outlet_id')
            ->join('mk_karyawan as m', 'm.id', '=', 'kj.karyawan_id')
            ->where('m.kategori', 'sales')->whereIn('m.koordinator_id', $ids ?: [0])->where('t.dibuat_nama', SinkronTemuan::NAMA_SISTEM)
            ->where(fn ($w) => $w->whereBetween('t.created_at', [$dari, $sampai])->orWhere('t.status', 'perlu_dicek'))
            ->distinct()->select('t.id', 'm.koordinator_id', 't.status', 't.created_at', 't.dicek_at');

        return $motoris->get()->concat($outlet->get())->map(function ($t) use ($dari, $sampai) {
            $t->koordinator_id = (int) $t->koordinator_id;
            $t->dalam_periode = (string) $t->created_at >= $dari && (string) $t->created_at <= $sampai;

            return $t;
        });
    }

    /**
     * Outlet unik ber-EC (tidak dibatalkan, bukan outlet terbukti fiktif) dan motoris yang menjual, per koordinator.
     *
     * @param  list<int>  $ids
     * @return Collection<int, object{outlet: int, motoris: int}> keyed by koordinator_id
     */
    private function hasilTim(array $ids): Collection
    {
        return DB::table('trx_penjualan as p')->join('mk_karyawan as m', 'm.id', '=', 'p.karyawan_id')
            ->whereIn('m.koordinator_id', $ids ?: [0])
            ->whereBetween('p.tanggal', [$this->dari, $this->sampai])
            ->whereNull('p.dibatalkan_at')->where('p.is_effective_call', true)->whereNotNull('p.outlet_id')
            ->whereNotIn('p.outlet_id', Dashboard::outletTerbuktiFiktif())
            ->groupBy('m.koordinator_id')
            ->selectRaw('m.koordinator_id, COUNT(DISTINCT p.outlet_id) AS outlet, COUNT(DISTINCT p.karyawan_id) AS motoris')
            ->get()->keyBy(fn ($r) => (int) $r->koordinator_id);
    }

    /** @param  list<int>  $ids */
    private function joinVisit(array $ids): Collection
    {
        return DB::table('trx_join_visit')->whereIn('koordinator_id', $ids ?: [0])->whereBetween('tanggal', [$this->dari, $this->sampai])
            ->get(['koordinator_id', 'sales_id', 'jarak_m', 'mock_location', 'selesai_at'])
            ->map(function ($v) {
                $v->koordinator_id = (int) $v->koordinator_id;

                return $v;
            });
    }

    /**
     * Pendaftaran NOO motoris tim: yang diverifikasi pada periode (untuk kecepatan) dan yang masih menunggu (untuk antrean lama).
     *
     * @param  list<int>  $ids
     * @return Collection<int, object{koordinator_id: int, status: string, recorded_at: string, diverifikasi_pada: ?string}>
     */
    private function noo(array $ids): Collection
    {
        return DB::table('trx_outlet_baru as b')->join('mk_karyawan as m', 'm.id', '=', 'b.karyawan_id')
            ->whereIn('m.koordinator_id', $ids ?: [0])->whereNotNull('b.recorded_at')
            ->where(fn ($w) => $w->where('b.status', 'menunggu')
                ->orWhere(fn ($x) => $x->whereIn('b.status', ['disetujui', 'ditolak'])->whereBetween('b.diverifikasi_pada', ["{$this->dari} 00:00:00", "{$this->sampai} 23:59:59"])))
            ->get(['m.koordinator_id', 'b.status', 'b.recorded_at', 'b.diverifikasi_pada'])
            ->map(function ($n) {
                $n->koordinator_id = (int) $n->koordinator_id;

                return $n;
            });
    }

    /** Join visit sah: tanpa mock location, sudah selesai, dan jarak koordinator ke outlet tidak melebihi ambang. */
    private function sah(object $v): bool
    {
        return ! $v->mock_location && $v->selesai_at !== null && $v->jarak_m !== null
            && (int) $v->jarak_m <= (int) config('marketama.kinerja_koordinator.join_visit_jarak_m');
    }

    /** @param  list<float|int>  $nilai */
    private static function median(array $nilai): ?float
    {
        if ($nilai === []) {
            return null;
        }

        sort($nilai);
        $n = count($nilai);
        $tengah = intdiv($n, 2);

        return round($n % 2 ? $nilai[$tengah] : ($nilai[$tengah - 1] + $nilai[$tengah]) / 2, 1);
    }
}
