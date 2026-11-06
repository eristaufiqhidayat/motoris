# Penggajian Motoris dan Koordinator

## Masalah

Halaman Penggajian Sales dan Penggajian Koordinator kosong. Keduanya hanya menampilkan tabel impor Marketama
(`penggajian_sales`, `penggajian_koordinator`), dan tidak ada kode yang mengisinya.

Tabel itu juga tidak cukup untuk kebutuhan penggajian: isinya hanya header (nomor, periode, total), semua kolom bertipe
string, dan tidak ada rincian gaji dan potongan per orang. Admin tidak bisa menjawab berapa yang diterima seorang motoris
pada periode tertentu dan dari mana angkanya.

Gaji dan insentif juga berjalan pada jadwal berbeda. Periode absen adalah tanggal 20 sampai tanggal 19 bulan berikutnya,
sedangkan insentif dihitung per bulan kalender dan dibayarkan tanggal 15 bulan berikutnya.

## Solusi

Admin membuat penggajian untuk satu depo dan periode, dengan dua kategori terpisah: **gaji** dan **insentif**. Sistem
menghitung draf dari data dasar. Admin meninjau, menambah komponen manual seperti kasbon atau BPJS, lalu memfinalkan. Saat
final, rincian tiap orang tersimpan dan terkunci, sehingga perubahan gaji karyawan, rumus, atau absensi tidak mengubah
angka yang sudah final.

Tanggal cut off gaji, tanggal bayar gaji, dan tanggal bayar insentif bisa diubah admin dengan tanggal mulai berlaku. Penggajian yang sudah
dibuat tidak ikut berubah.

## User stories

1. Sebagai admin, saya membuat penggajian gaji untuk satu depo dan periode, supaya sistem menghitung draf semua motorisnya.
2. Sebagai admin, saya membuat penggajian insentif untuk satu depo dan bulan, secara terpisah dari gaji.
3. Sebagai admin, saya melihat periode terisi otomatis dari pengaturan yang berlaku.
4. Sebagai admin, saya membuka rincian tiap orang, supaya tahu asal setiap angka pendapatan dan potongan.
5. Sebagai admin, saya mengisi hari izin tiap motoris di draf, supaya tunjangan hari itu tetap dibayar.
6. Sebagai admin, saya menambah, mengubah, atau menghapus komponen manual di draf, seperti potongan absen, kasbon, atau
   BPJS.
7. Sebagai admin, saya menghitung ulang draf setelah absensi atau data karyawan diperbaiki, tanpa kehilangan isian
   manual.
8. Sebagai admin, saya memfinalkan penggajian, supaya angkanya tidak bisa berubah lagi.
9. Sebagai admin, saya melihat kekurangan data sebelum final, misalnya gaji pokok kosong.
10. Sebagai admin, saya ditolak bila membuat penggajian dengan kategori sama yang periodenya tumpang tindih untuk orang
    yang sama, supaya tidak ada pembayaran ganda.
11. Sebagai admin, saya mencari riwayat gaji dan insentif satu motoris per periode.
12. Sebagai admin, saya mengubah tanggal cut off gaji, tanggal bayar gaji, dan tanggal bayar insentif dengan tanggal mulai berlaku, dan melihat
    riwayat perubahannya.
13. Sebagai admin, saya membatalkan draf yang salah.
14. Sebagai admin, saya mengisi tunjangan jabatan di data karyawan, supaya masuk ke draf gaji.
15. Sebagai pengguna selain Super Admin, saya tidak bisa membuka menu ini, karena data gaji sensitif.

## Keputusan

Keputusan bertanda *(menunggu konfirmasi)* dijawab pada 2026-11-06, tetapi masih bisa berubah sampai dikonfirmasi ke pihak
Marketama/Enesis. Statusnya dipantau di [docs/BUSINESS_RULES.md](../../BUSINESS_RULES.md) §9–§11.

- **Dihitung dari tabel dasar, disimpan sebagai snapshot saat final.** Draf boleh dihitung ulang, baris final tidak.
  Tabel `lap_*` tidak dipakai sebagai sumber.
- **Satu set tabel untuk sales dan koordinator**, dibedakan kolom `jenis`. Menu tetap dua halaman dengan slug lama
  (`penggajian_sales`, `penggajian_koordinator`). Perbedaan rumus ada di kode penghitung, bukan di skema.
