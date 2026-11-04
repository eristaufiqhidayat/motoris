# Kartu Beranda dan angka di sidebar

## Parent

Spec: [docs/backlog_items/064-laporan-kendala-lapangan/spec.md](../spec.md)

## What to build

- Beranda Koordinator (`Home/DashboardKoordinator`): kartu "Laporan Kendala" — jumlah Terbuka tim, 5 terbaru, tombol
  Buat Laporan (hak Tambah) dan tautan ke daftar.
- Beranda cakupan semua/depo (`Home/Dashboard`): kartu "Kendala Lapangan" — jumlah Terbuka dan Belum dibaca, 5 terbaru
  belum dibaca yang membuka detail.
- Sidebar: angka Belum dibaca pada entri Laporan Kendala untuk akun selain cakupan downline (shared prop di
  `HandleInertiaRequests`, dihitung ringan / di-cache singkat).

Kartu hanya tampil bila akun punya hak Lihat pada `laporan_kendala`.

## Acceptance criteria

- [x] Kartu tampil sesuai cakupan dan hilang bila tanpa hak Lihat.
- [x] Angka Belum dibaca berkurang setelah detail dibuka.
- [x] Feature test prop Beranda dan shared prop sidebar.

## Blocked by

- 04-status-dibaca.md
