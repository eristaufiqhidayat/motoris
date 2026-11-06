# Penggajian koordinator

## What to build

Alur penggajian gaji dan insentif yang sama tersedia untuk koordinator di halaman Penggajian Koordinator, dengan insentif
dari Rumus Insentif Koordinator.

## Acceptance criteria

- [ ] Halaman `/penggajian_koordinator` menampilkan penggajian berjenis `koordinator`, dengan tab Gaji dan Insentif.
- [ ] Draf, isian manual, finalisasi, dan riwayat berjalan sama seperti sales.
- [ ] Insentif koordinator dihitung dari `rumus_insentif_koordinator` dan menyimpan asal angkanya.
- [ ] Penggajian sales dan koordinator tidak saling tampil.

## Implementation steps

- [ ] Pastikan pertanyaan terbuka 2 di spec sudah dijawab.
- [ ] Tambah perhitungan insentif koordinator di `Insentif`.
- [ ] Tambah rumus koordinator di `HitungGaji`.
- [ ] Tambah `jenis` pada route dan controller, memakai halaman yang sama.
- [ ] Tambah test, lalu jalankan `php artisan test --filter=Penggajian` dan `vendor/bin/pint`.

## Blocked by

- `05-riwayat-per-orang.md`
