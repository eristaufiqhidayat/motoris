# Komentar di detail laporan

## Parent

Spec: [docs/backlog_items/065-diskusi-laporan-kendala/spec.md](../spec.md)

## What to build

Migrasi `laporan_kendala_komentar` dan kolom `aktivitas_at` (diisi `created_at` untuk laporan lama). Endpoint kirim
komentar (`POST /laporan_kendala/{id}/komentar`) untuk akun dengan hak Lihat dan laporan dalam cakupan. Detail laporan
memuat dan menampilkan linimasa (uraian lalu komentar urut waktu) beserta kotak komentar; kirim komentar memperbarui
`aktivitas_at`. Irisan tipis end-to-end: Supervisor membalas, koordinator melihat balasan.

## Acceptance criteria

- [x] Akun di luar cakupan role ditolak; dalam cakupan (hak Lihat saja) berhasil berkomentar.
- [x] Pelapor bisa berkomentar di laporannya sendiri; komentar kosong atau >2000 karakter ditolak dengan pesan.
- [x] Linimasa menampilkan penulis, waktu, dan baris baru komentar dengan benar (teks di-escape).
- [x] Komentar boleh ditulis pada laporan Selesai dan tidak mengubah status.
- [x] Feature test kirim komentar, cakupan, dan validasi.

## Blocked by

- Tidak ada (spec 064 sudah terimplementasi).
