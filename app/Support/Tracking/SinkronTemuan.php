<?php

namespace App\Support\Tracking;

use App\Models\User;
use App\Support\Akses\Akses;
use App\Support\Mobile\Pengaturan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Tombol "Sinkronisasi Data" di Beranda Koordinator (spec 062): satu-satunya sumber tanda motoris & outlet. Tanda adalah
// saran "Perlu dicek" untuk koordinator (dicek di lapangan / Street View), bukan vonis; koordinator menutupnya dengan
// hasil Terbukti / Wajar. Sinkronisasi hanya MENAMBAH: tanda yang sudah ada (status apa pun) tidak dibuat ulang, jadi
// tanda yang sudah dinilai Wajar tidak muncul lagi.
//
// - Diduga lokasi palsu (tanda_motoris, per motoris per tanggal): AnomaliGps tingkat "terdeteksi" — HP menandai mock
//   location. Pola lompatan tanpa penanda HP ("indikasi") tidak dijadikan tanda. Motoris-hari yang diperiksa setelah
//   harinya lewat dicatat "lengkap" di pemeriksaan_motoris dan dilewati; hari ini selalu diperiksa ulang.
// - Diduga outlet fiktif (tanda_outlet, per TITIK): outlet dari pendaftar yang sama (mk_outlet.dibuat_oleh) dengan
//   koordinat master identik. Titik masuk bila berisi >= min_outlet outlet, ada yang bertransaksi, dan ada yang ada di
//   rute tim (dikunjungi motoris tim). Transaksi diutamakan karena ikut menaikkan EC — target EC adalah dorongan
//   terbesar untuk mencatat transaksi di outlet yang tidak ada. Antrean Perlu dicek dibatasi antrean_titik titik per tim,
//   diisi dari skor tertinggi. Kunjungan berdekatan dan durasi kunjungan sengaja tidak dipakai (lihat spec 062).
class SinkronTemuan
{
    public const NAMA_SISTEM = 'Sistem';

    /**
     * @param  array<int, int>  $karyawanIds  motoris tim (sudah dibatasi cakupan oleh pemanggil)
     * @return array{diperiksa: int, dilewati: int, tanda_motoris: int, titik: int, tanda_outlet: int}
     */
    public static function jalankan(array $karyawanIds, string $dari, string $sampai, ?User $oleh = null): array
    {
        $motoris = self::lokasiPalsu($karyawanIds, $dari, $sampai, $oleh);
        $outlet = self::titikOutlet($karyawanIds, $oleh);

        return $motoris + $outlet;
    }

    /**
     * Tanda outlet milik tim: outlet itu, atau outlet lain di titik (kelompok) yang sama, ada di rute motoris tim.
     * Dipakai juga oleh beranda untuk antrean dan kartu Outlet Ditandai.
     *
     * @param  array<int, int>  $karyawanIds
     */
    public static function tandaOutletTim(array $karyawanIds, string $alias = 't'): Builder
    {
        $rute = DB::table('trx_kunjungan')->whereIn('karyawan_id', $karyawanIds ?: [0])->whereNotNull('outlet_id')->select('outlet_id');
        $kelompok = DB::table('tanda_outlet')->whereNotNull('kelompok')->whereIn('outlet_id', $rute)->select('kelompok');

        return DB::table("tanda_outlet as {$alias}")
            ->where(fn ($w) => $w->whereIn("{$alias}.outlet_id", $rute)->orWhereIn("{$alias}.kelompok", $kelompok));
    }

