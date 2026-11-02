# Daftar transaksi dan produk terlaris

## Parent

Spec: [docs/backlog_items/060-detail-motoris-lacak-motoris/spec.md](../spec.md)

## What to build

Tab Transaksi (historis penjualan motoris pada periode) dan tab Produk (10 produk terlaris) di modal Detail Motoris.

## Acceptance criteria

- [x] `transaksi`: maks. 500 terbaru (kode, waktu lokal, outlet, SKU, qty, total, diskon, status Batal/Sah) plus
      `transaksi_total`; info "menampilkan N dari M" bila terpotong.
- [x] `produk`: 10 produk dengan qty terbanyak dari penjualan tidak batal (nama, qty, nilai).

## Blocked by

- [02-ringkasan-dan-harian-per-periode.md](02-ringkasan-dan-harian-per-periode.md)
