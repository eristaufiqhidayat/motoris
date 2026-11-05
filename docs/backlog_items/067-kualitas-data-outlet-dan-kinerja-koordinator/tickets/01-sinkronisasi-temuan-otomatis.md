# Sinkronisasi temuan otomatis setiap malam

## Parent

Spec: [docs/backlog_items/067-kualitas-data-outlet-dan-kinerja-koordinator/spec.md](../spec.md)

## What to build

Command `temuan:sinkron [--tanggal=Y-m-d]` (bawaan kemarin, zona `Pengaturan::zona`) yang menjalankan
`SinkronTemuan::jalankan` per tim koordinator aktif (motoris aktif dengan `koordinator_id` itu), plus satu kelompok
untuk motoris aktif tanpa koordinator. Dijadwalkan `dailyAt('01:00')` dengan `withoutOverlapping`. Satu tim gagal
dicatat ke log dan tidak menghentikan tim lain; ringkasan hasil per tim ditulis ke output/log.

## Acceptance criteria

- [x] Tanpa opsi, memeriksa tanggal kemarin untuk semua tim; `--tanggal` memeriksa tanggal itu.
- [x] Motoris tanpa koordinator ikut diperiksa.
- [x] Dijalankan dua kali tidak membuat tanda ganda; antrean titik outlet tetap maks. `antrean_titik` per tim.
- [x] Terdaftar di jadwal (`schedule:list`).
- [x] Feature test command.

## Blocked by

- Tidak ada.
