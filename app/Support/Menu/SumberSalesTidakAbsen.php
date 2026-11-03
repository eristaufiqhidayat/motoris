<?php

namespace App\Support\Menu;

use App\Models\User;
use App\Support\Akses\Akses;
use App\Support\Mobile\Pengaturan;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Monitoring Harian Sales › Sales Tidak Absen Pagi: sales motoris aktif dalam cakupan role akun yang
 * tidak punya baris trx_absensi (absen masuk) pada tanggal yang dipilih.
 */
class SumberSalesTidakAbsen implements SumberBertanggal
{
    public function tabelUtama(): string
    {
        return 'trx_absensi';
    }

    public function query(?User $user): Builder
    {
        return $this->queryTanggal($user, Pengaturan::sekarang()->toDateString(), ['depo' => null, 'koordinator' => null]);
    }

    public function queryTanggal(?User $user, string $tanggal, array $filter): Builder
    {
        return $this->salesAktif($user, $filter)
            ->leftJoin('mk_depo as d', 'd.id', '=', 'k.depo_id')
            ->leftJoin('mk_karyawan as ko', 'ko.id', '=', 'k.koordinator_id')
            ->whereNotExists($this->absenTanggal($tanggal))
            ->orderBy('d.nama')->orderBy('k.nama')
            ->select(['k.id', 'd.nama as depo', 'ko.nama as koordinator', 'k.nama as nama_sales']);
    }

    public function kpi(?User $user, string $tanggal, array $filter): array
    {
        return [
            ['label' => 'Sales aktif', 'value' => $this->salesAktif($user, $filter)->count()],
            ['label' => 'Sudah absen', 'value' => $this->salesAktif($user, $filter)->whereExists($this->absenTanggal($tanggal))->count()],
            ['label' => 'Belum absen', 'value' => $this->queryTanggal($user, $tanggal, $filter)->count()],
        ];
    }

    public function keterangan(): ?string
    {
        return 'Menampilkan sales motoris aktif yang belum absen masuk (trx_absensi) pada tanggal yang dipilih.';
    }

    public function pilihanFilter(?User $user): array
    {
        $depo = Akses::batasiKaryawan(DB::table('mk_karyawan as k')->where('k.kategori', 'sales')->whereNotNull('k.depo_id'), $user)
            ->distinct()->pluck('k.depo_id');

        return [
            'depo' => DB::table('mk_depo')->whereIn('id', $depo->all() ?: [0])->orderBy('nama')->pluck('nama', 'id')->all(),
            'koordinator' => Akses::batasiKaryawan(DB::table('mk_karyawan as k')->where('k.kategori', 'koordinator')->where('k.aktif', true), $user)
                ->orderBy('k.nama')->pluck('k.nama', 'k.id')->all(),
        ];
    }

    public function ubahBaris(object $baris): object
    {
        return $baris;
    }

    public function kolomCari(): array
    {
        return ['k.nama', 'd.nama', 'ko.nama'];
    }

    // ------------------------------------------------------------------

    /** Sales motoris aktif dalam cakupan akun, disaring depo / koordinator (alias k). */
    private function salesAktif(?User $user, array $filter): Builder
    {
        $q = DB::table('mk_karyawan as k')->where('k.kategori', 'sales')->where('k.aktif', true)
            ->when($filter['depo'] ?? null, fn ($w, $depo) => $w->where('k.depo_id', $depo))
            ->when($filter['koordinator'] ?? null, fn ($w, $koor) => $w->where('k.koordinator_id', $koor));

        return Akses::batasiKaryawan($q, $user);
    }

    /** Subquery: ada baris trx_absensi sales k pada tanggal itu (absen masuk tercatat). */
    private function absenTanggal(string $tanggal): \Closure
    {
        return fn ($w) => $w->select(DB::raw(1))->from('trx_absensi as t')->whereColumn('t.karyawan_id', 'k.id')
            ->where('t.tanggal', $tanggal);
    }
}
