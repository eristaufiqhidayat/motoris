<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Akses\Akses;
use App\Support\Akses\Cakupan;
use App\Support\Ekspor\Xlsx;
use App\Support\Mobile\Pengaturan;
use App\Support\Motoris\KuotaMotoris;
use App\Support\Penggajian\Penggajian;
use App\Support\Penggajian\PeriodeGaji;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Penggajian › Penggajian Sales / Penggajian Koordinator (satu controller, jenis dari slug URL): daftar gaji dan
// insentif (tab), riwayat per orang, form Tambah, dan detail per orang. Hanya Super Admin
// (slug ada di Akses::MENU_ADMIN); akun bercakupan sebagian depo dibatasi ke depo itu.
class PenggajianController extends Controller
{
    private const JENIS = ['sales' => 'motoris', 'koordinator' => 'koordinator'];

    /** Jenis halaman ini ('sales' atau 'koordinator'), dari slug URL; mengatur slug menu, hak, dan data. */
    private string $jenis = 'sales';

    private const KATEGORI = ['gaji' => 'Gaji', 'insentif' => 'Insentif'];

    private const STATUS = ['draf' => 'Draf', 'final' => 'Final', 'batal' => 'Batal'];

    public function __construct(Request $request)
    {
        // Jenis dari slug di URL (penggajian_sales / penggajian_koordinator), sama dengan slug hak akses route.
        $jenis = str_replace('penggajian_', '', (string) $request->segment(1));
        abort_unless(array_key_exists($jenis, self::JENIS), 404);

        $this->jenis = $jenis;
    }

    /** Slug menu (juga slug hak akses) halaman ini. */
    private function menu(): string
    {
        return 'penggajian_'.$this->jenis;
    }

    /** Sebutan orang yang digaji di UI: motoris atau koordinator. */
    private function orangLabel(): string
    {
        return self::JENIS[$this->jenis];
    }

    /** Catatan bila kategori itu belum tersedia untuk jenis ini (insentif koordinator), selain itu null. */
    private function catatan(string $kategori): ?string
    {
        return $this->jenis === 'koordinator' && $kategori === 'insentif' ? Penggajian::INSENTIF_KOORDINATOR_TERTUNDA : null;
    }

    public function index(Request $request): Response
    {
        $depoIds = Cakupan::untuk($request->user())->depoIds();
        [$periode, $depo, $status] = $this->filterDaftar($request);

        if ($request->query('tab') === 'riwayat') {
            return $this->riwayat($request, $depoIds);
        }

        $tab = $this->kategori((string) $request->query('tab'));

        $baris = $this->daftarQuery($depoIds, $tab, $periode, $depo, $status)
            ->paginate(25)
            ->withQueryString()->onEachSide(1)
            ->through(fn ($r) => [
                'id' => $r->id,
                'no_penggajian' => $r->no_penggajian,
                'periode' => PeriodeGaji::label($r->periode_selesai).' ('.self::tgl($r->periode_mulai).' – '.self::tgl($r->periode_selesai).')',
                'depo' => $r->depo,
                'orang' => (int) $r->orang,
                'total' => 'Rp '.number_format((float) $r->total, 0, ',', '.'),
                'tanggal_bayar' => $r->tanggal_bayar ? self::tgl($r->tanggal_bayar) : '-',
                'status' => self::STATUS[$r->status] ?? $r->status,
                'final' => $r->status === 'final',
                'bisa_dipilih' => in_array($r->status, ['draf', 'final'], true),
            ]);

        return Inertia::render('Penggajian/Index', [
            'slug' => $this->menu(),
            'judul' => config('marketama.pages.'.$this->menu().'.title') ?? 'Penggajian '.ucfirst($this->orangLabel()),
            'orangLabel' => $this->orangLabel(),
            'catatan' => $this->catatan($tab),
            'baris' => $baris,
            'tab' => $tab,
            'tabs' => self::tabs(),
            'q' => '',
            'filter' => ['periode' => $periode, 'depo_id' => $depo ?: '', 'status' => $status],
            'opsi' => ['depo' => $this->daftarDepo($depoIds), 'status' => self::STATUS],
            'bisaTambah' => $this->catatan($tab) === null && Akses::bisa($request->user(), $this->menu(), 'tambah'),
            'bisaEkspor' => Akses::bisa($request->user(), $this->menu(), 'ekspor'),
            'bisaUbah' => Akses::bisa($request->user(), $this->menu(), 'ubah'),
        ]);
    }

