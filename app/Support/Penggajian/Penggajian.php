<?php

namespace App\Support\Penggajian;

use App\Models\User;
use App\Support\Akses\Akses;
use App\Support\Motoris\Insentif;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Siklus penggajian sales dan koordinator (gaji; insentif hanya sales): buat draf, isi hari izin (gaji), kelola komponen manual, hitung ulang, finalkan, batalkan.
// Hanya draf yang bisa diubah; penggajian final dan batal terkunci. Larangan pembayaran ganda dijaga di sini,
// di dalam transaksi, per kategori. Rincian final berupa baris komponen yang tersimpan, jadi perubahan data
// karyawan atau rumus sesudahnya tidak mengubah angkanya.
class Penggajian
{
    /** Awalan nomor penggajian per jenis dan kategori. */
    private const AWALAN_NOMOR = ['sales' => ['gaji' => 'GJS', 'insentif' => 'INS'], 'koordinator' => ['gaji' => 'GJK', 'insentif' => 'INK']];

    /** Rumus Insentif Koordinator ditulis untuk tim 10 sales; aturan untuk jumlah lain belum diputuskan (issue #100, pertanyaan 21). */
    public const INSENTIF_KOORDINATOR_TERTUNDA = 'Insentif koordinator belum tersedia: Rumus Insentif Koordinator ditulis untuk tim 10 motoris dan aturan untuk tim dengan jumlah motoris lain belum diputuskan (issue #100, pertanyaan 21).';

