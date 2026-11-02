# Detail Motoris di Lacak Motoris

## Masalah

Koordinator yang menganalisa kinerja motoris di halaman **Lacak Motoris** (`/tracking_sales`) hanya melihat posisi
terakhir, riwayat rute per tanggal, dan tanda. Untuk tahu siapa motoris itu (sejak kapan bekerja, depo, area) dan
bagaimana transaksinya dalam satu periode, koordinator harus pindah ke menu Karyawan Sales, Motoris Report, dan
laporan penjualan satu per satu — lalu mencocokkan sendiri nama motorisnya.

## Solusi

Nama motoris di tabel Lacak Motoris (`tracking_last_seen_sales`) menjadi tautan yang membuka **modal full-page
Detail Motoris** berisi profil motoris dan historis transaksinya pada periode yang dipilih koordinator.

## User stories

1. Sebagai koordinator, saya mengklik nama motoris di Lacak Motoris dan langsung melihat detail motoris dalam modal
   sepenuh layar, tanpa meninggalkan halaman.
2. Sebagai koordinator, saya melihat profil motoris: nama, no HP, depo, koordinator, area aktif, tanggal masuk, masa
   kerja, dan status aktif.
3. Sebagai koordinator, saya memilih periode (tanggal dari–sampai; bawaan awal bulan ini s.d. hari ini) dan semua
   angka mengikuti periode itu.
4. Sebagai koordinator, saya melihat ringkasan kinerja periode: hari hadir dibanding hari kerja standar, jumlah
   terlambat, kunjungan (call), effective call dan rasionya, jumlah transaksi, total penjualan, total qty, dan
   rata-rata penjualan per hari hadir.
5. Sebagai koordinator, saya melihat grafik dan tabel per hari (jam masuk, jam pulang, kunjungan, EC, transaksi,
   penjualan) untuk menemukan hari-hari yang tidak wajar.
6. Sebagai koordinator, saya melihat daftar transaksi penjualan motoris pada periode itu (kode, waktu, outlet, SKU,
   qty, total, status batal).
7. Sebagai koordinator, saya melihat produk terlaris motoris pada periode itu (qty dan nilai).
8. Sebagai koordinator, saya melihat realisasi kunjungan (Call) dan EC dalam persen terhadap target depo motoris, di
   ringkasan periode dan di setiap baris harian.
9. Sebagai koordinator, saya melihat tanda motoris pada periode itu dan tanda outlet pada outlet yang didaftarkan
   motoris ini, untuk menilai kewajaran kinerjanya.
10. Sebagai koordinator, saya hanya bisa membuka detail motoris dalam cakupan role saya (motoris bawahan saya).

## Keputusan implementasi

- **Endpoint JSON** `GET /tracking_sales/{id}/detail?dari=Y-m-d&sampai=Y-m-d`, hak `tracking_sales` (Lihat), hanya
  karyawan kategori `sales` dalam cakupan `Akses::batasiKaryawan` (di luar cakupan → 404). Dipanggil dari modal, bukan
  halaman Inertia, sama seperti endpoint tanda motoris.
- **Periode**: bawaan awal bulan berjalan s.d. hari ini (zona `Pengaturan::zona()`); `sampai` ≥ `dari`; rentang maks.
  366 hari (validasi 422).
- **Modul `App\Support\Motoris\DetailMotoris`** menghitung semua isi (profil, ringkasan, harian, transaksi, produk) dari
  `mk_karyawan`, `mk_depo`, `mk_karyawan_area`/`mk_area`, `trx_absensi`, `trx_kunjungan`, `trx_penjualan`,
  `trx_penjualan_item`, `mk_produk`, `mk_outlet`. Controller tipis.
- **Definisi angka sama dengan Motoris Report** (`LaporanMotoris`): hari hadir = baris `trx_absensi`; kunjungan =
  baris `trx_kunjungan`; transaksi, EC (`is_effective_call`), qty, dan nilai dari `trx_penjualan` yang **tidak
  dibatalkan**; hari kerja standar = `LaporanMotoris::hariKerja(dari, sampai)` (sampai dibatasi hari ini).
- **Transaksi**: maks. 500 terbaru (urut waktu turun) beserta jumlah total; transaksi batal tetap tampil dengan status
  "Batal" tapi tidak ikut ringkasan. Waktu `recorded_at` (UTC) ditampilkan jam lokal lewat `JamLokal`.
- **Target**: Call/EC per hari dari pengaturan depo motoris (`Pengaturan::depo`, bawaan `mobile.target_default`) — sama
  dengan KPI di aplikasi HP. Target periode = target harian × hari kerja standar (seperti Motoris Report); persen
  dihitung di frontend (`persenTarget`). Baris harian dibandingkan dengan target harian.
- **Jam pulang** dari `trx_absensi.pulang_at` (jam lokal); kosong bila belum absen pulang.
- **Tab Ditandai**: tanda motoris (`tanda_motoris`) pada tanggal dalam periode, yang dicabut tetap tampil berstatus
  Dicabut; tanda outlet aktif pada outlet yang didaftarkan motoris ini (`TandaOutletMotoris::aktif`, jendela hari
  yang sama dengan lencana Ditandai, maks. 50, tidak mengikuti periode).
- **Produk terlaris**: 10 produk dengan qty terbanyak dari item penjualan yang tidak batal.
- **Tanggal masuk** dari `mk_karyawan.tanggal_mulai` (teks bebas dari aplikasi lama: `dd/mm/yyyy`, ISO, "1 Maret
  2026") — dinormalkan ke Y-m-d; masa kerja dihitung dari situ (mis. "2 tahun 3 bulan"). Tidak bisa dibaca → "-".
- **Data pribadi** (NIK, alamat, tanggal lahir, gaji) sengaja tidak ditampilkan — tidak diperlukan untuk analisa
  kinerja.
- **UI**: `Pages/Menu/partials/DetailMotoris.vue` dalam `Modal size="full"` dari `Menu/Show.vue`, dibuka dari slot
  `#cell-nama` tabel `tracking_last_seen_sales`. Isi: baris periode (dua `DatePicker` + tombol Tampilkan), kartu
  profil, kartu KPI, lalu `Tabs` Harian (grafik `BarChart` + `DataTable`) / Transaksi (`DataTable`) / Produk
  (`DataTable`). Semua dari komponen `Components/ui`.

## Testing

Feature test `tests/Feature/Admin/DetailMotorisTest.php` (pola `AdminTestCase`): bentuk JSON profil, angka ringkasan
mengikuti periode dan mengabaikan transaksi batal, baris harian, daftar transaksi dan produk terlaris, periode bawaan,
validasi periode, cakupan role (motoris di luar bawahan koordinator → 404), dan tanpa hak `tracking_sales` → 403.
Vitest `DetailMotoris.spec.js`: memuat data dari URL dengan periode, menampilkan profil/KPI, dan mengambil ulang saat
periode diubah.

## Out of Scope

- Detail koordinator di halaman Last Seen Koordinator.
- Ekspor detail motoris ke CSV/PDF.
- Perbandingan antar motoris atau target per motoris (sudah ada di Motoris Report / Kuota).
- Riwayat dari aplikasi lama di luar tabel `trx_*` (sudah dimigrasikan lewat `enesis:migrasi-transaksi`).
