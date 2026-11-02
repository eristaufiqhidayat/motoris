# Transaksi per tanggal dan produk terlaris

## Parent

Spec: [docs/backlog_items/060-detail-motoris-lacak-motoris/spec.md](../spec.md)

## What to build

Klik satu tanggal di tabel Harian memuat transaksi penjualan tanggal itu (endpoint terpisah, tidak ikut payload utama;
tab Transaksi sebelumnya dihapus karena memuat terlalu banyak data), dan tab Produk Terlaris (10 produk) di modal Detail
Motoris.

## Acceptance criteria

- [x] `GET /tracking_sales/{id}/detail/transaksi?tanggal=` mengembalikan transaksi tanggal itu (kode, jam lokal, outlet,
      SKU, qty, total, diskon, status Batal/Sah), maks. 200; payload utama tidak memuat `transaksi`.
- [x] Klik baris tabel Harian membuka dialog (modal) berisi transaksi tanggal itu; pesan galat bila gagal dimuat.
- [x] `produk`: 10 produk dengan qty terbanyak dari penjualan tidak batal (nama, qty, nilai).

## Blocked by

- [02-ringkasan-dan-harian-per-periode.md](02-ringkasan-dan-harian-per-periode.md)