- **Gaji dan insentif adalah dokumen terpisah** *(menunggu konfirmasi)*, dibedakan kolom `kategori` (`gaji`, `insentif`). Tiap
  kategori punya periode, draf, dan finalisasi sendiri.
- **Tabel baru, bukan mengubah tabel impor.** `penggajian_sales` dan `penggajian_koordinator` dibiarkan sebagai arsip
  impor dan tidak lagi dibaca menu.
- **Komponen disimpan sebagai baris**, bukan kolom tetap, karena komponen bisa berbeda per jenis dan kategori.
- **Periode hanya ada di header.** Detail mengambilnya lewat `penggajian_id`. Larangan pembayaran ganda dan periode
  tumpang tindih dijaga validasi di dalam transaksi, per kategori, karena constraint unik tidak menangkap tumpang tindih.
- **Hanya motoris dengan status aktif eksplisit yang digaji.** Karyawan dengan status kosong (banyak hasil impor Marketama)
  atau nonaktif tidak masuk draf. Karyawan yang seharusnya digaji perlu ditandai aktif di data karyawan.
- **Draf semua depo sekaligus = satu penggajian per depo.** Form Tambah boleh dikosongkan depo-nya; sistem membuat satu draf per
  depo (dalam cakupan akun) yang punya karyawan aktif jenis itu, masing-masing dengan transaksi sendiri. Depo yang
  periodenya tumpang tindih dengan penggajian yang sudah ada dilewati dan disebut di pesan; bila tidak ada yang bisa
  dibuat, ditolak. Model tetap satu depo per penggajian (kuota, cakupan, dan finalisasi per depo).
- **Satu penggajian mencakup satu depo.** Koordinator ikut menjadi filter opsional, sama seperti kolom di data lama.
- **Periode gaji mengikuti cut off** *(menunggu konfirmasi)*. Dengan cut off `C`, periode berjalan dari tanggal `C` sampai
  tanggal `C - 1` bulan berikutnya, awalnya 20–19. Header menyimpan tanggal nyata, jadi perubahan pengaturan tidak
  menyentuh penggajian yang sudah ada.
- **Label periode mengikuti bulan tanggal akhir** *(menunggu konfirmasi)*: 20 Oktober–19 November adalah periode November.
- **Periode insentif adalah bulan kalender** *(menunggu konfirmasi)*, sama dengan Lap. Insentif Sales.
- **Tanggal bayar insentif awalnya tanggal 15 bulan berikutnya, dan bisa diubah admin** *(menunggu konfirmasi)*. Tanggal ini
  disalin ke header `tanggal_bayar`. Insentif November dibayar 15 Desember. Cut off insentif tetap akhir bulan kalender dan tidak ada di pengaturan.
- **Tanggal bayar gaji awalnya tanggal 25 pada bulan label periode, dan bisa diubah admin** *(menunggu konfirmasi)*. Periode
  20 Oktober–19 November (November) dibayar 25 November. Tanggal ini juga disalin ke header `tanggal_bayar`.
  Tanggal bayar gaji tidak boleh lebih awal dari tanggal cut off gaji (gaji dibayar setelah periode berakhir), diperiksa
  juga terhadap pengaturan yang sudah terjadwal. Karena periode cut off 1 berakhir di akhir bulan, cut off 1 ditolak.
- **Pengaturan tanggal bersifat global** *(menunggu konfirmasi)*, bernilai 1–28, dan punya `berlaku_mulai`. Tanggal 29–31 tidak
  ada di setiap bulan. Tanggal berlaku tidak boleh di masa lalu, dan riwayat tidak ditimpa, pola yang sama dengan
  `master_produk_distribusi`.
- **Pengaturan tanggal punya tabel sendiri, bukan `pengaturan_admin`.** `pengaturan_admin` hanya menyimpan satu nilai per
  kunci, sehingga tidak bisa menyimpan tanggal berlaku dan riwayat.
- **Hari kerja adalah Senin–Sabtu** *(menunggu konfirmasi)*, dikurangi `Pengaturan::libur()`.
- **Gaji pokok, tunjangan internet, dan tunjangan jabatan dibayar penuh. Tunjangan BBM dan makan serta sewa motor
  prorata** *(menunggu konfirmasi)*. Rumus prorata: nilai bulanan ×
  (hari hadir + hari izin) ÷ hari kerja, dibulatkan ke rupiah terdekat.