    public function tambah(Request $request): Response
    {
        $kategori = $this->kategori((string) $request->query('kategori'));

        return Inertia::render('Penggajian/Tambah', [
            'slug' => $this->menu(),
            'judul' => 'Tambah '.self::KATEGORI[$kategori].' '.ucfirst($this->orangLabel()),
            'orangLabel' => $this->orangLabel(),
            'catatan' => $this->catatan($kategori),
            'kategori' => $kategori,
            'periode' => $this->daftarPeriode($kategori),
            'depo' => $this->daftarDepo(Cakupan::untuk($request->user())->depoIds()),
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kategori' => ['nullable', 'in:gaji,insentif'],
            'depo_id' => ['nullable', 'integer'],
            'periode_mulai' => ['required', 'date_format:Y-m-d'],
        ], [
            'kategori.in' => 'Kategori penggajian tidak dikenal.',
            'depo_id.integer' => 'Depo tidak valid.',
            'periode_mulai.required' => 'Periode wajib dipilih.',
            'periode_mulai.date_format' => 'Periode tidak valid.',
        ]);

        $depoIds = Cakupan::untuk($request->user())->depoIds();
        $kategori = $data['kategori'] ?? 'gaji';

        // Depo kosong = semua depo dalam cakupan akun: satu draf per depo.
        if (empty($data['depo_id'])) {
            return $this->simpanSemuaDepo($request, $kategori, $data['periode_mulai'], $depoIds);
        }

        if ($depoIds !== null && ! in_array((int) $data['depo_id'], $depoIds, true)) {
            throw ValidationException::withMessages(['depo_id' => 'Depo ini di luar cakupan akun Anda.']);
        }

        $id = Penggajian::buat($this->jenis, $kategori, (int) $data['depo_id'], $data['periode_mulai'], $request->user());

        return redirect('/'.$this->menu().'/'.$id)->with('sukses', 'Draf penggajian dibuat.');
    }

    /**
     * Buat draf untuk semua depo dalam cakupan. Depo yang periodenya tumpang tindih dengan penggajian yang sudah ada
     * dilewati dan disebut di pesan; bila tidak ada satu pun yang bisa dibuat, ditolak dengan pesan jelas.
     *
     * @param  list<int>|null  $depoIds
     */
    private function simpanSemuaDepo(Request $request, string $kategori, string $tanggal, ?array $depoIds): RedirectResponse
    {
        $hasil = Penggajian::buatSemuaDepo($this->jenis, $kategori, $tanggal, $request->user(), $depoIds);

        if (! $hasil['dibuat']) {
            throw ValidationException::withMessages(['periode_mulai' => 'Tidak ada draf baru: semua depo sudah punya penggajian pada periode yang tumpang tindih ('.self::ringkasDepoDilewati($hasil['dilewati']).').']);
        }

        $pesan = redirect('/'.$this->menu().'?tab='.$kategori)
            ->with('sukses', 'Draf penggajian dibuat untuk '.count($hasil['dibuat']).' depo ('.$hasil['orang'].' orang).');

        if ($hasil['dilewati']) {
            $pesan->with('galat', 'Dilewati '.count($hasil['dilewati']).' depo yang periodenya tumpang tindih dengan penggajian yang sudah ada: '.self::ringkasDepoDilewati($hasil['dilewati']).'.');
        }

        return $pesan;
    }

    /**
     * Nama depo yang dilewati untuk pesan: tiga pertama, sisanya diringkas.
     *
     * @param  list<array{depo: string, alasan: string}>  $dilewati
     */
    private static function ringkasDepoDilewati(array $dilewati): string
    {
        $nama = array_column($dilewati, 'depo');
        $tampil = implode(', ', array_slice($nama, 0, 3));

        return count($nama) > 3 ? $tampil.', dan '.(count($nama) - 3).' lainnya' : $tampil;
    }