    /**
     * Buat draf penggajian ($jenis 'sales' atau 'koordinator', $kategori 'gaji' atau 'insentif'): satu detail per
     * karyawan aktif jenis itu di depo (untuk sales, opsional hanya milik satu koordinator), lengkap dengan komponen
     * otomatis. Periode ditentukan dari $tanggal (tanggal mana pun di dalam periode): periode cut off untuk gaji,
     * bulan kalender untuk insentif. Insentif koordinator belum tersedia (lihat pesan di bawah).
     *
     * @return int id penggajian
     *
     * @throws ValidationException
     */
    public static function buat(string $jenis, string $kategori, int $depoId, string $tanggal, User $aktor, ?int $koordinatorId = null): int
    {
        $insentif = $kategori === 'insentif';

        if ($insentif && $jenis === 'koordinator') {
            throw ValidationException::withMessages(['kategori' => self::INSENTIF_KOORDINATOR_TERTUNDA]);
        }

        $periode = $insentif ? PeriodeGaji::insentif($tanggal) : PeriodeGaji::gaji($tanggal);

        $motoris = DB::table('mk_karyawan')->where('kategori', $jenis)->where('depo_id', $depoId)
            ->where('aktif', true) // status kosong (impor Marketama tanpa status) tidak ikut digaji
            ->when($koordinatorId, fn ($q) => $q->where('koordinator_id', $koordinatorId))
            ->orderBy('nama')->get();

        if ($motoris->isEmpty()) {
            throw ValidationException::withMessages(['depo_id' => 'Tidak ada '.($jenis === 'sales' ? 'motoris' : 'koordinator').' aktif di depo ini.']);
        }

        $ids = $motoris->pluck('id')->map(fn ($id) => (int) $id)->all();
        $masukan = $insentif ? self::masukanInsentif($ids, $periode['mulai'], $periode['selesai']) : self::masukanGaji($ids, $periode['mulai'], $periode['selesai']);

        $id = DB::transaction(function () use ($jenis, $kategori, $depoId, $koordinatorId, $aktor, $periode, $motoris, $ids, $masukan) {
            // Kunci baris karyawan supaya dua permintaan serentak untuk orang yang sama berjalan bergantian (bukan
            // bersamaan lolos pemeriksaan); tidak berpengaruh di SQLite.
            DB::table('mk_karyawan')->whereIn('id', $ids)->lockForUpdate()->pluck('id');

            self::pastikanTidakTumpangTindih($kategori, $ids, $periode['mulai'], $periode['selesai']);

            $id = DB::table('penggajian')->insertGetId([
                'jenis' => $jenis, 'kategori' => $kategori,
                'no_penggajian' => self::nomor(self::AWALAN_NOMOR[$jenis][$kategori], $depoId, $periode['selesai']),
                'periode_mulai' => $periode['mulai'], 'periode_selesai' => $periode['selesai'], 'tanggal_bayar' => $periode['tanggal_bayar'],
                'depo_id' => $depoId, 'koordinator_id' => $koordinatorId, 'status' => 'draf',
                'dibuat_oleh' => $aktor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($motoris as $k) {
                $detailId = DB::table('penggajian_detail')->insertGetId([
                    'penggajian_id' => $id, 'karyawan_id' => $k->id, 'nama' => $k->nama, 'created_at' => now(), 'updated_at' => now(),
                ]);

                self::segarkanDetail($jenis, $kategori, $detailId, $k, $masukan, 0);
            }

            return $id;
        });

        Akses::catat($aktor, 'penggajian', $id, 'buat', ($insentif ? 'Insentif' : 'Gaji')." {$jenis} depo {$depoId} periode {$periode['mulai']} s.d. {$periode['selesai']}");

        return $id;
    }

    /**
     * Hitung ulang draf dari data terbaru (gaji karyawan, absensi, penjualan, hari libur, rumus): komponen otomatis
     * diganti, sedangkan komponen manual dan hari izin dipertahankan. Orang di draf tidak bertambah atau berkurang.
     *
     * @throws ValidationException
     */
    public static function hitungUlang(int $id): void
    {
        DB::transaction(function () use ($id) {
            $p = self::draf($id);

            $detail = DB::table('penggajian_detail')->where('penggajian_id', $id)->get();
            $karyawan = DB::table('mk_karyawan')->whereIn('id', $detail->pluck('karyawan_id'))->get()->keyBy('id');
            $ids = $detail->pluck('karyawan_id')->map(fn ($k) => (int) $k)->all();
            $masukan = $p->kategori === 'insentif' ? self::masukanInsentif($ids, $p->periode_mulai, $p->periode_selesai) : self::masukanGaji($ids, $p->periode_mulai, $p->periode_selesai);

            foreach ($detail as $d) {
                // Karyawan yang sudah tidak ada di master dibiarkan apa adanya.
                if ($k = $karyawan[$d->karyawan_id] ?? null) {
                    self::segarkanDetail($p->jenis, $p->kategori, $d->id, $k, $masukan, (int) $d->hari_izin);
                }
            }
        });
    }

    /**
     * Isi hari izin satu orang di draf; tunjangan prorata ikut dihitung ulang dari data terbaru orang itu.
     *
     * @throws ValidationException
     */
    public static function isiHariIzin(int $id, int $detailId, int $hari): void
    {
        DB::transaction(function () use ($id, $detailId, $hari) {
            $p = self::draf($id);

            if ($p->kategori !== 'gaji') {
                throw ValidationException::withMessages(['penggajian' => 'Hari izin hanya untuk penggajian gaji.']);
            }

            $d = DB::table('penggajian_detail')->where('id', $detailId)->where('penggajian_id', $id)->first();
            $k = $d ? DB::table('mk_karyawan')->where('id', $d->karyawan_id)->first() : null;

            if (! $d || ! $k) {
                throw ValidationException::withMessages(['penggajian' => 'Data karyawan tidak ditemukan.']);
            }

            // Draf mengikuti data terbaru: selain izin, kehadiran dan komponen otomatis orang ini (termasuk potongan
            // terlambat, yang butuh rincian keterlambatan per bulan) dihitung ulang dari absensi dan data karyawan.
            self::segarkanDetail($p->jenis, 'gaji', $d->id, $k, self::masukanGaji([(int) $k->id], $p->periode_mulai, $p->periode_selesai), $hari);
        });
    }

    /**
     * Tambah komponen manual (pendapatan atau potongan) di draf. Potongan memakai nama dari Parameter Pemotong Gaji.
     *
     * @param  array{tipe: string, nama?: ?string, nominal: float|int|string, keterangan?: ?string, parameter_pemotong_id?: ?int}  $data
     *
     * @throws ValidationException
     */
    public static function tambahKomponen(int $id, int $detailId, array $data): void
    {
        DB::transaction(function () use ($id, $detailId, $data) {
            self::draf($id);

            DB::table('penggajian_komponen')->insert(self::barisManual($detailId, $data) + ['created_at' => now(), 'updated_at' => now()]);
            self::hitungTotal($detailId);
        });
    }

    /**
     * Ubah komponen manual di draf.
     *
     * @throws ValidationException
     */
    public static function ubahKomponen(int $id, int $detailId, int $komponenId, array $data): void
    {
        DB::transaction(function () use ($id, $detailId, $komponenId, $data) {
            self::draf($id);
            self::komponenManual($detailId, $komponenId);

            DB::table('penggajian_komponen')->where('id', $komponenId)->update(self::barisManual($detailId, $data) + ['updated_at' => now()]);
            self::hitungTotal($detailId);
        });
    }

    /**
     * Hapus komponen manual di draf.
     *
     * @throws ValidationException
     */
    public static function hapusKomponen(int $id, int $detailId, int $komponenId): void
    {
        DB::transaction(function () use ($id, $detailId, $komponenId) {
            self::draf($id);
            self::komponenManual($detailId, $komponenId);

            DB::table('penggajian_komponen')->where('id', $komponenId)->delete();
            self::hitungTotal($detailId);
        });
    }

    /**
     * Orang di penggajian yang datanya belum lengkap, mis. tanpa gaji pokok. Dibaca dari komponen yang tersimpan,
     * jadi setelah data karyawan diperbaiki draf perlu dihitung ulang.
     *
     * @return list<array{karyawan_id: int, nama: string, masalah: string}>
     */
    public static function kekurangan(int $id): array
    {
        // Insentif dihitung dari rumus dan penjualan, bukan dari gaji karyawan, jadi tidak ada data wajib yang bisa kosong.
        if (DB::table('penggajian')->where('id', $id)->value('kategori') !== 'gaji') {
            return [];
        }

        return DB::table('penggajian_detail as d')->where('d.penggajian_id', $id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('penggajian_komponen as k')->whereColumn('k.detail_id', 'd.id')->where('k.kode', 'gaji_pokok'))
            ->orderBy('d.nama')->get(['d.karyawan_id', 'd.nama'])
            ->map(fn ($d) => ['karyawan_id' => (int) $d->karyawan_id, 'nama' => $d->nama, 'masalah' => 'Gaji pokok kosong'])
            ->values()->all();
    }

    /**
     * Finalkan draf: ditolak selama ada kekurangan data. Periode yang belum selesai tetap boleh difinalkan
     * (halaman menampilkan peringatan). Sesudahnya penggajian terkunci.
     *
     * @throws ValidationException
     */
    public static function finalkan(int $id, User $aktor): void
    {
        DB::transaction(function () use ($id, $aktor) {
            self::draf($id);

            $kurang = self::kekurangan($id);

            if ($kurang) {
                $contoh = collect($kurang)->take(3)->map(fn ($k) => "{$k['nama']}: {$k['masalah']}")->implode('; ');
                $lebih = count($kurang) > 3 ? ', dan '.(count($kurang) - 3).' lainnya' : '';

                throw ValidationException::withMessages(['penggajian' => 'Tidak bisa difinalkan, '.count($kurang)." orang datanya belum lengkap ({$contoh}{$lebih})."]);
            }

            DB::table('penggajian')->where('id', $id)->update([
                'status' => 'final', 'difinalkan_oleh' => $aktor->id, 'difinalkan_pada' => now(), 'updated_at' => now(),
            ]);
        });

        Akses::catat($aktor, 'penggajian', $id, 'finalkan');
    }

    /**
     * Batalkan draf. Baris tetap ada dengan status batal, dan tidak lagi menghalangi penggajian baru.
     *
     * @throws ValidationException
     */
    public static function batalkan(int $id, User $aktor): void
    {
        DB::transaction(function () use ($id) {
            self::draf($id);

            DB::table('penggajian')->where('id', $id)->update(['status' => 'batal', 'updated_at' => now()]);
        });

        Akses::catat($aktor, 'penggajian', $id, 'batalkan');
    }

    /** Penggajian harus berstatus draf (baris dikunci selama transaksi). */
    private static function draf(int $id): object
    {
        $p = DB::table('penggajian')->where('id', $id)->lockForUpdate()->first();

        if (! $p || $p->status !== 'draf') {
            $status = $p->status ?? 'tidak ada';

            throw ValidationException::withMessages(['penggajian' => "Penggajian berstatus {$status}; hanya draf yang bisa diubah, dihitung ulang, difinalkan, atau dibatalkan."]);
        }

        return $p;
    }

    /**
     * Masukan hitungan gaji untuk sekumpulan orang: kehadiran per orang dan hari kerja periode.
     *
     * @param  list<int>  $ids
     * @return array{kehadiran: array<int, array{hadir: int, terlambat: int}>, hariKerja: int}
     */
    private static function masukanGaji(array $ids, string $mulai, string $selesai): array
    {
        return ['kehadiran' => HitungGaji::kehadiran($ids, $mulai, $selesai), 'hariKerja' => PeriodeGaji::hariKerja($mulai, $selesai)];
    }

    /**
     * Masukan hitungan insentif: total EC dan poin per orang (dari sumber yang sama dengan Lap. Insentif Sales) dan nama
     * depo tiap orang untuk konversi poin.
     *
     * @param  list<int>  $ids
     * @return array{penjualan: array<int, array{ec: int, poin: int}>, depo: array<int, string>}
     */
    private static function masukanInsentif(array $ids, string $mulai, string $selesai): array
    {
        $penjualan = Insentif::penjualanPerSales($mulai, $selesai)->whereIn('karyawan_id', $ids)->get()
            ->mapWithKeys(fn ($r) => [(int) $r->karyawan_id => ['ec' => (int) $r->ec, 'poin' => (int) $r->poin]])->all();

        $depoIds = DB::table('mk_karyawan')->whereIn('id', $ids)->whereNotNull('depo_id')->distinct()->pluck('depo_id');
        $depo = DB::table('mk_depo')->whereIn('id', $depoIds)->pluck('nama', 'id')->mapWithKeys(fn ($n, $i) => [(int) $i => $n])->all();

        return ['penjualan' => $penjualan, 'depo' => $depo];
    }

    /** Tulis ulang hari dan komponen otomatis satu orang; komponen manual tidak disentuh. */
    private static function segarkanDetail(string $jenis, string $kategori, int $detailId, object $karyawan, array $masukan, int $hariIzin): void
    {
        $hasil = $kategori === 'insentif'
            ? HitungGaji::insentifSales($masukan['depo'][(int) $karyawan->depo_id] ?? null, $masukan['penjualan'][(int) $karyawan->id] ?? ['ec' => 0, 'poin' => 0])
            : ($jenis === 'koordinator' ? HitungGaji::koordinator(...) : HitungGaji::sales(...))($karyawan, $masukan['kehadiran'][(int) $karyawan->id], $masukan['hariKerja'], $hariIzin);

        DB::table('penggajian_detail')->where('id', $detailId)->update([
            'hari_kerja' => $hasil['hari_kerja'], 'hari_hadir' => $hasil['hari_hadir'],
            'hari_terlambat' => $hasil['hari_terlambat'], 'hari_izin' => $hasil['hari_izin'], 'updated_at' => now(),
        ]);

        DB::table('penggajian_komponen')->where('detail_id', $detailId)->where('sumber', 'otomatis')->delete();

        if ($hasil['komponen']) {
            DB::table('penggajian_komponen')->insert(array_map(fn ($c) => [
                'detail_id' => $detailId, 'tipe' => $c['tipe'], 'kode' => $c['kode'], 'nama' => $c['nama'],
                'nominal' => $c['nominal'], 'sumber' => $c['sumber'], 'rincian' => json_encode($c['rincian']),
                'created_at' => now(), 'updated_at' => now(),
            ], $hasil['komponen']));
        }

        self::hitungTotal($detailId);
    }

    /** Total pendapatan, potongan, dan bersih satu detail dari semua komponennya. */
    private static function hitungTotal(int $detailId): void
    {
        $per = DB::table('penggajian_komponen')->where('detail_id', $detailId)->groupBy('tipe')->selectRaw('tipe, sum(nominal) as total')->pluck('total', 'tipe');
        $pendapatan = (float) ($per['pendapatan'] ?? 0);
        $potongan = (float) ($per['potongan'] ?? 0);

        DB::table('penggajian_detail')->where('id', $detailId)->update([
            'total_pendapatan' => $pendapatan, 'total_potongan' => $potongan, 'total_bersih' => $pendapatan - $potongan, 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> kolom penggajian_komponen untuk komponen manual */
    private static function barisManual(int $detailId, array $data): array
    {
        $potongan = $data['tipe'] === 'potongan';
        $parameter = $potongan ? DB::table('parameter_pemotong_gaji')->where('id', $data['parameter_pemotong_id'] ?? 0)->first() : null;

        if ($potongan && ! $parameter) {
            throw ValidationException::withMessages(['parameter_pemotong_id' => 'Pilih jenis potongan dari Parameter Pemotong Gaji.']);
        }

        return [
            'detail_id' => $detailId,
            'tipe' => $data['tipe'],
            'kode' => $potongan ? 'potongan_manual' : 'pendapatan_manual',
            'nama' => $potongan ? $parameter->nama : $data['nama'],
            'nominal' => $data['nominal'],
            'keterangan' => $data['keterangan'] ?? null,
            'parameter_pemotong_id' => $parameter?->id,
            'sumber' => 'manual',
            'rincian' => null,
        ];
    }

    /** Komponen harus milik detail itu dan berasal dari isian manual. */
    private static function komponenManual(int $detailId, int $komponenId): void
    {
        $k = DB::table('penggajian_komponen')->where('id', $komponenId)->where('detail_id', $detailId)->first();

        if (! $k || $k->sumber !== 'manual') {
            throw ValidationException::withMessages(['penggajian' => 'Hanya komponen manual yang bisa diubah atau dihapus. Komponen otomatis mengikuti Hitung Ulang.']);
        }
    }

    /**
     * Satu orang tidak boleh ada di dua penggajian aktif (draf atau final) pada kategori yang sama dengan periode
     * yang beririsan. Penggajian batal tidak menghalangi, dan gaji tidak menghalangi insentif.
     *
     * @param  list<int|string>  $karyawanIds
     *
     * @throws ValidationException
     */
    private static function pastikanTidakTumpangTindih(string $kategori, array $karyawanIds, string $mulai, string $selesai): void
    {
        $bentrok = DB::table('penggajian as p')->join('penggajian_detail as d', 'd.penggajian_id', '=', 'p.id')
            ->where('p.kategori', $kategori)->whereIn('p.status', ['draf', 'final'])
            ->where('p.periode_mulai', '<=', $selesai)->where('p.periode_selesai', '>=', $mulai)
            ->whereIn('d.karyawan_id', $karyawanIds)
            ->orderBy('p.id')->first(['p.no_penggajian', 'p.periode_mulai', 'p.periode_selesai', 'd.nama']);

        if ($bentrok) {
            throw ValidationException::withMessages(['periode_mulai' => "Periode tumpang tindih dengan penggajian {$bentrok->no_penggajian} ({$bentrok->periode_mulai} s.d. {$bentrok->periode_selesai}) untuk {$bentrok->nama}."]);
        }
    }

    /** Nomor unik: awalan-bulan label-depo-urutan (penggajian batal ikut dihitung supaya nomor tidak dipakai ulang). */
    private static function nomor(string $awalan, int $depoId, string $periodeSelesai): string
    {
        $dasar = sprintf('%s-%s-%03d', $awalan, str_replace('-', '', substr($periodeSelesai, 0, 7)), $depoId);
        $urut = DB::table('penggajian')->where('no_penggajian', 'like', $dasar.'-%')->count() + 1;

        return "{$dasar}-{$urut}";
    }
}