- **Hari izin diisi manual di draf** *(menunggu konfirmasi)*, dan dihitung sebagai hadir untuk prorata. `permintaan_izin` tidak
  dipakai karena hanya berisi nama dan tanggal teks.
- **Potongan selain keterlambatan diisi manual di draf** *(menunggu konfirmasi, tarif Potongan Absen dibahas lain waktu)*.
  Pilihan jenisnya dari `parameter_pemotong_gaji`. Detail menampilkan hari hadir, tidak hadir, dan terlambat sebagai dasar
  isian. Tabel `potongan_gaji` tidak dipakai karena nama karyawan, tanggal, dan nominalnya berupa string tanpa `karyawan_id`.
- **Potongan terlambat dihitung otomatis untuk motoris.** `docs/BUSINESS_RULES.md` §10: toleransi 3 kali per bulan, dan
  mulai keterlambatan ke-4 dalam bulan yang sama tunjangan BBM & makan dan sewa motor dipotong per hari. Potongan per hari
  = nilai bulanan ÷ hari kerja *(menunggu konfirmasi)*, dua komponen potongan `otomatis` (`potongan_terlambat_bbm_makan`,
  `potongan_terlambat_sewa_motor`) yang menyimpan asal angkanya (terlambat per bulan, toleransi, hari dipotong), dibatasi
  sebesar tunjangan prorata yang dibayar. Toleransi dihitung per bulan kalender di dalam periode gaji *(menunggu
  konfirmasi)*. Koordinator tidak dikenai potongan ini. Komponen otomatis ikut diganti saat Hitung Ulang dan saat hari
  izin disimpan (orang itu dihitung ulang dari absensi terbaru).