    public function tampil(Request $request, int $id): Response
    {
        $p = $this->muat($request, $id);

        $detail = $this->muatDetail($id);

        return Inertia::render('Penggajian/Detail', [
            'slug' => $this->menu(),
            'orangLabel' => $this->orangLabel(),
            'judul' => $p->no_penggajian,
            'penggajian' => [
                'id' => $p->id,
                'no_penggajian' => $p->no_penggajian,
                'kategori' => $p->kategori,
                'status' => $p->status,
                'status_label' => self::STATUS[$p->status] ?? $p->status,
                'depo' => $p->depo,
                'periode_mulai' => $p->periode_mulai,
                'periode_selesai' => $p->periode_selesai,
                'periode_label' => PeriodeGaji::label($p->periode_selesai),
                'tanggal_bayar' => $p->tanggal_bayar,
                // Periode yang belum berakhir: hari (gaji) atau penjualan (insentif) yang belum terjadi belum terhitung, jadi Finalkan memberi peringatan.
                // Kartu KPI kehadiran; insentif tidak punya data kehadiran (semuanya null). Rata-rata = total hadir ÷ hari
                // kerja periode (rata-rata orang yang hadir per hari kerja); tingkat = total hadir ÷ (hari kerja × pembagi).
                // Pembagi sales = kuota motoris depo (bukan jumlah motoris di draf); koordinator tidak punya kuota, jadi jumlah orang.
                ...self::ringkasKehadiran($detail, ...$this->pembagiKehadiran($p, count($detail))),
                'belum_selesai' => $p->periode_selesai >= Pengaturan::sekarang()->toDateString(),
                'total_pendapatan' => self::uang(array_sum(array_column($detail, 'total_pendapatan'))),
                'total_potongan' => self::uang(array_sum(array_column($detail, 'total_potongan'))),
                'total_bersih' => self::uang(array_sum(array_column($detail, 'total_bersih'))),
            ],
            'detail' => $detail,
            'kekurangan' => Penggajian::kekurangan($id),
            'pemotong' => DB::table('parameter_pemotong_gaji')->orderBy('nama')->get(['id', 'nama'])->map(fn ($r) => ['id' => (int) $r->id, 'nama' => $r->nama])->all(),
            'bisaUbah' => Akses::bisa($request->user(), $this->menu(), 'ubah'),
            'bisaEkspor' => Akses::bisa($request->user(), $this->menu(), 'ekspor'),
        ]);
    }

    public function hitungUlang(Request $request, int $id): RedirectResponse
    {
        $this->muat($request, $id);
        Penggajian::hitungUlang($id);

        return $this->kembali($id, 'Draf dihitung ulang. Komponen manual dan hari izin dipertahankan.');
    }

    public function finalkan(Request $request, int $id): RedirectResponse
    {
        $this->muat($request, $id);
        Penggajian::finalkan($id, $request->user());

        return $this->kembali($id, 'Penggajian difinalkan dan terkunci.');
    }