    /** @return array{diperiksa: int, dilewati: int, tanda_motoris: int} */
    private static function lokasiPalsu(array $karyawanIds, string $dari, string $sampai, ?User $oleh): array
    {
        $hasil = ['diperiksa' => 0, 'dilewati' => 0, 'tanda_motoris' => 0];
        $hariIni = Pengaturan::sekarang()->toDateString();
        $nama = DB::table('mk_karyawan')->whereIn('id', $karyawanIds ?: [0])->pluck('nama', 'id');
        $lengkap = DB::table('pemeriksaan_motoris')->whereIn('karyawan_id', $karyawanIds ?: [0])
            ->whereBetween('tanggal', [$dari, $sampai])->where('lengkap', true)
            ->get(['karyawan_id', 'tanggal'])->map(fn ($p) => $p->karyawan_id.'|'.substr((string) $p->tanggal, 0, 10))->flip();

        foreach (self::tanggal($dari, $sampai) as $tanggal) {
            $lama = DB::table('tracking_sales_lokasi')->whereIn('sales_id', $karyawanIds ?: [0])->whereDate('tanggal', $tanggal)
                ->get(['sales_id', 'lat', 'lng', 'waktu'])->groupBy('sales_id');

            foreach ($karyawanIds as $id) {
                if (isset($lengkap["{$id}|{$tanggal}"])) {
                    $hasil['dilewati']++;

                    continue;
                }

                $titik = ($lama[$id] ?? collect())->concat(TitikGps::untuk($id, $tanggal))->sortBy('waktu')->values();
                $kunjungan = KunjunganHarian::untuk($id, $tanggal);
                $gps = $titik->isEmpty() && $kunjungan->isEmpty() ? null
                    : AnomaliGps::analisis($titik, $kunjungan->where('lokasi_palsu', true)->count());

                if ($gps && $gps['tingkat'] === 'terdeteksi') {
                    $hasil['tanda_motoris'] += (int) self::tandaMotoris($id, (string) ($nama[$id] ?? ''), $tanggal, self::rincianGps($gps), $oleh);
                }

                $hasil['diperiksa']++;
                DB::table('pemeriksaan_motoris')->upsert(
                    [['karyawan_id' => $id, 'tanggal' => $tanggal, 'lengkap' => $tanggal < $hariIni, 'diperiksa_at' => now(), 'diperiksa_oleh' => $oleh?->id]],
                    ['karyawan_id', 'tanggal'],
                    ['lengkap', 'diperiksa_at', 'diperiksa_oleh'],
                );
            }
        }

        return $hasil;
    }

    /** Tambah tanda lokasi palsu bila motoris itu belum punya tanda (status apa pun) dengan kategori itu di tanggal itu. */
    private static function tandaMotoris(int $id, string $nama, string $tanggal, string $catatan, ?User $oleh): bool
    {
        if (DB::table('tanda_motoris')->where('karyawan_id', $id)->where('tanggal', $tanggal)->where('kategori', 'lokasi_palsu')->exists()) {
            return false;
        }

        $tandaId = DB::table('tanda_motoris')->insertGetId(self::kolomSistem($catatan) + [
            'karyawan_id' => $id, 'tanggal' => $tanggal, 'kategori' => 'lokasi_palsu',
        ]);
        Akses::catat($oleh, 'tanda_motoris', $tandaId, 'tambah', "Sinkronisasi sistem: {$nama} ({$tanggal}): ".config('marketama.tanda_motoris.lokasi_palsu'));

        return true;
    }

    /**
     * Isi antrean titik outlet Perlu dicek tim sampai antrean_titik, dari titik berskor tertinggi yang belum pernah ditandai.
     *
     * @param  array<int, int>  $karyawanIds
     * @return array{titik: int, tanda_outlet: int}
     */
    private static function titikOutlet(array $karyawanIds, ?User $oleh): array
    {
        $atur = config('marketama.tanda_outlet_sistem');
        $terbuka = self::tandaOutletTim($karyawanIds)->where('t.status', 'perlu_dicek')->distinct()->count('t.kelompok');
        $slot = max(0, (int) $atur['antrean_titik'] - $terbuka);
        $hasil = ['titik' => 0, 'tanda_outlet' => 0];

        if ($slot === 0) {
            return $hasil;
        }

        foreach (self::kandidatTitik($karyawanIds, $atur)->take($slot) as $titik) {
            foreach ($titik['outlet'] as $o) {
                $tandaId = DB::table('tanda_outlet')->insertGetId(self::kolomSistem($titik['catatan']) + [
                    'outlet_id' => $o->id, 'kelompok' => $titik['kelompok'], 'skor' => $titik['skor'], 'kategori' => 'outlet_fiktif',
                ]);
                Akses::catat($oleh, 'tanda_outlet', $tandaId, 'tambah', "Sinkronisasi sistem: outlet {$o->nama}: ".config('marketama.tanda_outlet.outlet_fiktif'));
                $hasil['tanda_outlet']++;
            }
            $hasil['titik']++;
        }

        return $hasil;
    }