- **Tunjangan jabatan memakai kolom baru** `mk_karyawan.tunjangan_jabatan` *(menunggu konfirmasi)*, diisi di form karyawan.
- **Insentif absensi tidak dipakai** *(menunggu konfirmasi)*.
- **Penggajian koordinator: gaji sudah tersedia, insentif menunggu aturan rumus** *(menunggu konfirmasi)*. Gaji koordinator memakai
  komponen dan alur yang sama dengan sales di `/penggajian_koordinator` (satu controller, jenis dari slug URL). Insentif
  koordinator ditolak dengan pesan jelas sampai aturan untuk tim bukan 10 sales dijawab (issue #100, pertanyaan 21).
- **Hanya Super Admin**, mengikuti `Akses::MENU_ADMIN` yang sudah memuat dua slug ini. Akun dengan cakupan sebagian depo
  dibatasi `Cakupan::untuk($user)->depoIds()`.

## Sumber data

| Kategori | Komponen | Sumber | Cara hitung |
| --- | --- | --- | --- |
| Gaji | Gaji pokok | `mk_karyawan.gaji_pokok` | Penuh |
| Gaji | Tunjangan internet | `mk_karyawan.tunjangan_internet` | Penuh |
| Gaji | Tunjangan jabatan | `mk_karyawan.tunjangan_jabatan` (baru) | Penuh |
| Gaji | Tunjangan BBM dan makan | `mk_karyawan.tunjangan_bbm_makan` | Prorata |
| Gaji | Sewa motor | `mk_karyawan.sewa_motor` | Prorata |
| Gaji | Potongan terlambat (motoris) | `trx_absensi.terlambat` per bulan kalender | Otomatis, hari ke-4 dst × nilai bulanan ÷ hari kerja |
| Gaji | Potongan lain | `parameter_pemotong_gaji` (nama) | Manual |
| Insentif | Insentif EC sales | `Insentif::ec()` + `trx_penjualan` | Otomatis, total EC sebulan |
| Insentif | Nilai poin sales | `Insentif::nilaiPoin()` | Otomatis |
| Insentif | Insentif koordinator | `rumus_insentif_koordinator` | Tiket koordinator |

Hari hadir dan terlambat diambil dari `trx_absensi` (`masuk_at`, `terlambat`).

## Rincian

1. **Migration**:
   - `penggajian`: `id`, `jenis` (`sales`, `koordinator`), `kategori` (`gaji`, `insentif`), `no_penggajian` (unik),
     `periode_mulai`, `periode_selesai`, `tanggal_bayar` (nullable), `depo_id`, `koordinator_id` (nullable), `status`
     (`draf`, `final`, `batal`), `dibuat_oleh`, `difinalkan_oleh`, `difinalkan_pada`, timestamps.
   - `penggajian_detail`: `id`, `penggajian_id`, `karyawan_id`, `nama`, `hari_kerja`, `hari_hadir`, `hari_terlambat`,
     `hari_izin` (semua hari nullable, hanya untuk gaji), `total_pendapatan`, `total_potongan`, `total_bersih`,
     timestamps. Unik `(penggajian_id, karyawan_id)`. Nominal memakai `decimal(18, 2)`, sama dengan
     `mk_karyawan.gaji_pokok`.
   - `penggajian_komponen`: `id`, `detail_id`, `tipe` (`pendapatan`, `potongan`), `kode`, `nama`, `nominal`, `keterangan`,
     `parameter_pemotong_id` (nullable), `sumber` (`otomatis`, `manual`), `rincian` (JSON: EC, tingkat rumus, nilai
     bulanan, jumlah hari), timestamps.
   - `pengaturan_tanggal_penggajian`: `id`, `kunci` (`cutoff_gaji`, `bayar_gaji`, `bayar_insentif`), `tanggal`, `berlaku_mulai`,
     `diubah_oleh`, timestamps. Unik `(kunci, berlaku_mulai)`. Baris awal 20 dan 15.
   - `mk_karyawan`: tambah `tunjangan_jabatan` (decimal 18, 2, nullable).
2. **Modul periode** `App\Support\Penggajian\PeriodeGaji`, satu-satunya tempat aturan tanggal:
   - `gaji(string $tanggal)`: periode gaji yang memuat tanggal itu, beserta tanggal bayarnya.
   - `insentif(string $tanggal)`: bulan kalender dan tanggal bayarnya.
   - `label(string $periodeSelesai)`: nama periode.
   - `hariKerja(string $dari, string $sampai)`: Senin–Sabtu dikurangi libur.
   - `ubahPengaturan(string $kunci, int $tanggal, string $berlakuMulai, User $aktor)`: validasi nilai dan tanggal berlaku.
3. **Modul penghitung** `App\Support\Penggajian\HitungGaji`, satu-satunya tempat rumus. Masukannya karyawan dan periode,
   hasilnya daftar komponen, dipisah per kategori dan jenis.
4. **Modul siklus** `App\Support\Penggajian\Penggajian`:
   - `buat`: memeriksa tumpang tindih per kategori di dalam transaksi, lalu menyimpan draf.
   - `hitungUlang`: mengganti komponen `otomatis`, mempertahankan komponen `manual` dan `hari_izin`.
   - `finalkan`: menolak bila ada kekurangan data, lalu mengunci.
   - `batalkan`: hanya untuk draf, baris tetap ada dengan status `batal`.
5. **Data karyawan**: `tunjangan_jabatan` ditambahkan ke `KaryawanController::GAJI`, `FormKaryawan.vue`, dan
   `ReviewKaryawan.vue`.
6. **Halaman** dengan route yang dideklarasikan sebelum catch-all, middleware `hak:penggajian_sales` dan
   `hak:penggajian_koordinator`:
   - `PenggajianController` dan `Pages/Penggajian/`, memakai `DataTable`, `Card`, `Badge`, `Button`, `Modal`, `DatePicker`,
     `Tabs`.
   - Daftar penggajian dengan tab Gaji dan Insentif, filter periode, depo, status, dan tombol Tambah.
   - Detail penggajian berisi tabel orang, drawer rincian per orang, daftar kekurangan data, Hitung Ulang, dan Finalkan.
   - Riwayat per orang lewat pencarian nama.
   - Halaman pengaturan tanggal, slug baru `pengaturan_penggajian`, ditambahkan ke `Akses::MENU_ADMIN`.
7. **Pencatatan**: buat, finalkan, batalkan, dan ubah pengaturan dicatat lewat `Akses::catat`.
8. **Backup**: empat tabel baru masuk daftar `ENESIS` di `App\Support\Backup\DaftarTabel`.
9. **CLAUDE.md**: tambahkan catatan modul ini di bagian arsitektur.

## Pengujian

Seam utama adalah HTTP: `$this->post/put` lalu `assertInertia`, tanpa membaca ulang database untuk hal yang sudah
dikembalikan response. `PeriodeGaji` dan `HitungGaji` diuji langsung karena kombinasinya banyak.

- `PeriodeGaji`: tanggal sebelum dan sesudah cut off, pergantian tahun, Februari, pengaturan bertanggal masa depan belum
  berlaku, nilai 29 ditolak, tanggal berlaku lampau ditolak, tanggal bayar insentif, hari kerja dengan Minggu dan libur.
- `HitungGaji`: komponen penuh, prorata dengan dan tanpa hari izin, absensi di luar periode tidak dihitung, insentif EC,
  nilai poin, karyawan tanpa gaji pokok.
- Siklus: hitung ulang mempertahankan isian manual, draf bisa diubah, final ditolak saat diubah, batal hanya untuk draf,
  periode tumpang tindih ditolak dalam kategori sama, gaji dan insentif periode sama tidak saling menghalangi, draf batal
  tidak menghalangi penggajian baru.
- Snapshot: mengubah `mk_karyawan.gaji_pokok` dan rumus setelah final tidak mengubah rincian.
- Akses: selain Super Admin ditolak, depo di luar cakupan ditolak.
- Vitest untuk komponen Vue bila ada logika tampilan yang tidak sepele.

## Di luar cakupan

- Tarif dan perhitungan otomatis Potongan Absen (Potongan Terlambat motoris sudah otomatis).
- Slip gaji PDF dan pembayaran atau transfer ke bank.
- Tampilan gaji di aplikasi HP.
- Pajak PPh 21.
- Pengajuan dan persetujuan izin baru di Enesis.
- Penggajian karyawan kategori `lainnya`.
- Migrasi data penggajian lama Marketama.
- Penyesuaian otomatis antarperiode. Koreksi setelah final dicatat sebagai komponen manual di periode berikutnya.

## Catatan

- Backlog 073 (koreksi KPI outlet terbukti fiktif) belum dikerjakan. Insentif EC di sini memakai data penjualan apa
  adanya sampai keputusan koreksi diambil.
- Insentif dihitung per `karyawan_id` dan digabung dalam penggajian depo saat ini. Motoris yang pindah depo di tengah
  bulan seluruh insentifnya masuk depo barunya.
- `trx_absensi` di database lokal hanya berisi 1–22 Oktober 2026, jadi uji satu periode penuh perlu data lain.
- Penggajian yang dibuat di tengah periode berjalan menghitung hari yang belum terjadi sebagai tidak hadir, sehingga
  tunjangan prorata lebih kecil. Hitung Ulang menanganinya, dan Finalkan menampilkan peringatan bila periode belum selesai.

## Pertanyaan terbuka

Daftar lengkap untuk pihak Marketama/Enesis ada di
[komentar issue #100](https://github.com/eristaufiqhidayat/enesis/issues/100#issuecomment-6684712781).

1. **Tarif Potongan Absen dan Terlambat**: dibahas lain waktu. Sampai itu, potongan diisi manual dan hitungan otomatisnya
   di luar cakupan.
2. **Rumus insentif koordinator**: batas EC di rumus ditulis untuk tim 10 sales, dan aturan untuk jumlah lain dibahas lain
   waktu. Gaji koordinator (tiket 06) sudah berjalan; hanya insentif koordinator yang menunggu jawaban ini.
3. **Karyawan masuk atau keluar di tengah periode**: apakah gaji pokok tetap penuh? Asumsi: tetap penuh, dan admin
   menyesuaikan lewat komponen manual.
4. **Tunjangan jabatan**: asumsi dibayar penuh karena kolomnya baru diputuskan dan belum ditanyakan prorata atau tidak.
5. **Daftar gaji per depo atau keseluruhan**: apakah Marketama memproses, meninjau, dan memfinalkan penggajian per depo, atau
   langsung satu daftar untuk semua motoris? Saat ini satu penggajian = satu depo, dan form Tambah bisa membuat satu draf per
   depo sekaligus (depo dikosongkan). Jika yang dibutuhkan satu daftar keseluruhan (satu dokumen, finalisasi sekali), model
   perlu diubah; jika cukup tampilan gabungan untuk dilihat, cukup laporan tanpa mengubah model.
