# Penggajian insentif sales

## What to build

Super Admin membuat penggajian insentif sales untuk satu depo dan bulan kalender, terpisah dari gaji, dengan tanggal bayar
dari pengaturan. Draf, komponen manual, dan finalisasi berjalan sama seperti penggajian gaji.

## Acceptance criteria

- [x] Halaman Penggajian Sales punya tab Gaji dan Insentif.
- [x] Penggajian insentif November memakai periode 1–30 November dan tanggal bayar 15 Desember.
- [x] Tanggal bayar tersimpan di penggajian, jadi perubahan pengaturan tidak mengubah penggajian yang sudah dibuat.
- [x] Rincian tiap motoris memuat insentif EC dan nilai poin, dengan total EC, tingkat rumus, dan poin sebagai asal angka.
- [x] Angka insentif EC sama dengan Lap. Insentif Sales pada bulan yang sama.
- [x] Penggajian gaji dan insentif pada periode yang bertumpuk tidak saling menghalangi.
- [x] Dua penggajian insentif yang tumpang tindih untuk motoris yang sama ditolak.
- [x] Draf bisa ditambah komponen manual, dihitung ulang, difinalkan, dan dibatalkan.

## Implementation steps

- [x] Tambah rumus insentif sales di `HitungGaji`, memakai `Insentif::ec` dan `Insentif::nilaiPoin`.
- [x] Tambah kategori `insentif` di `Penggajian::buat` dan controller.
- [x] Tambah tab dan form Tambah insentif di `Pages/Penggajian/`.
- [x] Sembunyikan hari kerja, hadir, izin, dan terlambat di detail insentif.
- [x] Tambah test, lalu jalankan `php artisan test --filter=Penggajian` dan `vendor/bin/pint`. (suite penuh: 4 test SSR di AutoGridTest/GenerateKelurahanTest gagal karena dev server, tidak terkait tiket ini)

## Blocked by

- `03-komponen-manual-dan-finalisasi.md`