    /**
     * Titik kandidat tim yang belum punya tanda sama sekali, urut skor tertinggi.
     *
     * @return Collection<int, array{kelompok: string, skor: int, catatan: string, outlet: Collection<int, object>}>
     */
    private static function kandidatTitik(array $karyawanIds, array $atur): Collection
    {
        $rute = DB::table('trx_kunjungan')->whereIn('karyawan_id', $karyawanIds ?: [0])->whereNotNull('outlet_id')->distinct()->pluck('outlet_id')->flip();
        // Pendaftar outlet di rute tim; titik dihitung dari SEMUA outlet pendaftar itu (outlet sekelompok bisa di luar rute).
        $pendaftar = DB::table('mk_outlet')->whereIn('id', $rute->keys()->all() ?: [0])->whereNotNull('dibuat_oleh')->distinct()->pluck('dibuat_oleh');

        $titik = DB::table('mk_outlet')->whereIn('dibuat_oleh', $pendaftar->all() ?: [''])
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->get(['id', 'nama', 'dibuat_oleh', 'latitude', 'longitude'])
            ->filter(fn ($o) => (float) $o->latitude != 0.0 && (float) $o->longitude != 0.0)
            ->groupBy(fn ($o) => self::kunciTitik($o))
            ->filter(fn ($g) => $g->count() >= $atur['min_outlet'] && $g->contains(fn ($o) => isset($rute[$o->id])));

        if ($titik->isEmpty()) {
            return collect();
        }

        $ids = $titik->flatten(1)->pluck('id')->all();
        $sudah = DB::table('tanda_outlet')->whereIn('outlet_id', $ids)->distinct()->pluck('outlet_id')->flip();
        $jual = DB::table('trx_penjualan')->whereIn('outlet_id', $ids)->whereNull('dibatalkan_at')->groupBy('outlet_id')
            ->selectRaw('outlet_id, COUNT(DISTINCT tanggal) AS hari, AVG(total) AS rata, MAX(jumlah_sku) AS sku')
            ->get()->keyBy('outlet_id');

        return $titik
            ->reject(fn ($g) => $g->contains(fn ($o) => isset($sudah[$o->id])))
            ->map(function ($g, $kunci) use ($jual, $atur) {
                $bertransaksi = $g->filter(fn ($o) => isset($jual[$o->id]));
                $kecil = $bertransaksi->filter(fn ($o) => (int) $jual[$o->id]->hari === 1
                    && ((float) $jual[$o->id]->rata < $atur['kecil_rupiah'] || (int) $jual[$o->id]->sku <= $atur['kecil_sku']));

                return [
                    'kelompok' => md5($kunci),
                    'skor' => 3 * $bertransaksi->count() + 2 * $kecil->count() + $g->count(),
                    'bertransaksi' => $bertransaksi->count(),
                    'catatan' => "{$g->count()} outlet didaftarkan {$g->first()->dibuat_oleh} di koordinat yang sama persis; "
                        ."{$bertransaksi->count()} bertransaksi".($kecil->count() ? ", {$kecil->count()} di antaranya hanya sekali dengan nilai kecil" : '').'.',
                    'outlet' => $g->sortBy('nama')->values(),
                ];
            })
            ->filter(fn ($t) => $t['bertransaksi'] > 0)
            ->sortByDesc('skor')->values();
    }

    /** Pendaftar + koordinat dibulatkan 6 desimal (~0,1 m): outlet dengan kunci sama dianggap satu titik. */
    private static function kunciTitik(object $o): string
    {
        return mb_strtolower(trim((string) $o->dibuat_oleh)).'|'.number_format((float) $o->latitude, 6, '.', '').'|'.number_format((float) $o->longitude, 6, '.', '');
    }

    /** @return array<string, mixed> */
    private static function kolomSistem(string $catatan): array
    {
        return [
            'catatan' => $catatan, 'status' => 'perlu_dicek', 'dibuat_oleh' => null, 'dibuat_nama' => self::NAMA_SISTEM,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** Kalimat ringkas hasil AnomaliGps (tingkat terdeteksi), isinya sama dengan banner/temuan di modal Riwayat. */
    private static function rincianGps(array $a): string
    {
        $desimal = fn (float $n) => number_format($n, 1, ',', '.');
        $dari = array_filter([
            $a['titik_mock'] ? "{$a['titik_mock']} titik GPS" : null,
            $a['kunjungan_mock'] ? "{$a['kunjungan_mock']} kunjungan" : null,
        ]);
        $bagian = ['Aplikasi HP menandai lokasi palsu (mock location) pada '.implode(' dan ', $dari).'.'];

        if ($a['lompatan']) {
            $j = $a['terjauh'];
            $bagian[] = "{$a['lompatan']} lompatan posisi mustahil ({$a['kembali_ke_asal']} kembali ke lokasi semula), terjauh {$desimal($j['km'])} km pada {$j['jam']}.";
        }

        if ($a['menyimpang']) {
            $bagian[] = "{$a['menyimpang']} titik GPS menyimpang, terjauh {$desimal($a['menyimpang_terjauh']['km'])} km pada {$a['menyimpang_terjauh']['jam']}.";
        }

        return implode(' ', $bagian);
    }

    /** @return array<int, string> tanggal Y-m-d dari $dari sampai $sampai (inklusif) */
    private static function tanggal(string $dari, string $sampai): array
    {
        $hasil = [];

        for ($t = CarbonImmutable::parse($dari); $t->toDateString() <= $sampai; $t = $t->addDay()) {
            $hasil[] = $t->toDateString();
        }

        return $hasil;
    }
}
