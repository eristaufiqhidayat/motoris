# Kartu Perbandingan Kinerja Nasional (frontend)

> Catatan: partial `PerbandinganKinerja` dari tiket ini sudah diganti tata letak baru di [04-tata-letak-penyebaran-produk.md](04-tata-letak-penyebaran-produk.md).

## Parent

Spec: [docs/backlog_items/066-kinerja-per-depo-beranda-admin/spec.md](../spec.md)

## What to build

Partial `Pages/Home/partials/PerbandinganKinerja.vue`: tiga kartu (Total Call, Total Effective Call, IPT) bergaya kartu
statistik Beranda, masing-masing dengan nilai 30 hari terakhir, persen naik/turun vs 30 hari sebelumnya, dan nilai
sebelumnya. Dipasang di `Home/Dashboard.vue` dalam `<Deferred data="perbandingan">` dengan skeleton sendiri. Tambah
butir penjelasan di InfoPopover Beranda.

## Acceptance criteria

- [ ] KPI/funnel/tren tampil tanpa menunggu kartu ini; kartu menampilkan skeleton saat memuat. (belum dicek di browser)
- [x] Naik hijau ▲, turun merah ▼, tetap abu-abu; periode sebelumnya 0 = "Belum ada pembanding".
- [x] Rentang kedua periode tertulis di bawah kartu.
- [x] Hanya komponen UI dari `Components/ui/`; Vitest untuk partial.

## Blocked by

- [01-prop-perbandingan-nasional.md](01-prop-perbandingan-nasional.md)
