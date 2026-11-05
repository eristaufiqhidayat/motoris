# Bagian Kualitas Data Outlet

## Parent

Spec: [docs/backlog_items/067-kualitas-data-outlet-dan-kinerja-koordinator/spec.md](../spec.md)

## What to build

Metrik per periode (30 hari dan 30 hari sebelumnya) ditambahkan ke prop `perbandingan`: outlet terbukti fiktif dan
rasio fiktif (dari `tanda_outlet` sistem dengan `dicek_at` dalam periode), rasio NOO ditolak (`diverifikasi_pada`
dalam periode), dan NOO menunggu saat ini + umur tertua. Bagian **Kualitas Data Outlet** di Beranda admin dengan 4
`StatCard`. Partial `Selisih` mendapat prop `turunBaik` (turun = hijau), nada kartu mengikuti.

## Acceptance criteria

- [x] Angka per periode sesuai definisi spec; akun cakupan depo hanya menghitung outlet/motoris di depo-nya.
- [x] Turun ditampilkan hijau untuk metrik fiktif dan NOO ditolak.
- [x] Feature test `HomeTest`; Vitest `Selisih` dengan `turunBaik`.
- [ ] Tampilan dicek di browser. (belum dicek)

## Blocked by

- [02-koreksi-noo-dan-outlet-terjangkau.md](02-koreksi-noo-dan-outlet-terjangkau.md)
