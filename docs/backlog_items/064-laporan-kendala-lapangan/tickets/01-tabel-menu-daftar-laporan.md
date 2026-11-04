# Tabel, menu, dan daftar Laporan Kendala

## Parent

Spec: [docs/backlog_items/064-laporan-kendala-lapangan/spec.md](../spec.md)

## What to build

Irisan ujung ke ujung yang paling tipis: migrasi `laporan_kendala` + `laporan_kendala_dibaca`, migrasi hak role
`koordinator` (Lihat/Tambah/Ubah) pada slug `laporan_kendala`, entri sidebar di `config/marketama.php`, route khusus
sebelum catch-all, `LaporanKendalaController@index` yang membatasi daftar dengan `Akses::batasiKaryawan` terhadap
karyawan pelapor, dan halaman `Pages/LaporanKendala/Index.vue` memakai `DataTable` dengan filter status (bawaan
Terbuka), kategori, depo, rentang tanggal, dan pencarian. Kategori dan label status di satu kelas pendukung.

## Acceptance criteria

- [x] Menu Laporan Kendala tampil di sidebar untuk role yang punya hak Lihat, tersembunyi untuk yang tidak.
- [x] Koordinator hanya melihat laporannya dan laporan downline; akun cakupan depo hanya depo-nya; Super Admin semua.
- [x] Filter status/kategori/depo/tanggal dan pencarian bekerja; badge status & kategori lewat class komponen.
- [x] Feature test: cakupan downline/depo/semua dan akun tanpa hak (403).

## Blocked by

None - can start immediately
