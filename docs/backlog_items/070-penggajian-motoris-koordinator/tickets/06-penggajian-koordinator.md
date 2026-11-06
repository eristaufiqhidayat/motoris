# Penggajian koordinator

## What to build

Alur penggajian gaji dan insentif yang sama tersedia untuk koordinator di halaman Penggajian Koordinator, dengan insentif
dari Rumus Insentif Koordinator.

## Acceptance criteria

- [x] Halaman `/penggajian_koordinator` menampilkan penggajian berjenis `koordinator`, dengan tab Gaji dan Insentif.
- [x] Draf, isian manual, finalisasi, dan riwayat berjalan sama seperti sales.
- [ ] Insentif koordinator dihitung dari `rumus_insentif_koordinator` dan menyimpan asal angkanya.
- [x] Penggajian sales dan koordinator tidak saling tampil.

## Implementation steps

- [ ] Pastikan pertanyaan terbuka 2 di spec sudah dijawab. (belum dijawab di issue #100, pertanyaan 21; dilanjutkan tanpa insentif koordinator atas keputusan pengguna)
- [ ] Tambah perhitungan insentif koordinator di `Insentif`. (ditunda: menunggu aturan tim bukan 10 sales; insentif koordinator ditolak dengan pesan jelas)
- [x] Tambah rumus koordinator di `HitungGaji`. (hanya gaji koordinator; insentif menunggu aturan di atas)
- [x] Tambah `jenis` pada route dan controller, memakai halaman yang sama.
- [x] Tambah test, lalu jalankan `php artisan test --filter=Penggajian` dan `vendor/bin/pint`. (suite penuh: 4 test SSR di AutoGridTest/GenerateKelurahanTest gagal karena dev server, tidak terkait tiket ini)

## Blocked by

- `05-riwayat-per-orang.md`