    /**
     * Finalkan beberapa draf sekaligus dari halaman daftar (ids[]). Semua atau tidak sama sekali: satu draf yang datanya belum
     * lengkap membatalkan seluruhnya. Yang sudah Final dilewati; yang Batal ditolak.
     */
    public function finalkanPilihan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
        ], [
            'ids.required' => 'Pilih dulu penggajian yang akan difinalkan.',
            'ids.min' => 'Pilih dulu penggajian yang akan difinalkan.',
            'ids.max' => 'Terlalu banyak penggajian dipilih (maksimal 200).',
            'ids.*.integer' => 'Pilihan penggajian tidak valid.',
        ]);

        $ids = array_map('intval', $data['ids']);
        $penggajian = DB::table('penggajian')->whereIn('id', $ids)->where('jenis', $this->jenis)->get();
        abort_unless($penggajian->count() === count($ids), 404);

        $depoIds = Cakupan::untuk($request->user())->depoIds();
        abort_if($depoIds !== null && $penggajian->contains(fn ($p) => ! in_array((int) $p->depo_id, $depoIds, true)), 403);
        abort_if($penggajian->contains(fn ($p) => $p->status === 'batal'), 409, 'Penggajian yang dibatalkan tidak bisa difinalkan.');

        $draf = $penggajian->where('status', 'draf');
        abort_if($draf->isEmpty(), 409, 'Semua penggajian yang dipilih sudah Final.');

        DB::transaction(fn () => $draf->each(fn ($p) => Penggajian::finalkan((int) $p->id, $request->user())));

        return redirect('/'.$this->menu().'?tab='.$penggajian->first()->kategori)->with('sukses', $draf->count().' penggajian difinalkan dan terkunci.');
    }

    public function kembalikanKeDraf(Request $request, int $id): RedirectResponse
    {
        $this->muat($request, $id);
        Penggajian::kembalikanKeDraf($id, $request->user());

        return $this->kembali($id, 'Penggajian dikembalikan ke Draf. Koreksi lalu finalkan lagi.');
    }

    public function batalkan(Request $request, int $id): RedirectResponse
    {
        $this->muat($request, $id);
        Penggajian::batalkan($id, $request->user());

        return redirect('/'.$this->menu())->with('sukses', 'Draf penggajian dibatalkan.');
    }

    public function isiIzin(Request $request, int $id, int $detail): RedirectResponse
    {
        $this->muat($request, $id);
        $d = $this->detailDari($id, $detail);

        $data = $request->validate([
            'hari_izin' => ['required', 'integer', 'min:0', 'max:'.(int) $d->hari_kerja],
        ], [
            'hari_izin.required' => 'Hari izin wajib diisi.',
            'hari_izin.integer' => 'Hari izin harus angka bulat.',
            'hari_izin.min' => 'Hari izin tidak boleh negatif.',
            'hari_izin.max' => 'Hari izin tidak boleh melebihi hari kerja ('.(int) $d->hari_kerja.').',
        ]);

        Penggajian::isiHariIzin($id, $detail, (int) $data['hari_izin']);

        return $this->kembali($id, "Hari izin {$d->nama} disimpan.");
    }

    public function tambahKomponen(Request $request, int $id, int $detail): RedirectResponse
    {
        $this->muat($request, $id);
        $this->detailDari($id, $detail);

        Penggajian::tambahKomponen($id, $detail, $this->dataKomponen($request));

        return $this->kembali($id, 'Komponen ditambahkan.');
    }

    public function ubahKomponen(Request $request, int $id, int $detail, int $komponen): RedirectResponse
    {
        $this->muat($request, $id);
        $this->detailDari($id, $detail);
        $this->komponenDari($detail, $komponen);

        Penggajian::ubahKomponen($id, $detail, $komponen, $this->dataKomponen($request));

        return $this->kembali($id, 'Komponen diubah.');
    }

    public function hapusKomponen(Request $request, int $id, int $detail, int $komponen): RedirectResponse
    {
        $this->muat($request, $id);
        $this->detailDari($id, $detail);
        $this->komponenDari($detail, $komponen);

        Penggajian::hapusKomponen($id, $detail, $komponen);

        return $this->kembali($id, 'Komponen dihapus.');
    }

    /** Filter daftar dari query string: [periode (Y-m bulan label), depo_id, status], nilai tidak valid dianggap kosong. */
    private function filterDaftar(Request $request): array
    {
        $periode = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('periode')) ? (string) $request->query('periode') : '';
        $status = array_key_exists((string) $request->query('status'), self::STATUS) ? (string) $request->query('status') : '';

        return [$periode, (int) $request->query('depo_id'), $status];
    }

    /** Query daftar penggajian jenis ini untuk satu kategori, dibatasi cakupan akun dan filter; dipakai halaman dan ekspor. */
    private function daftarQuery(?array $depoIds, string $tab, string $periode, int $depo, string $status): Builder
    {
        $detail = fn (string $ekspresi) => DB::table('penggajian_detail')->whereColumn('penggajian_id', 'p.id')->selectRaw($ekspresi);

        return DB::table('penggajian as p')->leftJoin('mk_depo as d', 'd.id', '=', 'p.depo_id')
            ->where('p.jenis', $this->jenis)->where('p.kategori', $tab)
            ->when($depoIds !== null, fn ($q) => $q->whereIn('p.depo_id', $depoIds ?: [0]))
            // Periode dinamai menurut bulan tanggal akhirnya (lihat PeriodeGaji::label).
            ->when($periode !== '', fn ($q) => $q->where('p.periode_selesai', 'like', $periode.'-%'))
            ->when($depo > 0, fn ($q) => $q->where('p.depo_id', $depo))
            ->when($status !== '', fn ($q) => $q->where('p.status', $status))
            ->select(['p.id', 'p.no_penggajian', 'p.kategori', 'p.periode_mulai', 'p.periode_selesai', 'p.tanggal_bayar', 'p.status', 'd.nama as depo'])
            ->selectSub($detail('count(*)'), 'orang')
            ->selectSub($detail('coalesce(sum(total_pendapatan), 0)'), 'total_pendapatan')
            ->selectSub($detail('coalesce(sum(total_potongan), 0)'), 'total_potongan')
            ->selectSub($detail('coalesce(sum(total_bersih), 0)'), 'total')
            ->orderByDesc('p.periode_mulai')->orderByDesc('p.id');
    }

    /** Semua orang di penggajian beserta komponennya (urut nama), untuk halaman detail dan ekspor. */
    private function muatDetail(int $id): array
    {
        $komponen = DB::table('penggajian_komponen')
            ->whereIn('detail_id', DB::table('penggajian_detail')->where('penggajian_id', $id)->select('id'))
            ->orderBy('id')->get()->groupBy('detail_id');

        return DB::table('penggajian_detail')->where('penggajian_id', $id)->orderBy('nama')->get()
            ->map(fn ($d) => self::petakanDetail($d, $komponen[$d->id] ?? collect()))->values()->all();
    }

    /** Ekspor satu penggajian (dari halaman detail): sama dengan ekspor pilihan dengan satu id. */
    public function ekspor(Request $request, int $id): StreamedResponse
    {
        return $this->unduhRincian($request, [$id]);
    }

    /** Ekspor penggajian yang dipilih di halaman daftar (ids[]): pengguna harus memilih dulu no. penggajian yang diekspor. */
    public function eksporPilihan(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
        ], [
            'ids.required' => 'Pilih dulu no. penggajian yang akan diekspor.',
            'ids.min' => 'Pilih dulu no. penggajian yang akan diekspor.',
            'ids.max' => 'Terlalu banyak penggajian dipilih (maksimal 200).',
            'ids.*.integer' => 'Pilihan penggajian tidak valid.',
        ]);

        return $this->unduhRincian($request, array_map('intval', $data['ids']));
    }

    /**
     * Ekspor rincian per orang dari penggajian yang dipilih ke Excel. Satu penggajian: satu sheet "Rincian". Beberapa
     * penggajian: sheet "Konsolidasi" (rincian per orang dari semua depo dalam satu tabel) lalu satu sheet per depo berisi rincian
     * penggajian terpilih milik depo itu. Hanya penggajian Final, dalam cakupan akun, sejenis, dan sekategori (gaji atau insentif).
     *
     * @param  list<int>  $ids
     */
    private function unduhRincian(Request $request, array $ids): StreamedResponse
    {
        $ids = array_values(array_unique($ids));

        $penggajian = DB::table('penggajian as p')->leftJoin('mk_depo as d', 'd.id', '=', 'p.depo_id')
            ->whereIn('p.id', $ids)->where('p.jenis', $this->jenis)
            ->orderBy('d.nama')->orderBy('p.periode_mulai')->orderBy('p.id')->get(['p.*', 'd.nama as depo']);

        abort_unless($penggajian->count() === count($ids), 404);

        $depoIds = Cakupan::untuk($request->user())->depoIds();
        abort_if($depoIds !== null && $penggajian->contains(fn ($p) => ! in_array((int) $p->depo_id, $depoIds, true)), 403);
        // Ekspor hanya untuk penggajian yang sudah final (angka terkunci dan sudah ditinjau).
        abort_unless($penggajian->every(fn ($p) => $p->status === 'final'), 409, 'Ekspor Excel hanya tersedia untuk penggajian berstatus Final.');
        abort_unless($penggajian->pluck('kategori')->unique()->count() === 1, 422, 'Pilih penggajian dengan kategori yang sama (Gaji atau Insentif).');

        $gaji = $penggajian->first()->kategori === 'gaji';
        $semua = $penggajian->map(fn ($p) => [$p, $this->muatDetail((int) $p->id)]);
        $nik = DB::table('mk_karyawan')->whereIn('id', $semua->flatMap(fn ($x) => array_column($x[1], 'karyawan_id'))->unique()->all())->pluck('nik', 'id')->all();

        if ($semua->count() === 1) {
            $nama = $penggajian->first()->no_penggajian.'.xlsx';

            return Xlsx::unduhBanyak($nama, [$this->lembarRincian('Rincian', $semua, $gaji, $nik)]);
        }

        $lembar = [$this->lembarRincian('Konsolidasi', $semua, $gaji, $nik)];

        foreach ($semua->groupBy(fn ($x) => (string) $x[0]->depo) as $depo => $kelompok) {
            $lembar[] = $this->lembarRincian($depo !== '' ? $depo : 'Tanpa depo', $kelompok, $gaji, $nik);
        }

        return Xlsx::unduhBanyak('penggajian_'.$this->jenis.'_'.$penggajian->first()->kategori.'_'.now()->format('Ymd_His').'.xlsx', $lembar);
    }

    /** Label periode untuk berkas ekspor. */
    private static function periodeEkspor(object $p): string
    {
        return PeriodeGaji::label($p->periode_selesai).' ('.$p->periode_mulai.' s.d. '.$p->periode_selesai.')';
    }

    /**
     * Sheet rincian per orang sebagai tabel datar: identitas penggajian (no., depo, periode, tanggal bayar), nama, hari
     * (gaji), satu kolom per komponen (otomatis dan manual yang muncul di sheet ini), dan total, ditutup baris total.
     *
     * @param  Collection<int, array{0: object, 1: list<array<string, mixed>>}>  $kelompok
     * @param  array<int, ?string>  $nik  NIK/KTP per karyawan_id (ditulis sebagai teks: 16 digit melebihi presisi angka Excel)
     * @return array{nama: string, baris: list<list<mixed>>, opsi: array<string, mixed>}
     */
    private function lembarRincian(string $nama, Collection $kelompok, bool $gaji, array $nik): array
    {
        // Kolom komponen menurut nama: pendapatan dulu, lalu potongan, urut kemunculan pertama.
        $kolom = ['pendapatan' => [], 'potongan' => []];

        foreach ($kelompok as [, $detail]) {
            foreach ($detail as $d) {
                foreach ($d['komponen'] as $k) {
                    $kolom[$k['tipe']][$k['nama']] = true;
                }
            }
        }

        $namaKomponen = [...array_keys($kolom['pendapatan']), ...array_keys($kolom['potongan'])];
        $tipeKomponen = [...array_fill(0, count($kolom['pendapatan']), 'pendapatan'), ...array_fill(0, count($kolom['potongan']), 'potongan')];

        $hari = $gaji ? ['Hari Kerja', 'Hadir', 'Tidak Hadir', 'Terlambat', 'Izin'] : [];
        $header = ['No', 'No. Penggajian', 'Depo', 'Periode', 'Tanggal Bayar', 'Nama', 'NIK/KTP', ...$hari, ...$namaKomponen, 'Total Pendapatan', 'Total Potongan', 'Total Bersih'];
        $baris = [$header];
        $jumlah = array_fill(0, count($namaKomponen) + 3, 0);
        $no = 0;

        foreach ($kelompok as [$p, $detail]) {
            foreach ($detail as $d) {
                $nilai = [];

                foreach ($namaKomponen as $n => $namaK) {
                    $nilai[$n] = collect($d['komponen'])->where('tipe', $tipeKomponen[$n])->where('nama', $namaK)->sum('nominal') ?: null;
                }

                $angka = [...$nilai, $d['total_pendapatan'], $d['total_potongan'], $d['total_bersih']];

                foreach ($angka as $n => $v) {
                    $jumlah[$n] += $v ?? 0;
                }

                $baris[] = [
                    ++$no, $p->no_penggajian, $p->depo, self::periodeEkspor($p), $p->tanggal_bayar, $d['nama'], ($nik[$d['karyawan_id']] ?? null) ?: null,
                    ...($gaji ? [$d['hari_kerja'], $d['hari_hadir'], $d['hari_tidak_hadir'], $d['hari_terlambat'], $d['hari_izin']] : []),
                    ...$angka,
                ];
            }
        }

        $indeksTotal = count($baris);
        $baris[] = [null, null, null, null, null, 'Total', null, ...array_fill(0, count($hari), null), ...$jumlah];

        return ['nama' => $nama, 'baris' => $baris, 'opsi' => ['header' => 0, 'tebal' => [$indeksTotal], 'uang' => range(7 + count($hari), count($header) - 1)]];
    }

    /**
     * Satu orang di penggajian beserta komponennya, untuk tabel dan drawer rincian (dipakai halaman detail dan riwayat).
     *
     * @param  Collection<int, object>  $komponen
     * @return array<string, mixed>
     */
    private static function petakanDetail(object $d, Collection $komponen): array
    {
        return [
            'id' => $d->id,
            'karyawan_id' => $d->karyawan_id,
            'nama' => $d->nama,
            'hari_kerja' => $d->hari_kerja,
            'hari_hadir' => $d->hari_hadir,
            'hari_terlambat' => $d->hari_terlambat,
            'hari_izin' => $d->hari_izin,
            // Hari kerja yang tidak dihadiri (termasuk yang diizinkan); null untuk insentif yang tidak punya data hari.
            'hari_tidak_hadir' => $d->hari_kerja === null ? null : max(0, (int) $d->hari_kerja - (int) $d->hari_hadir),
            'total_pendapatan' => self::uang($d->total_pendapatan),
            'total_potongan' => self::uang($d->total_potongan),
            'total_bersih' => self::uang($d->total_bersih),
            'komponen' => $komponen->map(fn ($k) => [
                'id' => $k->id,
                'tipe' => $k->tipe,
                'kode' => $k->kode,
                'nama' => $k->nama,
                'nominal' => self::uang($k->nominal),
                'sumber' => $k->sumber,
                'keterangan' => $k->keterangan,
                'rincian' => $k->rincian ? json_decode($k->rincian, true) : null,
            ])->values()->all(),
        ];
    }

    /**
     * Riwayat gaji dan insentif per orang: detail dari penggajian sales yang sudah final saja (draf dan batal tidak
     * tampil), dalam cakupan depo akun, dicari menurut nama. Tiap baris memuat rincian komponen untuk drawer.
     */
    private function riwayat(Request $request, ?array $depoIds): Response
    {
        $q = trim((string) $request->query('q'));
        // Karakter pencarian dianggap huruf biasa (bukan wildcard LIKE); '!' sebagai karakter escape yang portabel.
        $pola = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)).'%';

        $halaman = DB::table('penggajian_detail as d')->join('penggajian as p', 'p.id', '=', 'd.penggajian_id')
            ->leftJoin('mk_depo as dp', 'dp.id', '=', 'p.depo_id')
            ->where('p.status', 'final')->where('p.jenis', $this->jenis)
            ->when($depoIds !== null, fn ($w) => $w->whereIn('p.depo_id', $depoIds ?: [0]))
            ->when($q !== '', fn ($w) => $w->whereRaw("LOWER(d.nama) LIKE ? ESCAPE '!'", [$pola]))
            ->orderByDesc('p.periode_mulai')->orderBy('d.nama')->orderBy('d.id')
            ->select(['d.*', 'p.kategori', 'p.no_penggajian', 'p.periode_mulai', 'p.periode_selesai', 'dp.nama as depo'])
            ->paginate(25)->withQueryString()->onEachSide(1);

        $komponen = DB::table('penggajian_komponen')->whereIn('detail_id', collect($halaman->items())->pluck('id'))
            ->orderBy('id')->get()->groupBy('detail_id');

        $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');

        $halaman->through(fn ($r) => [
            'id' => $r->id,
            'nama' => $r->nama,
            'depo' => $r->depo,
            'no_penggajian' => $r->no_penggajian,
            'kategori' => self::KATEGORI[$r->kategori] ?? $r->kategori,
            'periode' => PeriodeGaji::label($r->periode_selesai).' ('.self::tgl($r->periode_mulai).' – '.self::tgl($r->periode_selesai).')',
            'pendapatan' => $rp($r->total_pendapatan),
            'potongan' => $rp($r->total_potongan),
            'bersih' => $rp($r->total_bersih),
            'orang' => self::petakanDetail($r, $komponen[$r->id] ?? collect()) + ['kategori' => $r->kategori],
        ]);

        return Inertia::render('Penggajian/Index', [
            'slug' => $this->menu(),
            'judul' => config('marketama.pages.'.$this->menu().'.title') ?? 'Penggajian '.ucfirst($this->orangLabel()),
            'orangLabel' => $this->orangLabel(),
            'catatan' => null,
            'baris' => $halaman,
            'tab' => 'riwayat',
            'tabs' => self::tabs(),
            'q' => $q,
            'filter' => ['periode' => '', 'depo_id' => '', 'status' => ''],
            'opsi' => ['depo' => [], 'status' => self::STATUS],
            'bisaTambah' => false,
        ]);
    }

    /** @return list<array{key: string, label: string}> */
    private static function tabs(): array
    {
        return [
            ...collect(self::KATEGORI)->map(fn ($label, $kunci) => ['key' => $kunci, 'label' => $label])->values()->all(),
            ['key' => 'riwayat', 'label' => 'Riwayat per Orang'],
        ];
    }

    /**
     * Pembagi tingkat kehadiran: untuk sales, kuota motoris depo pada bulan label periode (sumber yang sama dengan Motoris
     * Report; null bila kuota belum diisi); untuk koordinator tidak ada kuota, jadi jumlah koordinator di penggajian.
     *
     * @return array{0: string, 1: int|null} [dasar ('kuota' | 'orang'), pembagi]
     */
    private function pembagiKehadiran(object $p, int $jumlahOrang): array
    {
        if ($this->jenis !== 'sales') {
            return ['orang', $jumlahOrang];
        }

        $kuota = KuotaMotoris::untukBulan(substr((string) $p->periode_selesai, 0, 7), [(int) $p->depo_id])->first()?->kuota;

        return ['kuota', $kuota === null ? null : (int) $kuota];
    }

    /**
     * Ringkasan kehadiran seluruh orang di penggajian untuk kartu KPI: total hari hadir, rata-rata hadir per hari kerja,
     * dan tingkat kehadiran dalam persen terhadap $pembagi. Semuanya null bila tidak ada data kehadiran (insentif);
     * rata-rata dan tingkat null bila hari kerja 0, dan tingkat null bila pembagi kosong.
     *
     * @param  list<array<string, mixed>>  $detail
     * @return array{total_hadir: int|null, rata_hadir: float|null, tingkat_hadir: float|null, dasar_hadir: string|null, pembagi_hadir: int|null}
     */
    private static function ringkasKehadiran(array $detail, string $dasar, ?int $pembagi): array
    {
        $hadir = collect($detail)->pluck('hari_hadir')->filter(fn ($h) => $h !== null);
        $hariKerja = (int) collect($detail)->pluck('hari_kerja')->filter(fn ($h) => $h !== null)->max();

        if ($hadir->isEmpty()) {
            return ['total_hadir' => null, 'rata_hadir' => null, 'tingkat_hadir' => null, 'dasar_hadir' => null, 'pembagi_hadir' => null];
        }

        $total = (int) $hadir->sum();

        return [
            'total_hadir' => $total,
            'rata_hadir' => $hariKerja > 0 ? round($total / $hariKerja, 1) : null,
            'tingkat_hadir' => $hariKerja > 0 && $pembagi > 0 ? round($total / ($hariKerja * $pembagi) * 100, 1) : null,
            'dasar_hadir' => $dasar,
            'pembagi_hadir' => $pembagi,
        ];
    }

    /** Penggajian gaji sales ini (404 bila tidak ada atau beda jenis), dalam cakupan depo akun (403). */
    private function muat(Request $request, int $id): object
    {
        $p = DB::table('penggajian as p')->leftJoin('mk_depo as d', 'd.id', '=', 'p.depo_id')
            ->where('p.id', $id)->where('p.jenis', $this->jenis)
            ->first(['p.*', 'd.nama as depo']);

        abort_unless($p, 404);

        $depoIds = Cakupan::untuk($request->user())->depoIds();
        abort_if($depoIds !== null && ! in_array((int) $p->depo_id, $depoIds, true), 403);

        return $p;
    }

    private function detailDari(int $id, int $detail): object
    {
        $d = DB::table('penggajian_detail')->where('id', $detail)->where('penggajian_id', $id)->first();
        abort_unless($d, 404);

        return $d;
    }

    private function komponenDari(int $detail, int $komponen): void
    {
        abort_unless(DB::table('penggajian_komponen')->where('id', $komponen)->where('detail_id', $detail)->exists(), 404);
    }

    private function kembali(int $id, string $pesan): RedirectResponse
    {
        return redirect('/'.$this->menu().'/'.$id)->with('sukses', $pesan);
    }

    /** @return array{tipe: string, nama?: string, nominal: string, keterangan?: ?string, parameter_pemotong_id?: int} */
    private function dataKomponen(Request $request): array
    {
        if ($request->filled('nominal')) {
            $request->merge(['nominal' => str_replace(['.', ' ', 'Rp'], '', (string) $request->input('nominal'))]);
        }

        return $request->validate([
            'tipe' => ['required', 'in:pendapatan,potongan'],
            'parameter_pemotong_id' => ['exclude_unless:tipe,potongan', 'required', 'integer', 'exists:parameter_pemotong_gaji,id'],
            'nama' => ['exclude_unless:tipe,pendapatan', 'required', 'string', 'max:100'],
            'nominal' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ], [
            'tipe.required' => 'Jenis komponen wajib dipilih.',
            'tipe.in' => 'Jenis komponen tidak valid.',
            'parameter_pemotong_id.required' => 'Pilih jenis potongan dari Parameter Pemotong Gaji.',
            'parameter_pemotong_id.exists' => 'Jenis potongan tidak ada di Parameter Pemotong Gaji.',
            'nama.required' => 'Nama komponen wajib diisi.',
            'nominal.required' => 'Nominal wajib diisi.',
            'nominal.numeric' => 'Nominal harus berupa angka.',
            'nominal.gt' => 'Nominal harus lebih dari nol.',
            'nominal.max' => 'Nominal terlalu besar.',
        ]);
    }

    /** Kategori dari query, atau gaji bila kosong atau tidak dikenal. */
    private function kategori(string $nilai): string
    {
        return array_key_exists($nilai, self::KATEGORI) ? $nilai : 'gaji';
    }

    /**
     * Periode berjalan dan lima periode sebelumnya, terbaru dulu (untuk pilihan di form Tambah): periode cut off untuk
     * gaji, bulan kalender untuk insentif.
     */
    private function daftarPeriode(string $kategori): array
    {
        $hasil = [];
        $tanggal = Pengaturan::sekarang()->toDateString();

        for ($i = 0; $i < 6; $i++) {
            $p = $kategori === 'insentif' ? PeriodeGaji::insentif($tanggal) : PeriodeGaji::gaji($tanggal);
            $hasil[] = $p;
            $tanggal = CarbonImmutable::parse($p['mulai'])->subDay()->toDateString();
        }

        return $hasil;
    }

    /** @return list<array{id: int, nama: string}> */
    private function daftarDepo(?array $depoIds): array
    {
        return DB::table('mk_depo')->when($depoIds !== null, fn ($q) => $q->whereIn('id', $depoIds ?: [0]))
            ->orderBy('nama')->get(['id', 'nama'])->map(fn ($d) => ['id' => (int) $d->id, 'nama' => $d->nama])->all();
    }

    private static function tgl(string $tanggal): string
    {
        return CarbonImmutable::parse($tanggal)->locale('id')->translatedFormat('j M Y');
    }

    /** Nominal rupiah: bilangan bulat bila tanpa pecahan (decimal(18,2) dari database berupa string). */
    private static function uang(mixed $nilai): int|float
    {
        $f = (float) $nilai;

        return floor($f) === $f ? (int) $f : $f;
    }
}
