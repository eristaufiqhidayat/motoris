# Jam pulang, persen terhadap target, dan tab Ditandai

## Parent

Spec: [docs/backlog_items/060-detail-motoris-lacak-motoris/spec.md](../spec.md)

## What to build

Tab Harian menambah kolom Jam Pulang; Call dan EC (ringkasan dan per hari) menampilkan persen terhadap target depo; tab
baru Ditandai memuat tanda motoris pada periode dan tanda outlet pada outlet yang didaftarkan motoris ini.

## Acceptance criteria

- [x] `harian[].pulang` (jam lokal, null bila belum pulang) dan kolom Jam Pulang di tabel Harian.
- [x] `target` (call/ec per hari) dan `ringkasan.target_call/target_ec` (× hari kerja standar); persen tampil di KPI
      Call dan EC serta di sel Kunjungan dan EC tiap hari.
- [x] `tanda_motoris` (dalam periode, termasuk yang dicabut) dan `tanda_outlet` (aktif, outlet didaftarkan motoris ini)
      tampil di tab Ditandai dengan jumlah tanda aktif di label tab.

## Blocked by

- [03-daftar-transaksi-dan-produk-terlaris.md](03-daftar-transaksi-dan-produk-terlaris.md)
