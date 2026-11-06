# Penggajian insentif sales

## What to build

Super Admin membuat penggajian insentif sales untuk satu depo dan bulan kalender, terpisah dari gaji, dengan tanggal bayar
dari pengaturan. Draf, komponen manual, dan finalisasi berjalan sama seperti penggajian gaji.

## Acceptance criteria

- [ ] Halaman Penggajian Sales punya tab Gaji dan Insentif.
- [ ] Penggajian insentif November memakai periode 1–30 November dan tanggal bayar 15 Desember.
- [ ] Tanggal bayar tersimpan di penggajian, jadi perubahan pengaturan tidak mengubah penggajian yang sudah dibuat.
- [ ] Rincian tiap motoris memuat insentif EC dan nilai poin, dengan total EC, tingkat rumus, dan poin sebagai asal angka.
- [ ] Angka insentif EC sama dengan Lap. Insentif Sales pada bulan yang sama.
- [ ] Penggajian gaji dan insentif pada periode yang bertumpuk tidak saling menghalangi.
- [ ] Dua penggajian insentif yang tumpang tindih untuk motoris yang sama ditolak.
- [ ] Draf bisa ditambah komponen manual, dihitung ulang, difinalkan, dan dibatalkan.

## Implementation steps

- [ ] Tambah rumus insentif sales di `HitungGaji`, memakai `Insentif::ec` dan `Insentif::nilaiPoin`.
- [ ] Tambah kategori `insentif` di `Penggajian::buat` dan controller.
- [ ] Tambah tab dan form Tambah insentif di `Pages/Penggajian/`.
- [ ] Sembunyikan hari kerja, hadir, izin, dan terlambat di detail insentif.
- [ ] Tambah test, lalu jalankan `php artisan test --filter=Penggajian` dan `vendor/bin/pint`.

## Blocked by

- `03-komponen-manual-dan-finalisasi.md`
