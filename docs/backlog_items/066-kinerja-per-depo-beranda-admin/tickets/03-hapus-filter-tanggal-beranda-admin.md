# Hapus filter tanggal Beranda admin, semua bagian 30 hari

## Parent

Spec: [docs/backlog_items/066-kinerja-per-depo-beranda-admin/spec.md](../spec.md)

## What to build

Beranda admin tidak lagi punya filter tanggal: `HomeController::index` memakai periode tetap 30 hari terakhir
(`periodeBeranda`) untuk KPI, Achievement, Funnel, Tren, Depo Teratas, dan perbandingan; prop `filter` dihapus dan
`date_from`/`date_to` diabaikan. Kartu Total EC dan Total Call dihapus (sudah ada di kartu perbandingan). Periode
sekarang di kartu perbandingan memakai ulang cache `ringkasanDepo`. InfoPopover menjelaskan periode tetap dan
mengarahkan ke Summary All Depo untuk rentang lain.

## Acceptance criteria

- [x] Header Beranda admin tanpa DatePicker/Tampilkan; subjudul menampilkan periode 30 hari.
- [x] `date_from`/`date_to` di URL diabaikan; Tren Harian berisi 30 titik.
- [x] Kartu Total EC dan Total Call tidak ada lagi; baris kartu: Laporan Kendala, Motoris Aktif, Depo Aktif, SK Aktif.
- [x] Beranda koordinator tidak berubah.
- [ ] Tampilan dicek di browser. (belum dicek)

## Blocked by

- [01-prop-perbandingan-nasional.md](01-prop-perbandingan-nasional.md)
