<?php

namespace App\Http\Controllers;

use App\Support\Akses\Akses;
use App\Support\Kendala\LaporanKendala;
use App\Support\Menu\JamLokal;
use App\Support\Mobile\Pengaturan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

// Laporan Kendala Lapangan (spec 064): koordinator mencatat kendala operasional, atasan membaca sesuai cakupan role.
// Daftar dibatasi Akses::batasiKaryawan terhadap karyawan PELAPOR (mk_karyawan): downline = laporan sendiri dan
// koordinator di bawahnya, depo = pelapor di depo akun, semua = semua laporan.
class LaporanKendalaController extends Controller
{
    private const PER_HALAMAN = 25;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $cakupan = Akses::cakupan($user);

        $status = $request->query('status');
        $status = $status === 'semua' || isset(LaporanKendala::STATUS[$status]) ? $status : LaporanKendala::STATUS_TERBUKA;
        $kategori = isset(LaporanKendala::KATEGORI[$request->query('kategori')]) ? $request->query('kategori') : null;
        $depo = $request->integer('depo') ?: null;
        $dari = $this->tanggal($request->query('dari'));
        $sampai = $this->tanggal($request->query('sampai'));
        $q = trim((string) $request->query('q'));
        $belumDibaca = $request->boolean('belum_dibaca');

        $baris = LaporanKendala::dalamCakupan($user)
            ->leftJoin('mk_depo as d', 'd.id', '=', 'l.depo_id')
            ->leftJoin('laporan_kendala_dibaca as b', fn ($j) => $j->on('b.laporan_kendala_id', '=', 'l.id')->where('b.user_id', $user->id))
            ->when($belumDibaca, fn ($w) => $w->where('l.status', LaporanKendala::STATUS_TERBUKA)->where('l.user_id', '!=', $user->id)->whereNull('b.id'))
            ->when($status !== 'semua', fn ($w) => $w->where('l.status', $status))
            ->when($kategori, fn ($w) => $w->where('l.kategori', $kategori))
            ->when($depo, fn ($w) => $w->where('l.depo_id', $depo))
            ->when($dari, fn ($w) => $w->where('l.tanggal_kejadian', '>=', $dari))
            ->when($sampai, fn ($w) => $w->where('l.tanggal_kejadian', '<=', $sampai))
            ->when($q !== '', fn ($w) => $w->where(function ($x) use ($q) {
                foreach (['l.judul', 'l.uraian', 'l.pelapor_nama'] as $kolom) {
                    $x->orWhereRaw("LOWER({$kolom}) LIKE ?", ['%'.mb_strtolower($q).'%']);
                }
            }))
            ->orderByDesc('l.tanggal_kejadian')->orderByDesc('l.id')
            ->select('l.id', 'l.user_id', 'l.tanggal_kejadian', 'l.kategori', 'l.judul', 'l.uraian', 'l.pelapor_nama', 'l.status', 'l.catatan_selesai', 'l.selesai_at', 'l.updated_at', 'd.nama as depo', 'b.dibaca_at',
                DB::raw('(select count(*) from laporan_kendala_dibaca as p where p.laporan_kendala_id = l.id) as jumlah_pembaca'))
            ->paginate(self::PER_HALAMAN)->withQueryString()->onEachSide(1);

        $bolehUbah = Akses::bisa($user, LaporanKendala::MENU, 'ubah');

        $baris->through(fn ($l) => [
            'id' => $l->id,
            'tanggal_kejadian' => $l->tanggal_kejadian,
            'kategori' => $l->kategori,
            'kategori_label' => LaporanKendala::labelKategori($l->kategori),
            'judul' => $l->judul,
            'uraian' => $l->uraian,
            'pelapor' => $l->pelapor_nama,
            'depo' => $l->depo,
            'status' => $l->status,
            'status_label' => LaporanKendala::labelStatus($l->status),
            'diperbarui' => JamLokal::format($l->updated_at),
            'catatan_selesai' => $l->catatan_selesai,
            'selesai' => JamLokal::format($l->selesai_at),
            // Lencana untuk pembaca (bukan pelapor): Terbuka yang belum pernah dibuka akun ini, atau diubah pelapor setelah dibaca.
            'belum_dibaca' => (int) $l->user_id !== $user->id && $l->status === LaporanKendala::STATUS_TERBUKA && $l->dibaca_at === null,
            'diperbarui_setelah_dibaca' => (int) $l->user_id !== $user->id && $l->dibaca_at !== null && $l->updated_at > $l->dibaca_at,
            'jumlah_pembaca' => (int) $l->jumlah_pembaca,
            // Hanya pelapor sendiri, selama Terbuka (supervisor/admin hanya membaca).
            'bisa_ubah' => $bolehUbah && (int) $l->user_id === $user->id && $l->status === LaporanKendala::STATUS_TERBUKA,
        ]);

