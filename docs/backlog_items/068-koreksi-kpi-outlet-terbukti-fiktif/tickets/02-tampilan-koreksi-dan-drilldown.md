# Tampilan Koreksi dan Drill-down

## Parent

Spec: [docs/backlog_items/068-koreksi-kpi-outlet-terbukti-fiktif/spec.md](../spec.md)

## What to build

Keterangan "N EC dikoreksi, outlet terbukti fiktif" pada kartu Effective Call Beranda admin dan baris depo Summary All
Depo bila `ec_dikoreksi > 0`. Drill-down penjualan depo memberi lencana "Outlet fiktif" pada baris di outlet Terbukti
dan tidak memasukkannya ke total. InfoPopover Beranda diperbarui. Memakai komponen `Badge` dan `StatCard` yang ada.

## Acceptance criteria

- [ ] Keterangan tampil hanya bila ada koreksi.
- [ ] Baris penjualan di outlet fiktif berlencana dan tidak masuk total.
- [ ] Spec Vitest `TabRingkasan` / Summary All Depo.

## Blocked by

- 01-koreksi-summary-depo.md
