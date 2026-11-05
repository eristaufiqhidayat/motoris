# Tata letak Beranda admin: Penyebaran Produk, Aktivitas, Perlu Perhatian

## Parent

Spec: [docs/backlog_items/066-kinerja-per-depo-beranda-admin/spec.md](../spec.md)

## What to build

Susun ulang `Home/Dashboard.vue` seperti Beranda Koordinator (StatCard per bagian `h6.judul-bagian`, grafik 7/5):
Penyebaran Produk (Outlet Terjangkau, Outlet Baru, Repeat Order, IPT), Aktivitas Motoris (Call dan EC = AVG per hari
kerja + total, Motoris Aktif, Hari Kerja), Perlu Perhatian (Laporan Kendala, Outlet Stok Kosong, Depo Tertinggal,
Motoris Ditandai, bertaut sesuai hak), Tren Harian, Funnel Outlet, dan Depo · Outlet Terjangkau per Motoris (10
terendah). Backend: `Dashboard::penyebaran()`, `Dashboard::outletPerDepo()`, `depoTertinggal`, `tandaMotoris`,
`tautan`, dan perbandingan dua periode yang memuat metrik penyebaran. Partial `Selisih` untuk ▲/▼. Kartu nilai
penjualan, Achievement Nasional terpisah, Depo Teratas, `PerbandinganKinerja`, dan varian `ringkas` `KartuKendala`
dihapus.

## Acceptance criteria

- [x] Outlet terjangkau dan repeat order per periode hanya dari EC yang tidak dibatalkan.
- [x] Periode sekarang memakai ulang cache ringkasanDepo/penyebaran; hanya periode sebelumnya yang dihitung tambahan.
- [x] Kartu Perlu Perhatian bertaut hanya bila akun punya hak Lihat menu tujuan.
- [x] Semua markup kartu memakai `StatCard`/`Card`; warna naik/turun lewat class komponen `.selisih-*` di `app.css`.
- [x] Feature test `HomeTest`, Vitest `Selisih` dan smoke test `Dashboard`.
- [ ] Tampilan dicek di browser (desktop dan sempit). (belum dicek)

## Blocked by

- [03-hapus-filter-tanggal-beranda-admin.md](03-hapus-filter-tanggal-beranda-admin.md)
