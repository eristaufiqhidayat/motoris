<?php

namespace App\Support\Motoris;

use App\Support\Marketama\HalamanParser;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Perkiraan insentif sales dari aturan Master Data (dipakai aplikasi HP "me/performance", Lap. Insentif Sales, dan
// Penggajian Insentif):
// - insentif EC: rentang total EC sebulan di Rumus Insentif Sales;
// - nilai poin: poin × rupiah per poin depo di Konversi Poin Per Depo.
class Insentif
{
    /**
     * Total EC dan poin per sales dalam rentang tanggal, dari penjualan yang tidak dibatalkan. Satu-satunya sumber
     * hitungan ini supaya Lap. Insentif Sales dan Penggajian Insentif selalu menghasilkan angka yang sama.
     */
    public static function penjualanPerSales(string $dari, string $sampai): Builder
    {
        return DB::table('trx_penjualan')->whereBetween('tanggal', [$dari, $sampai])->whereNull('dibatalkan_at')->groupBy('karyawan_id')
            ->selectRaw('karyawan_id, SUM(CASE WHEN is_effective_call THEN 1 ELSE 0 END) AS ec, COALESCE(SUM(poin), 0) AS poin');
    }

    /**
     * Rentang Rumus Insentif Sales yang memuat total EC itu (null bila tabel belum ada atau tidak ada rentang yang cocok).
     *
     * @return array{bawah: int, atas: int|null, insentif: int}|null
     */
    public static function tingkatEc(int $ec): ?array
    {
        if (! Schema::hasTable('rumus_insentif_sales')) {
            return null;
        }

        foreach (DB::table('rumus_insentif_sales')->get() as $r) {
            $bawah = HalamanParser::angka((string) $r->tot_ec_lower_bound) ?? 0;
            $atas = HalamanParser::angka((string) $r->tot_ec_upper_bound);

            if ($ec >= $bawah && $ec <= ($atas ?? PHP_INT_MAX)) {
                return ['bawah' => (int) $bawah, 'atas' => $atas === null ? null : (int) $atas, 'insentif' => (int) (HalamanParser::angka((string) $r->total_insentif) ?? 0)];
            }
        }

        return null;
    }

    public static function ec(int $ec): ?int
    {
        if (! Schema::hasTable('rumus_insentif_sales')) {
            return null;
        }

        return self::tingkatEc($ec)['insentif'] ?? 0;
    }

    /** Rupiah per poin untuk depo itu dari Konversi Poin Per Depo (null bila depo belum ada di tabel). */
    public static function rupiahPerPoin(?string $namaDepo): ?int
    {
        if (! $namaDepo || ! Schema::hasTable('sales_point_conversion_per_warehouse')) {
            return null;
        }

        $rp = DB::table('sales_point_conversion_per_warehouse')->get()
            ->first(fn ($r) => HalamanParser::kunciNama($r->depo) === HalamanParser::kunciNama($namaDepo))?->poin_insentif_rp;

        return $rp === null ? null : (int) $rp;
    }

    public static function nilaiPoin(?string $namaDepo, int $poin): ?int
    {
        $rp = self::rupiahPerPoin($namaDepo);

        return $rp === null ? null : $rp * $poin;
    }
}
