<?php

namespace App\Support\Menu\Motoris;

use App\Models\User;
use App\Support\Akses\Cakupan;
use App\Support\Menu\JamLokal;
use App\Support\Menu\SumberBerstatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rekap Motoris Ditandai: semua tanda motoris (tanda_motoris, dibuat sistem lewat Sinkronisasi Data di Beranda Koordinator,
 * spec 062), termasuk yang sudah diperiksa (Terbukti / Wajar) sebagai jejak. Dibatasi cakupan role (alias k = motoris);
 * bisa disaring menurut status (config marketama.status_tanda).
 */
class SumberTandaMotoris implements SumberBerstatus
{
    public function tabelUtama(): string
    {
        return 'tanda_motoris';
    }

    public function query(?User $user): Builder
    {
        $q = DB::table('tanda_motoris as tm')
            ->join('mk_karyawan as k', 'k.id', '=', 'tm.karyawan_id')
            ->leftJoin('mk_depo as d', 'd.id', '=', 'k.depo_id')
            ->leftJoin('mk_karyawan as ko', 'ko.id', '=', 'k.koordinator_id')
            ->select([
                'tm.id', 'tm.karyawan_id', 'tm.tanggal', 'k.nama as motoris', 'd.nama as depo', 'ko.nama as koordinator',
                'tm.kategori', 'tm.catatan', 'tm.dibuat_nama as ditandai_oleh', 'tm.created_at', 'tm.status', 'tm.hasil_catatan', 'tm.dicek_nama',
            ])
            ->orderByDesc('tm.tanggal')->orderByDesc('tm.id');

        return Cakupan::untuk($user)->batasi($q);
    }

    public function ubahBaris(object $baris): object
    {
        $baris->tanggal = substr((string) $baris->tanggal, 0, 10);
        $baris->kategori = config("marketama.tanda_motoris.{$baris->kategori}", $baris->kategori);
        $baris->ditandai_pada = JamLokal::format($baris->created_at, 'Y-m-d H:i');
        $baris->status = config("marketama.status_tanda.{$baris->status}", $baris->status);
        $baris->hasil_cek = $baris->hasil_catatan ? "{$baris->hasil_catatan} ({$baris->dicek_nama})" : null;
        unset($baris->created_at, $baris->hasil_catatan, $baris->dicek_nama);

        return $baris;
    }

    public function kolomCari(): array
    {
        return ['k.nama', 'd.nama', 'ko.nama', 'tm.catatan', 'tm.hasil_catatan'];
    }

    public function pilihanStatus(): array
    {
        return config('marketama.status_tanda');
    }

    public function batasiStatus(Builder $query, string $status): void
    {
        if (array_key_exists($status, config('marketama.status_tanda'))) {
            $query->where('tm.status', $status);
        }
    }
}
