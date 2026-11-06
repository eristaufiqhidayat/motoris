<?php

namespace App\Http\Controllers;

use App\Support\Akses\Akses;
use App\Support\Akses\Cakupan;
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
// Daftar dibatasi Cakupan terhadap karyawan PELAPOR (mk_karyawan): downline = laporan sendiri dan
// koordinator di bawahnya, depo = pelapor di depo akun, semua = semua laporan.
class LaporanKendalaController extends Controller
{
    private const PER_HALAMAN = 25;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $cakupan = Cakupan::untuk($user)->mode();

        $status = $request->query('status');
        $status = $status === 'semua' || isset(LaporanKendala::STATUS[$status]) ? $status : LaporanKendala::STATUS_TERBUKA;
        $kategori = isset(LaporanKendala::KATEGORI[$request->query('kategori')]) ? $request->query('kategori') : null;
        $depo = $request->integer('depo') ?: null;
        $dari = $this->tanggal($request->query('dari'));
        $sampai = $this->tanggal($request->query('sampai'));
        $q = trim((string) $request->query('q'));
        $belumDibaca = $request->boolean('belum_dibaca');
        $adaBalasan = $request->boolean('ada_balasan');

        $baris = LaporanKendala::dalamCakupan($user)
            ->leftJoin('mk_depo as d', 'd.id', '=', 'l.depo_id')
            ->leftJoin('laporan_kendala_dibaca as b', fn ($j) => $j->on('b.laporan_kendala_id', '=', 'l.id')->where('b.user_id', $user->id))
            ->when($belumDibaca, fn ($w) => $w->where('l.status', LaporanKendala::STATUS_TERBUKA)->where('l.user_id', '!=', $user->id)->whereNull('b.id'))
            ->when($adaBalasan, fn ($w) => $w->whereRaw(LaporanKendala::sqlAdaBalasanBaru($user->id)))
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
                DB::raw(LaporanKendala::sqlAdaBalasanBaru($user->id).' as ada_balasan_baru'),
                DB::raw("(select count(*) from laporan_kendala_komentar as c where c.laporan_kendala_id = l.id and c.jenis = 'komentar') as jumlah_komentar"))
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
            // Lencana: Terbuka yang belum pernah dibuka akun ini (bukan pelapor); Ada balasan baru = aktivitas orang lain
            // (komentar, perubahan, selesai, dibuka kembali) setelah akun ini terakhir membuka laporan.
            'belum_dibaca' => (int) $l->user_id !== $user->id && $l->status === LaporanKendala::STATUS_TERBUKA && $l->dibaca_at === null,
            'ada_balasan_baru' => (bool) $l->ada_balasan_baru,
            'jumlah_komentar' => (int) $l->jumlah_komentar,
            // Hanya pelapor sendiri, selama Terbuka (supervisor/admin hanya membaca).
            'bisa_ubah' => $bolehUbah && (int) $l->user_id === $user->id && $l->status === LaporanKendala::STATUS_TERBUKA,
            // Pelapor boleh membuka kembali laporannya yang Selesai (spec 065).
            'bisa_buka_kembali' => $bolehUbah && (int) $l->user_id === $user->id && $l->status === LaporanKendala::STATUS_SELESAI,
        ]);

        return Inertia::render('LaporanKendala/Index', [
            'slug' => LaporanKendala::MENU,
            'judul' => config('marketama.pages.'.LaporanKendala::MENU.'.title') ?? 'Laporan Kendala',
            'baris' => $baris,
            'filter' => ['belum_dibaca' => $belumDibaca, 'ada_balasan' => $adaBalasan, 'status' => $status, 'kategori' => $kategori, 'depo' => $depo, 'dari' => $dari, 'sampai' => $sampai, 'q' => $q],
            'pilihanStatus' => LaporanKendala::STATUS,
            'pilihanKategori' => LaporanKendala::KATEGORI,
            // Pilihan depo hanya berguna bila cakupan lebih luas dari downline (satu tim biasanya satu depo).
            'daftarDepo' => in_array($cakupan, ['semua', 'depo'], true) ? $this->daftarDepo($user) : [],
            'bisa' => [
                'tambah' => LaporanKendala::bolehMelapor($user),
            ],
            'hariIni' => self::hariIni(),
        ]);
    }

    /**
     * Membuka detail: akun mana pun (termasuk pelapor) dicatat membuka laporan (satu baris per laporan-akun, waktu
     * baca diperbarui) sebagai dasar lencana Ada balasan baru. Daftar pembaca (selain pelapor) hanya dikirim ke pelapor.
     */
    public function dibaca(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $laporan = LaporanKendala::dalamCakupan($user)->where('l.id', $id)->first(['l.id', 'l.user_id']);

        abort_if($laporan === null, 404);

        DB::table('laporan_kendala_dibaca')->updateOrInsert(
            ['laporan_kendala_id' => $id, 'user_id' => $user->id],
            ['dibaca_at' => now()],
        );

        if ((int) $laporan->user_id !== $user->id) {
            return response()->json(['pembaca' => [], 'komentar' => $this->linimasa($id, $user->id)]);
        }

        $pembaca = DB::table('laporan_kendala_dibaca as b')
            ->join('users as u', 'u.id', '=', 'b.user_id')
            ->where('b.laporan_kendala_id', $id)->where('b.user_id', '!=', $laporan->user_id)->orderByDesc('b.dibaca_at')
            ->get(['u.name', 'u.username', 'b.dibaca_at'])
            ->map(fn ($r) => ['nama' => $r->name ?: $r->username, 'dibaca' => JamLokal::format($r->dibaca_at)])
            ->values();

        return response()->json(['pembaca' => $pembaca, 'komentar' => $this->linimasa($id, $user->id)]);
    }

    /** Kirim komentar (spec 065): akun yang boleh melihat laporan ini (cakupan role), termasuk pelapor; juga pada laporan Selesai. */
    public function komentar(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_if(LaporanKendala::dalamCakupan($user)->where('l.id', $id)->doesntExist(), 404);

        $data = $request->validate(['isi' => ['required', 'string', 'max:2000']]);

        DB::transaction(function () use ($id, $user, $data) {
            DB::table('laporan_kendala_komentar')->insert([
                'laporan_kendala_id' => $id,
                'user_id' => $user->id,
                'penulis_nama' => $user->name ?: $user->username,
                'jenis' => 'komentar',
                'isi' => $data['isi'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('laporan_kendala')->where('id', $id)->update(['aktivitas_at' => now(), 'aktivitas_oleh' => $user->id]);
        });

        return response()->json(['komentar' => $this->linimasa($id, $request->user()->id)], 201);
    }

    /** Sunting komentar sendiri (spec 065); peristiwa otomatis dan komentar orang lain tidak bisa. */
    public function suntingKomentar(Request $request, int $id, int $komentarId): JsonResponse
    {
        $this->komentarMilikSendiri($request, $id, $komentarId);

        $data = $request->validate(['isi' => ['required', 'string', 'max:2000']]);

        DB::table('laporan_kendala_komentar')->where('id', $komentarId)->update(['isi' => $data['isi'], 'disunting_at' => now(), 'updated_at' => now()]);

        return response()->json(['komentar' => $this->linimasa($id, $request->user()->id)]);
    }

    /** Hapus permanen komentar sendiri (spec 065). */
    public function hapusKomentar(Request $request, int $id, int $komentarId): JsonResponse
    {
        $this->komentarMilikSendiri($request, $id, $komentarId);

        DB::table('laporan_kendala_komentar')->where('id', $komentarId)->delete();

        return response()->json(['komentar' => $this->linimasa($id, $request->user()->id)]);
    }

    /** Laporan harus masuk cakupan (404 bila tidak) dan komentarnya jenis `komentar` milik akun login (403 bila bukan). */
    private function komentarMilikSendiri(Request $request, int $id, int $komentarId): void
    {
        $user = $request->user();
        abort_if(LaporanKendala::dalamCakupan($user)->where('l.id', $id)->doesntExist(), 404);

        $komentar = DB::table('laporan_kendala_komentar')->where('id', $komentarId)->where('laporan_kendala_id', $id)->first();
        abort_if($komentar === null, 404);
        abort_unless($komentar->jenis === 'komentar' && (int) $komentar->user_id === $user->id, 403, 'Komentar hanya bisa diubah atau dihapus oleh penulisnya.');
    }

    /** @return list<array<string, mixed>> komentar dan peristiwa laporan, urut waktu naik. */
    private function linimasa(int $id, int $idAkun): array
    {
        return DB::table('laporan_kendala_komentar')->where('laporan_kendala_id', $id)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($k) => [
                'id' => $k->id,
                'jenis' => $k->jenis,
                'penulis' => $k->penulis_nama,
                'milik_sendiri' => $k->jenis === 'komentar' && (int) $k->user_id === $idAkun,
                'isi' => $k->isi,
                'waktu' => JamLokal::format($k->created_at),
                'disunting' => $k->disunting_at !== null,
            ])->all();
    }

    /** Laporan baru: data pelapor (akun, karyawan, nama, depo) diambil dari akun login, bukan dari isian form. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(LaporanKendala::bolehMelapor($user), 403, 'Laporan kendala hanya dibuat oleh Sales Koordinator atau Supervisor.');
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
            'aktivitas_at' => now(), 'aktivitas_oleh' => $request->user()->id,
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

        DB::table('laporan_kendala')->where('id', $id)->where('status', LaporanKendala::STATUS_TERBUKA)->update($request->validate($this->aturan()) + ['updated_at' => now(), 'aktivitas_at' => now(), 'aktivitas_oleh' => $request->user()->id]);

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

        DB::transaction(function () use ($id, $request, $data) {
            $diubah = DB::table('laporan_kendala')->where('id', $id)->where('status', LaporanKendala::STATUS_TERBUKA)->update([
                'status' => LaporanKendala::STATUS_SELESAI,
                'catatan_selesai' => $data['catatan_selesai'],
                'selesai_at' => now(),
                'updated_at' => now(),
                'aktivitas_at' => now(), 'aktivitas_oleh' => $request->user()->id,
            ]);
            // Permintaan ganda (klik dua kali): yang kedua tidak mengubah baris, jadi tidak boleh menambah peristiwa.
            abort_if($diubah === 0, 422, 'Laporan ini sudah Selesai.');
            $this->catatPeristiwa($id, $request->user(), 'selesai', $data['catatan_selesai']);
        });

        return back()->with('sukses', 'Laporan kendala ditandai Selesai.');
    }

    /**
     * Buka kembali laporan Selesai (spec 065): hanya pelapor, hanya dari Selesai. Catatan penyelesaian lama tetap
     * di linimasa sebagai peristiwa Selesai; kolom di laporan dikosongkan agar hitungan Terbuka kembali benar.
     */
    public function bukaKembali(Request $request, int $id): RedirectResponse
    {
        $laporan = DB::table('laporan_kendala')->where('id', $id)->first();

        abort_if($laporan === null, 404);
        abort_unless((int) $laporan->user_id === $request->user()->id, 403, 'Laporan hanya bisa dibuka kembali oleh pelapornya.');
        abort_unless($laporan->status === LaporanKendala::STATUS_SELESAI, 422, 'Hanya laporan yang Selesai yang bisa dibuka kembali.');

        $data = $request->validate(['alasan' => ['nullable', 'string', 'max:2000']]);

        DB::transaction(function () use ($id, $request, $data) {
            $diubah = DB::table('laporan_kendala')->where('id', $id)->where('status', LaporanKendala::STATUS_SELESAI)->update([
                'status' => LaporanKendala::STATUS_TERBUKA,
                'catatan_selesai' => null,
                'selesai_at' => null,
                'updated_at' => now(),
                'aktivitas_at' => now(), 'aktivitas_oleh' => $request->user()->id,
            ]);
            abort_if($diubah === 0, 422, 'Laporan ini sudah dibuka kembali.');
            $this->catatPeristiwa($id, $request->user(), 'dibuka_kembali', filled($data['alasan'] ?? null) ? trim($data['alasan']) : null);
        });

        return back()->with('sukses', 'Laporan kendala dibuka kembali.');
    }

    /** Peristiwa otomatis di linimasa (jenis selesai / dibuka_kembali), ditulis atas nama akun yang bertindak. */
    private function catatPeristiwa(int $id, $user, string $jenis, ?string $isi): void
    {
        DB::table('laporan_kendala_komentar')->insert([
            'laporan_kendala_id' => $id,
            'user_id' => $user->id,
            'penulis_nama' => $user->name ?: $user->username,
            'jenis' => $jenis,
            'isi' => $isi,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
