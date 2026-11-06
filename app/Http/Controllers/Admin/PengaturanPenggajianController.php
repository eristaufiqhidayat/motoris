<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Mobile\Pengaturan;
use App\Support\Penggajian\PeriodeGaji;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

// Penggajian › Pengaturan Penggajian: tanggal cut off gaji dan tanggal bayar insentif beserta riwayatnya.
// Hanya Super Admin (slug ada di Akses::MENU_ADMIN).
class PengaturanPenggajianController extends Controller
{
    public const MENU = 'pengaturan_penggajian';

    public function index(Request $request): Response
    {
        $hariIni = Pengaturan::sekarang()->toDateString();
        $zona = Pengaturan::zona();

        $berlaku = [];

        foreach (PeriodeGaji::KUNCI as $kunci => $label) {
            $berlaku[$kunci] = ['label' => $label, 'tanggal' => PeriodeGaji::nilai($kunci, $hariIni)];
        }

        $riwayat = DB::table('pengaturan_tanggal_penggajian as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.diubah_oleh')
            ->orderByDesc('p.berlaku_mulai')->orderByDesc('p.id')
            ->get(['p.kunci', 'p.tanggal', 'p.berlaku_mulai', 'p.updated_at', 'u.username as oleh'])
            ->map(fn ($r) => [
                'kunci' => $r->kunci,
                'nama' => PeriodeGaji::KUNCI[$r->kunci] ?? $r->kunci,
                'tanggal' => (int) $r->tanggal,
                'berlaku_mulai' => $r->berlaku_mulai,
                'status' => $r->berlaku_mulai > $hariIni ? 'Terjadwal' : 'Berlaku',
                'oleh' => $r->oleh,
                'diubah' => $r->oleh ? Carbon::parse($r->updated_at)->timezone($zona)->format('d M Y H:i') : null,
            ])->values()->all();

        return Inertia::render('Penggajian/Pengaturan', [
            'slug' => self::MENU,
            'judul' => config('marketama.pages.'.self::MENU.'.title') ?? 'Pengaturan Penggajian',
            'berlaku' => $berlaku,
            'riwayat' => $riwayat,
            'hariIni' => $hariIni,
            'kunci' => PeriodeGaji::KUNCI,
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kunci' => ['required', 'string'],
            'tanggal' => ['required', 'integer'],
            'berlaku_mulai' => ['required', 'date_format:Y-m-d'],
        ], [
            'tanggal.required' => 'Tanggal wajib diisi.',
            'tanggal.integer' => 'Tanggal harus angka bulat.',
            'berlaku_mulai.required' => 'Tanggal berlaku wajib diisi.',
            'berlaku_mulai.date_format' => 'Tanggal berlaku tidak valid.',
        ]);

        PeriodeGaji::ubahPengaturan($data['kunci'], (int) $data['tanggal'], $data['berlaku_mulai'], $request->user());

        return redirect('/'.self::MENU)->with('sukses', 'Pengaturan disimpan, berlaku mulai '.Carbon::parse($data['berlaku_mulai'])->locale('id')->translatedFormat('j F Y').'.');
    }
}
