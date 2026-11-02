<?php

namespace App\Support\Menu;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Menu Master Warehouse / Depo dibaca dari mk_depo (tabel master Marketama di Enesis, dipakai juga oleh
 * aplikasi HP lewat App\Support\Mobile\Pengaturan::depo()), bukan lagi dari "master_depo" (salinan datar
 * hasil impor CSV lama). Depo baru buatan Enesis (App\Http\Controllers\Admin\MasterDepoController) ikut
 * tampil di sini. Kolom hasil query dibuat sama persis dengan config lama supaya Vue (DetailDepo.vue,
 * DetailDepoPeta.vue, DetailDepoEdit.vue) tidak perlu diubah.
 *
 * "Jumlah Sales Aktif" sengaja menghitung SEMUA sales di depo itu (bukan hanya yang aktif=true) — ikut
 * definisi Marketama yang sudah dipakai (lihat claude/menu-master-depo.md di project Enesis).
 *
 * Sort & filter kolom belum didukung untuk menu ber-sumber (sama seperti Master Area / SumberMasterArea).
 */
class SumberMasterDepo implements SumberTabel
{
    public function tabelUtama(): string
    {
        return 'mk_depo';
    }

    public function query(?User $user): Builder
    {
        return DB::table('mk_depo as d')
            ->leftJoin('master_project as p', 'p.id', '=', 'd.project_id')
            ->leftJoin('city_group as cg', 'cg.id', '=', 'd.city_group_id')
            ->select([
                'd.id',
                'd.nama as nama',
                'd.kode as kode',
                DB::raw("COALESCE(p.nama_project, '-') as projek"),
                DB::raw("COALESCE(cg.nama_city_group, '-') as kota_besar"),
                'd.minimum_sku as minimum_sku',
                DB::raw('CASE WHEN d.multi_polygon_sales THEN \'Ya\' ELSE \'Tidak\' END as multi_polygon_sales'),
                'd.provinsi as provinsi',
                'd.kota_kabupaten as kota_kab',
                'd.kecamatan as kecamatan',
                'd.kelurahan_desa as kelurahan_desa',
                'd.alamat as alamat',
                'd.longitude as longitude',
                'd.latitude as latitude',
                DB::raw("(SELECT COUNT(*) FROM mk_karyawan k WHERE k.depo_id = d.id AND k.kategori = 'koordinator') as total_koordinator"),
                DB::raw("(SELECT COUNT(*) FROM mk_karyawan k WHERE k.depo_id = d.id AND k.kategori = 'sales') as jumlah_sales_aktif"),
                DB::raw('(SELECT COUNT(*) FROM mk_wilayah_depo w WHERE w.depo_id = d.id) as jumlah_wilayah_depo'),
                'd.kuota_koordinator as kuota_koordinator',
                'd.kuota_sales_motoris as kuota_sales_motoris',
                'd.target_call as target_call',
                'd.target_ec as target_ec',
                'd.durasi_tracking_gps as durasi_tracking_gps',
                'd.insentif_per_poin as insentif_per_poin',
                'd.outlet_1_sku as outlet_1_sku',
                'd.minimal_value_transaksi as minimal_value_transaksi',
                'd.diubah_oleh as diupdate_oleh',
                'd.diubah_pada as diupdate_pada',
                DB::raw('CASE WHEN d.aktif THEN \'Aktif\' ELSE \'Tidak Aktif\' END as status'),
            ])
            ->orderBy('d.nama');
    }

    public function kolomCari(): array
    {
        return ['d.kode', 'd.nama', 'd.provinsi', 'd.kota_kabupaten', 'd.kecamatan', 'd.kelurahan_desa', 'd.alamat'];
    }

    public function ubahBaris(object $baris): object
    {
        $baris->diupdate_pada = JamLokal::format($baris->diupdate_pada);

        return $baris;
    }
}
