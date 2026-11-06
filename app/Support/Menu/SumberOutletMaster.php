<?php

namespace App\Support\Menu;

use App\Models\User;
use App\Support\Akses\Cakupan;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Master outlet (mk_outlet) — tabel yang sama dengan yang dibaca API mobile sales motoris.
 * Nama area dan depo diambil dari mk_area / mk_depo lewat area_id. Akun dengan cakupan selain
 * "semua" (mis. koordinator) hanya melihat outlet di area depo timnya (sama dengan menu Outlet lain).
 */
class SumberOutletMaster implements SumberTabel
{
    public function tabelUtama(): string
    {
        return 'mk_outlet';
    }

    public function query(?User $user): Builder
    {
        // Lencana "Ditandai" (tanda outlet, spec 053): jumlah tanda aktif outlet itu, lepas dari motoris/tanggal kunjungan.
        $ditandai = DB::table('tanda_outlet as t')->whereColumn('t.outlet_id', 'o.id')->whereNull('t.dicabut_at')->selectRaw('COUNT(*)');
        $depo = Cakupan::untuk($user)->depoIds();

        return DB::table('mk_outlet as o')
            ->leftJoin('mk_area as a', 'a.id', '=', 'o.area_id')
            ->leftJoin('mk_depo as d', 'd.id', '=', 'a.depo_id')
            ->when($depo !== null, fn ($w) => $w->whereIn('a.depo_id', $depo ?: [0]))
            ->select([
                'o.id', 'o.kode', 'o.nama', 'o.alamat',
                // Area dari master; 8.751 outlet memang tanpa area (tampil "-" seperti di aplikasi lama).
                DB::raw('COALESCE(a.nama, o.area) AS area'),
                'd.nama as depo',
                'o.provinsi', 'o.kota_kabupaten', 'o.kecamatan', 'o.kelurahan',
                'o.latitude', 'o.longitude', 'o.verifikasi', 'o.status_fiktif',
                'o.dibuat_oleh', 'o.dibuat_pada',
            ])
            ->selectSub($ditandai, 'ditandai')
            ->orderBy('o.id');
    }

    public function ubahBaris(object $baris): object
    {
        $baris->ditandai = (int) $baris->ditandai;

        return $baris;
    }

    public function kolomCari(): array
    {
        return ['o.id', 'o.kode', 'o.nama', 'o.alamat', 'o.area', 'a.nama', 'd.nama', 'o.kota_kabupaten', 'o.kecamatan', 'o.kelurahan'];
    }
}
