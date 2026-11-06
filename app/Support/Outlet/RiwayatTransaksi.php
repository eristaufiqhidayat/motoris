<?php

namespace App\Support\Outlet;

use App\Models\User;
use App\Support\Akses\Cakupan;
use App\Support\Menu\JamLokal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

// Riwayat transaksi (trx_penjualan + trx_penjualan_item) satu outlet untuk bukti pemeriksaan titik outlet diduga fiktif
// di Beranda Koordinator: alasan tanda berbunyi "N bertransaksi", jadi koordinator perlu melihat transaksinya sendiri.
// Hanya transaksi tidak dibatalkan (sama dengan penghitungan SinkronTemuan) dari motoris dalam cakupan role akun.
class RiwayatTransaksi
{
    /** Batas transaksi terbaru yang dikirim per outlet; sisanya cukup terlihat dari jumlah totalnya. */
    public const MAKS_TRANSAKSI = 30;

    /**
     * Ringkasan per outlet: jumlah transaksi, hari bertransaksi, dan total rupiah. Outlet tanpa transaksi tidak ada di hasil.
     *
     * @param  array<int, int>  $outletIds
     * @return array<int, array{transaksi: int, hari: int, total: int}>
     */
    public static function ringkasan(array $outletIds, ?User $user): array
    {
        if ($outletIds === []) {
            return [];
        }

        return self::dasar($user)->whereIn('p.outlet_id', $outletIds)->groupBy('p.outlet_id')
            ->selectRaw('p.outlet_id, COUNT(*) AS transaksi, COUNT(DISTINCT p.tanggal) AS hari, SUM(p.total) AS total')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->outlet_id => ['transaksi' => (int) $r->transaksi, 'hari' => (int) $r->hari, 'total' => (int) $r->total]])
            ->all();
    }

    /**
     * Transaksi terbaru satu outlet beserta item produknya.
     *
     * @return array{jumlah: int, transaksi: array<int, array<string, mixed>>}
     */
    public static function daftar(int $outletId, ?User $user): array
    {
        $dasar = self::dasar($user)->where('p.outlet_id', $outletId);
        $jumlah = (clone $dasar)->count();

        $penjualan = $dasar->orderByDesc('p.tanggal')->orderByDesc('p.recorded_at')->orderByDesc('p.id')->limit(self::MAKS_TRANSAKSI)
            ->get(['p.id', 'p.kode', 'p.tanggal', 'p.recorded_at', 'k.nama as motoris', 'p.jumlah_sku', 'p.total_qty', 'p.total_diskon', 'p.total', 'p.is_effective_call']);

        $item = DB::table('trx_penjualan_item as i')->leftJoin('mk_produk as pr', 'pr.id', '=', 'i.produk_id')
            ->whereIn('i.penjualan_id', $penjualan->pluck('id')->all() ?: [0])->orderBy('i.id')
            ->get(['i.penjualan_id', 'pr.nama as produk', 'pr.kode as sku', 'i.qty', 'i.harga', 'i.diskon', 'i.total'])
            ->groupBy('penjualan_id');

        return [
            'jumlah' => $jumlah,
            'transaksi' => $penjualan->map(fn ($p) => [
                'id' => (int) $p->id,
                'kode' => $p->kode,
                'tanggal' => substr((string) $p->tanggal, 0, 10),
                'jam' => JamLokal::format($p->recorded_at, 'H:i'),
                'motoris' => $p->motoris,
                'jumlah_sku' => (int) $p->jumlah_sku,
                'total_qty' => (float) $p->total_qty,
                'total_diskon' => (int) $p->total_diskon,
                'total' => (int) $p->total,
                'effective_call' => (bool) $p->is_effective_call,
                'item' => ($item[$p->id] ?? collect())->map(fn ($i) => [
                    'produk' => $i->produk ?? 'Produk #?',
                    'sku' => $i->sku,
                    'qty' => (float) $i->qty,
                    'harga' => (int) $i->harga,
                    'diskon' => (int) $i->diskon,
                    'total' => (int) $i->total,
                ])->values(),
            ])->values()->all(),
        ];
    }

    private static function dasar(?User $user): Builder
    {
        return Cakupan::untuk($user)->batasi(DB::table('trx_penjualan as p')->join('mk_karyawan as k', 'k.id', '=', 'p.karyawan_id')->whereNull('p.dibatalkan_at'));
    }
}
