# Koreksi KPI Depo dari Outlet Terbukti Fiktif

## Masalah

Spec 067 membuat outlet yang tanda outlet-nya **Terbukti** fiktif tidak dihitung di Outlet Terjangkau, Repeat Order,
dan grafik outlet per depo. Namun **penjualan dan kunjungan di outlet itu tetap ikut** di KPI aktivitas:

- Call, Effective Call (EC), SKU, IPT, nilai penjualan, dan poin di Summary All Depo, Beranda admin, dan Beranda
  Koordinator.
- Capaian terhadap target (Call/EC/IPT Achievement) dan daftar "Depo Tertinggal".

Akibatnya, setelah koordinator menilai satu titik berisi 25 outlet fiktif, angka penyebaran turun tetapi **EC depo
tetap menggelembung** oleh transaksi di outlet yang tidak ada. Dua halaman yang sama bisa saling bertentangan: Outlet
Terjangkau sudah bersih, Effective Call belum. Target EC adalah dorongan terbesar untuk mencatat transaksi di outlet
fiktif, jadi celah inilah yang seharusnya ditutup.

## Solusi

Penjualan dan kunjungan di outlet dengan tanda outlet **Terbukti** (belum dicabut) tidak dihitung di KPI aktivitas,
dengan definisi yang sama dengan `Dashboard::outletTerbuktiFiktif()`. Angka yang dikoreksi ditampilkan terpisah supaya
transparan.

## User stories

1. Sebagai Manajemen, saya melihat Call, EC, IPT, dan capaiannya tanpa transaksi di outlet terbukti fiktif.
2. Sebagai Manajemen, saya tahu berapa EC yang dikoreksi per depo, supaya koreksi tidak terlihat sebagai penurunan
   kinerja tanpa sebab.
3. Sebagai Koordinator, angka tim saya konsisten dengan angka di Beranda admin.
4. Sebagai Koordinator, bila tanda Terbukti dicabut, angka kembali seperti semula.

## Keputusan

- **Berlaku surut untuk seluruh periode.** Outlet terbukti fiktif dianggap tidak pernah ada, jadi transaksinya
  dikeluarkan dari semua tanggal, bukan hanya sejak tanda dibuat. Tanda yang dicabut (`dicabut_at` terisi) tidak
  dihitung, sehingga angka pulih sendiri.
- **Yang dikeluarkan:** `trx_penjualan` (EC, SKU, nilai, poin) dan `trx_kunjungan` (Call) pada outlet itu.
- **Hari kerja tidak berubah.** Tetap dari absen ∪ kunjungan tanpa pengecualian; motoris tetap bekerja pada hari itu,
  hanya hasilnya yang tidak dihitung. Bila kunjungan fiktif ikut dikeluarkan dari hari kerja, rata-rata per hari akan
  salah naik.
- **Data mentah tidak diubah.** Tidak ada baris yang dihapus atau ditandai di `trx_*`; koreksi dihitung saat query.
- **Tidak ada sanksi otomatis** ke motoris pendaftar; ini hanya koreksi angka.

## Rincian

1. `SummaryDepo`: query `sales()` (kunjungan dan penjualan) menambahkan pengecualian
   `whereNotIn('outlet_id', Dashboard::outletTerbuktiFiktif())` (perhatikan `outlet_id` NULL tetap dihitung). Tambahkan
   `ec_dikoreksi` per depo (EC di outlet terbukti fiktif) pada `kartu()` dan `detail()`.
2. `HomeController::kpi()` dan perbandingan 30 hari sebelumnya otomatis ikut karena sumbernya `kartu()`; tambahkan
   total `ec_dikoreksi` nasional.
3. Drill-down penjualan depo (`SummaryDepo::penjualan`): baris di outlet terbukti fiktif tetap tampil tetapi diberi
   lencana "Outlet fiktif" dan tidak masuk total.
4. Tampilan: keterangan kecil di kartu Effective Call ("N EC dikoreksi, outlet terbukti fiktif") dan di baris depo
   Summary All Depo bila `ec_dikoreksi > 0`. InfoPopover Beranda diperbarui.
5. Cache Beranda (`CACHE_TTL` 300 detik) cukup; setelah `TandaOutletController@periksa` angka menyesuaikan paling lama
   5 menit, atau langsung bila kunci cache kartu ikut dilupakan.

## Di luar cakupan

- Laporan Motoris dan Detail Motoris (angka per motoris) tidak diubah di spec ini; dicatat sebagai tindak lanjut bila
  perlu konsisten.
- Aplikasi HP motoris tidak diubah.
- Pemblokiran transaksi baru di outlet fiktif.

## Pertanyaan terbuka

1. Apakah **Wajar** perlu memulihkan sesuatu? Saat ini tidak, karena Wajar tidak pernah mengeluarkan transaksi.
2. Apakah Laporan Motoris/Detail Motoris sebaiknya ikut dikoreksi dalam satu rilis dengan ini?
