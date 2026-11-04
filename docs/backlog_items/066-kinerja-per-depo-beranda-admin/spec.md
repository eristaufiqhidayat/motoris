# Kinerja per Depo di Beranda Admin

## Masalah

Beranda admin (cakupan `semua`/`depo`, `Home/Dashboard`) hanya menampilkan KPI nasional dan grafik **Depo Teratas**
(8 depo dengan EC Achievement tertinggi, rentang bawaan 7 hari). Admin tidak bisa melihat kinerja **semua** depo
sekaligus — terutama depo yang tertinggal — tanpa membuka Summary All Depo. Rentang 7 hari juga terlalu pendek untuk
menilai kinerja depo; satu-dua hari libur atau gangguan sudah menggeser rata-rata.

## Solusi

Tambah kartu **Kinerja per Depo** di Beranda admin: tabel semua depo dalam cakupan akun dengan **AVG Call**, **AVG EC**,
dan **AVG IPT** (beserta achievement terhadap target nasional), mirip Summary All Depo. Kartu punya **rentang tanggal
sendiri** dengan bawaan **30 hari terakhir**, terpisah dari filter tanggal halaman (KPI, funnel, tren, Depo Teratas
tetap bawaan 7 hari).

## User stories

1. Sebagai Admin, saya melihat AVG Call, AVG EC, dan AVG IPT setiap depo dalam 30 hari terakhir langsung di Beranda.
2. Sebagai Admin, saya mengurutkan tabel per kolom untuk menemukan depo terbaik dan yang paling tertinggal.
3. Sebagai Admin, saya mengganti rentang tabel ini tanpa mengubah rentang KPI dan grafik lain di Beranda.
4. Sebagai Admin, saya mengklik nama depo untuk membuka detailnya di Summary All Depo.

## Sumber data

Tidak ada query baru. Angka diambil dari `SummaryDepo::kartu()` — dipakai Summary All Depo dan sudah dipakai Beranda
untuk KPI nasional & Depo Teratas — sehingga definisi dan angkanya **identik** dengan Summary All Depo:

| Kolom | Rumus (`SummaryDepo::ringkas`) |
| --- | --- |
| AVG Call | total call ÷ total hari kerja motoris depo |
| AVG EC | total EC ÷ total hari kerja motoris depo |
| AVG IPT | total SKU ÷ total EC |
| Achievement | AVG ÷ target nasional (`pengaturan_admin` `target_nasional.*`) × 100 |

Rata-rata berbobot (total digabung dulu, baru dibagi), bukan rata-rata dari rata-rata motoris.

## Pertimbangan beban data

- **Baris yang dikirim kecil**: satu baris per depo dalam cakupan (± 30 depo). Agregasi `trx_kunjungan`,
  `trx_penjualan`, `trx_absensi` dikerjakan database (`GROUP BY karyawan_id`), hasilnya satu baris per motoris
  (± 1.500); PHP tidak pernah memuat baris transaksi mentah.
- **Yang membesar hanya rentang**: 30 hari memindai ± 4× baris transaksi dibanding 7 hari. Kolom `tanggal` dan
  `karyawan_id` di ketiga tabel sudah berindeks (`trx_penjualan` juga `(karyawan_id, tanggal)`).
- **Tidak memperlambat halaman**: prop dikirim lewat `Inertia::defer` di grup sendiri (`kinerja`), terpisah dari grup
  `ringkasan`, sehingga KPI/funnel/tren tidak menunggu query 30 hari, dan kartu menampilkan skeleton sendiri.
- **Cache**: `Cache::remember` 5 menit (`HomeController::CACHE_TTL`) dengan kunci
  `beranda:kinerja:{dari}:{sampai}:{cakupan}` — pola sama dengan `ringkasanDepo`. Bila rentang tabel sama dengan
  rentang halaman, boleh memakai ulang hasil `ringkasanDepo` (opsional, bukan syarat).
- **Batas rentang**: maksimal 62 hari (`RENTANG_MAKS_HARI`), sama dengan filter lain di Beranda.

## Aturan

- Hanya untuk Beranda admin (`Home/Dashboard`); Beranda koordinator (cakupan `downline`) tidak berubah.
- Depo dibatasi cakupan akun (`Akses::batasiKaryawan`, sudah dilakukan `SummaryDepo`); depo aktif tanpa aktivitas
  tetap muncul dengan angka 0.
- Rentang lewat query `kinerja_dari` / `kinerja_sampai` (pola `rentangFilter`, yang perlu parameter panjang bawaan:
  30 hari untuk kartu ini, 7 hari untuk pemakai lain). Bawaan: hari ini − 29 hari s.d. hari ini (zona
  `Pengaturan::zona`).
- Tombol Tampilkan di kartu memuat ulang hanya prop kartu (`only: ['kinerja', 'kinerjaFilter']`).
- Urutan bawaan: EC Achievement tertinggi (sama dengan `kartu()`); kolom bisa diurutkan di sisi klien.
- Warna achievement memakai `warnaCapai` (sama dengan Summary All Depo).
- Nama depo menjadi tautan ke `/summary_all_depo/{id}?date_from=..&date_to=..` (rentang yang sama) bila akun punya hak
  Lihat `summary_all_depo`; selain itu teks biasa.

## Tampilan

Kartu `Kinerja per Depo` di bawah kartu Achievement Nasional, header berisi judul + rentang (`DatePicker` dari/sampai +
`Button` Tampilkan). Isi: organism `DataTable` (tanpa pagination) dengan kolom:

| Depo | Motoris Aktif | Hari Kerja | AVG Call | Call Ach. | AVG EC | EC Ach. | AVG IPT | IPT Ach. |

InfoPopover Beranda ditambah satu butir yang menjelaskan rentang 30 hari dan rumus rata-rata berbobot.

## Di luar cakupan

- Ekspor tabel (sudah tersedia di Summary All Depo).
- Grafik tren per depo.
- Mengubah rentang bawaan bagian Beranda lain.

## Pengujian

- Feature test `HomeTest`: prop `kinerja` berisi semua depo dalam cakupan (bukan dibatasi 8), rentang bawaan 30 hari,
  `kinerja_dari`/`kinerja_sampai` dihormati dan dibatasi 62 hari, angka sama dengan `SummaryDepo::kartu()` pada
  rentang yang sama, akun cakupan `depo` hanya melihat depo-nya.
- Vitest: kartu menampilkan baris depo, pengurutan kolom, tautan depo hanya bila punya hak.