        return Inertia::render('LaporanKendala/Index', [
            'slug' => LaporanKendala::MENU,
            'judul' => config('marketama.pages.'.LaporanKendala::MENU.'.title') ?? 'Laporan Kendala',
            'baris' => $baris,
            'filter' => ['belum_dibaca' => $belumDibaca, 'status' => $status, 'kategori' => $kategori, 'depo' => $depo, 'dari' => $dari, 'sampai' => $sampai, 'q' => $q],
            'pilihanStatus' => LaporanKendala::STATUS,
            'pilihanKategori' => LaporanKendala::KATEGORI,
            // Pilihan depo hanya berguna bila cakupan lebih luas dari downline (satu tim biasanya satu depo).
            'daftarDepo' => in_array($cakupan, ['semua', 'depo'], true) ? $this->daftarDepo($user) : [],
            'bisa' => [
                'tambah' => Akses::bisa($user, LaporanKendala::MENU, 'tambah'),
            ],
            'hariIni' => self::hariIni(),
        ]);
    }

    /**
     * Membuka detail: akun selain pelapor dicatat sebagai pembaca (satu baris per laporan-akun, waktu baca diperbarui).
     * Pelapor mendapat daftar pembaca dengan waktunya; yang lain daftar kosong.
     */
    public function dibaca(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $laporan = LaporanKendala::dalamCakupan($user)->where('l.id', $id)->first(['l.id', 'l.user_id']);

        abort_if($laporan === null, 404);

        if ((int) $laporan->user_id !== $user->id) {
            DB::table('laporan_kendala_dibaca')->updateOrInsert(
                ['laporan_kendala_id' => $id, 'user_id' => $user->id],
                ['dibaca_at' => now()],
            );

            return response()->json(['pembaca' => []]);
        }

        $pembaca = DB::table('laporan_kendala_dibaca as b')
            ->join('users as u', 'u.id', '=', 'b.user_id')
            ->where('b.laporan_kendala_id', $id)->orderByDesc('b.dibaca_at')
            ->get(['u.name', 'u.username', 'b.dibaca_at'])
            ->map(fn ($r) => ['nama' => $r->name ?: $r->username, 'dibaca' => JamLokal::format($r->dibaca_at)])
            ->values();

        return response()->json(['pembaca' => $pembaca]);
    }

    /** Laporan baru: data pelapor (akun, karyawan, nama, depo) diambil dari akun login, bukan dari isian form. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate($this->aturan());

        $karyawan = $user->karyawan_id ? DB::table('mk_karyawan')->find($user->karyawan_id) : null;

        if (! $karyawan) {
            throw ValidationException::withMessages([
                'karyawan' => 'Akun ini belum terhubung ke data karyawan, jadi laporan kendala tidak bisa dibuat. Hubungi admin.',
            ]);
        }

        DB::table('laporan_kendala')->insert($data + [
            'user_id' => $user->id,
            'karyawan_id' => $karyawan->id,
            'pelapor_nama' => $karyawan->nama,
            'depo_id' => $karyawan->depo_id,
            'status' => LaporanKendala::STATUS_TERBUKA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('sukses', 'Laporan kendala dibuat.');
    }

    /** Ubah isi laporan: hanya pelapor sendiri dan hanya selama Terbuka. */
    public function update(Request $request, int $id): RedirectResponse
    {
        $laporan = DB::table('laporan_kendala')->where('id', $id)->first();

        abort_if($laporan === null, 404);
        abort_unless((int) $laporan->user_id === $request->user()->id, 403, 'Laporan hanya bisa diubah oleh pelapornya.');
        abort_unless($laporan->status === LaporanKendala::STATUS_TERBUKA, 422, 'Laporan yang sudah Selesai tidak bisa diubah lagi.');

        DB::table('laporan_kendala')->where('id', $id)->where('status', LaporanKendala::STATUS_TERBUKA)->update($request->validate($this->aturan()) + ['updated_at' => now()]);

        return back()->with('sukses', 'Laporan kendala diperbarui.');
    }

    /** Tutup laporan sebagai Selesai dengan catatan penyelesaian (wajib); hanya pelapor, hanya dari Terbuka. */
    public function selesai(Request $request, int $id): RedirectResponse
    {
        $laporan = DB::table('laporan_kendala')->where('id', $id)->first();

        abort_if($laporan === null, 404);
        abort_unless((int) $laporan->user_id === $request->user()->id, 403, 'Laporan hanya bisa ditutup oleh pelapornya.');
        abort_unless($laporan->status === LaporanKendala::STATUS_TERBUKA, 422, 'Laporan ini sudah Selesai.');

        $data = $request->validate(['catatan_selesai' => ['required', 'string', 'max:5000']]);

        DB::table('laporan_kendala')->where('id', $id)->where('status', LaporanKendala::STATUS_TERBUKA)->update([
            'status' => LaporanKendala::STATUS_SELESAI,
            'catatan_selesai' => $data['catatan_selesai'],
            'selesai_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('sukses', 'Laporan kendala ditandai Selesai.');
    }

    /** @return array<string, mixed> */
    private function aturan(): array
    {
        return [
            'kategori' => ['required', Rule::in(array_keys(LaporanKendala::KATEGORI))],
            'tanggal_kejadian' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.self::hariIni()],
            'judul' => ['required', 'string', 'max:120'],
            'uraian' => ['required', 'string', 'max:5000'],
        ];
    }

    private static function hariIni(): string
    {
        return Carbon::now(Pengaturan::zona())->toDateString();
    }

    /** @return array<int|string, string> id depo → nama, hanya depo yang punya laporan dalam cakupan akun. */
    private function daftarDepo($user): array
    {
        return LaporanKendala::dalamCakupan($user)
            ->join('mk_depo as d', 'd.id', '=', 'l.depo_id')
            ->distinct()->orderBy('d.nama')->pluck('d.nama', 'd.id')->all();
    }

    private function tanggal(mixed $nilai): ?string
    {
        return is_string($nilai) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai) ? $nilai : null;
    }
}
