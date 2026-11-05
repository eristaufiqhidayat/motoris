# Koreksi NOO dan Outlet Terjangkau

## Parent

Spec: [docs/backlog_items/067-kualitas-data-outlet-dan-kinerja-koordinator/spec.md](../spec.md)

## What to build

`Dashboard::penyebaran()` dan `outletPerDepo()`: NOO hanya berstatus `disetujui` (plus jumlah `menunggu` sebagai
data terpisah), dan outlet dengan tanda outlet Terbukti tidak dihitung di outlet terjangkau, repeat order, dan outlet
per depo. Kartu Outlet Baru menampilkan jumlah yang masih menunggu di keterangan. InfoPopover diperbarui.

## Acceptance criteria

- [x] NOO menunggu/ditolak tidak dihitung di kartu Outlet Baru; jumlah menunggu tampil di keterangan.
- [x] Outlet dengan tanda Terbukti tidak dihitung di Outlet Terjangkau, Repeat Order, dan grafik per depo.
- [x] Feature test `HomeTest`.

## Blocked by

- Tidak ada.
