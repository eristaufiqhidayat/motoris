<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Akses\Akses;
use App\Support\Menu\JamLokal;
use App\Support\Tracking\HasilPeriksa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Tanda outlet (diduga outlet fiktif) dari drawer Riwayat Lacak Motoris atau menu All Outlet. Melekat pada OUTLET (bukan
// per kunjungan/motoris/tanggal), jadi tidak dibatasi cakupan role seperti tanda motoris. Sejak spec 062 tanda hanya
// dibuat sistem (Sinkronisasi Data di Beranda Koordinator) per TITIK: outlet-outlet berkoordinat identik dari pendaftar
// yang sama berbagi `kelompok`, dan memeriksa satu tanda menutup semua tanda Perlu dicek di kelompok itu (satu kunjungan
// lapangan / Street View menilai seluruh titik). Hak mengikuti menu outlet_all: Lihat = melihat, Tambah = memeriksa.
class TandaOutletController extends Controller
{
    private const MENU = 'outlet_all';

    /** Tanda aktif (Perlu dicek / Terbukti) satu outlet. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'integer'],
        ]);

        $outlet = $this->outlet((int) $data['outlet_id']);

        $tanda = DB::table('tanda_outlet')
            ->where('outlet_id', $outlet->id)->whereNull('dicabut_at')
            ->orderBy('id')->get()
            ->map(fn ($t) => $this->bentuk($t, $request->user()));

        return response()->json([
            // Siapa yang mendaftarkan outlet dan kapan (data asal Marketama), sebagai konteks pemeriksaan.
            'outlet' => [
                'nama' => $outlet->nama,
                'dibuat_oleh' => $outlet->dibuat_oleh,
                'dibuat_pada' => $outlet->dibuat_pada,
            ],
            'tanda' => $tanda->values(),
        ]);
    }

    /**
     * Hasil cek koordinator atas tanda Perlu dicek: Terbukti atau Wajar (+ catatan wajib), diterapkan ke semua tanda
     * Perlu dicek di kelompok (titik) yang sama. Tanda tanpa kelompok hanya dirinya sendiri.
     */
    public function periksa(Request $request, int $tanda): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['terbukti', 'wajar'])],
            'catatan' => ['required', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $baris = DB::table('tanda_outlet')->where('id', $tanda)->where('status', 'perlu_dicek')->first();

        abort_unless($baris, 404);
        abort_unless(Akses::bisa($user, self::MENU, 'tambah'), 403, 'Anda tidak boleh memeriksa tanda ini.');

        $sekelompok = DB::table('tanda_outlet')->where('status', 'perlu_dicek')
            ->when($baris->kelompok, fn ($q) => $q->where('kelompok', $baris->kelompok), fn ($q) => $q->where('id', $tanda));
        $ids = (clone $sekelompok)->pluck('id');
        $berubah = $sekelompok->update(HasilPeriksa::kolom($data['status'], $data['catatan'], $user));

        // Dua pemeriksa bersamaan: hanya yang pertama berhasil.
        abort_unless($berubah, 404);

        $label = config("marketama.status_tanda.{$data['status']}");
        $nama = DB::table('tanda_outlet as t')->join('mk_outlet as o', 'o.id', '=', 't.outlet_id')->whereIn('t.id', $ids)->pluck('o.nama', 't.id');
        foreach ($ids as $id) {
            Akses::catat($user, 'tanda_outlet', (int) $id, 'ubah', 'Periksa tanda outlet '.($nama[$id] ?? $id).": {$label}");
        }
        HomeController::lupakanTanda($request);

        return response()->json(['diperiksa' => $berubah] + $this->bentuk(DB::table('tanda_outlet')->find($tanda), $user));
    }

    /** Outlet master (mk_outlet); selain itu 404. */
    private function outlet(int $id): object
    {
        $outlet = DB::table('mk_outlet')->where('id', $id)->first(['id', 'nama', 'dibuat_oleh', 'dibuat_pada']);

        abort_unless($outlet, 404);

        return $outlet;
    }

    /** @return array<string, mixed> */
    private function bentuk(object $tanda, ?User $user): array
    {
        return [
            'id' => (int) $tanda->id,
            'kategori' => $tanda->kategori,
            'kategori_label' => config("marketama.tanda_outlet.{$tanda->kategori}", $tanda->kategori),
            'catatan' => $tanda->catatan,
            'oleh' => $tanda->dibuat_nama,
            'waktu' => JamLokal::format($tanda->created_at, 'Y-m-d H:i'),
            // Jumlah outlet lain di titik yang sama (tanda sekelompok), supaya jelas pemeriksaan berlaku untuk semuanya.
            'sekelompok' => $tanda->kelompok ? DB::table('tanda_outlet')->where('kelompok', $tanda->kelompok)->where('id', '<>', $tanda->id)->count() : 0,
        ] + HasilPeriksa::bentuk($tanda, Akses::bisa($user, self::MENU, 'tambah'));
    }
}
