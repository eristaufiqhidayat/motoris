# Ringkasan kinerja dan rekap harian per periode

## Parent

Spec: [docs/backlog_items/060-detail-motoris-lacak-motoris/spec.md](../spec.md)

## What to build

Pemilih periode (dari–sampai, bawaan awal bulan ini s.d. hari ini) di modal Detail Motoris, kartu KPI ringkasan, dan
tab Harian (grafik penjualan + tabel per tanggal), dengan definisi angka yang sama dengan Motoris Report.

## Acceptance criteria

- [x] `ringkasan`: hari kerja standar, hari hadir, terlambat, kunjungan, EC, transaksi, total penjualan,
      total qty, rata-rata penjualan per hari hadir — transaksi batal tidak dihitung.
- [x] `harian`: satu baris per tanggal yang punya absensi/kunjungan/penjualan (jam masuk lokal, kunjungan, EC,
      transaksi, penjualan), urut tanggal.
- [x] Periode tanpa parameter = awal bulan berjalan s.d. hari ini; `sampai` < `dari` atau rentang > 366 hari → 422.
- [x] Mengubah periode lalu klik Tampilkan mengambil ulang data.

## Blocked by

- [01-modal-detail-motoris-dengan-profil.md](01-modal-detail-motoris-dengan-profil.md)
