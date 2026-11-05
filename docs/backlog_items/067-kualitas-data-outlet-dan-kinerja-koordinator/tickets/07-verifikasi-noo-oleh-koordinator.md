# Verifikasi NOO oleh koordinator

## Parent

Spec: [docs/backlog_items/067-kualitas-data-outlet-dan-kinerja-koordinator/spec.md](../spec.md)

## What to build

Koordinator, bukan admin, yang menyetujui/menolak NOO motoris timnya. Hak Ubah `outlet_all` untuk role bawaan Koordinator
(`RoleBawaan` + migrasi), Super Admin ditolak 403 dan tombol disembunyikan di tab Outlet Baru dari HP. Metrik kecepatan
verifikasi per koordinator (median hari, menunggu lebih dari 3 hari) ditambahkan ke `KinerjaKoordinator` dan bagian Kinerja
Koordinator di Beranda admin.

## Acceptance criteria

- [x] Koordinator bisa menyetujui/menolak NOO motoris timnya dan ditolak (403) untuk motoris tim lain.
- [x] Super Admin ditolak 403 dan tidak melihat tombol Setujui/Tolak.
- [x] Role Koordinator yang sudah ada mendapat hak lewat migrasi.
- [x] Median hari verifikasi dan jumlah menunggu lama benar per koordinator dan nasional (feature test).
- [x] Kartu Verifikasi NOO dan kolom tabel tampil (Vitest).
- [ ] Alur dicek di browser (koordinator memverifikasi dari tab Outlet Baru dari HP). (belum dicek)

## Blocked by

- [04-metrik-tindak-lanjut-temuan-koordinator.md](04-metrik-tindak-lanjut-temuan-koordinator.md)
