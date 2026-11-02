<?php

namespace App\Http\Controllers;

use App\Support\Mobile\Pengaturan;
use App\Support\Motoris\DetailMotoris;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

// Modal Detail Motoris di Lacak Motoris (spec 060): profil dan historis transaksi motoris pada satu periode.
// Hak mengikuti menu tracking_sales (Lihat); hanya motoris dalam cakupan role akun.
class DetailMotorisController extends Controller
{
    public const MAKS_HARI = 366;

    public function show(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $hariIni = Pengaturan::sekarang();
        $dari = $data['dari'] ?? $hariIni->startOfMonth()->toDateString();
        $sampai = $data['sampai'] ?? $hariIni->toDateString();

        if ($sampai < $dari) {
            throw ValidationException::withMessages(['sampai' => 'Tanggal sampai tidak boleh sebelum tanggal dari.']);
        }

        if (CarbonImmutable::parse($dari)->diffInDays(CarbonImmutable::parse($sampai)) >= self::MAKS_HARI) {
            throw ValidationException::withMessages(['sampai' => 'Periode paling panjang '.self::MAKS_HARI.' hari.']);
        }

        $detail = DetailMotoris::untuk($request->user(), $id, $dari, $sampai);
        abort_unless($detail, 404);

        return response()->json($detail->toArray($request->user()));
    }

    /** Transaksi motoris pada satu tanggal, untuk detail baris tabel Harian. */
    public function transaksi(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['tanggal' => ['required', 'date_format:Y-m-d']]);

        $detail = DetailMotoris::untuk($request->user(), $id, $data['tanggal'], $data['tanggal']);
        abort_unless($detail, 404);

        return response()->json(['tanggal' => $data['tanggal'], 'transaksi' => $detail->transaksiTanggal($data['tanggal'])]);
    }
}
