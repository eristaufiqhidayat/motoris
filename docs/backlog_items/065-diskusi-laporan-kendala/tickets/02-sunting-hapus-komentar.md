# Sunting dan hapus komentar sendiri

## Parent

Spec: [docs/backlog_items/065-diskusi-laporan-kendala/spec.md](../spec.md)

## What to build

Penulis dapat menyunting (`PUT /laporan_kendala/{id}/komentar/{komentarId}`, mengisi `disunting_at`) dan menghapus
komentarnya sendiri lewat aksi kecil di kartu komentar; hapus memakai konfirmasi. Peristiwa otomatis tidak punya aksi
ini.

## Acceptance criteria

- [x] Komentar milik orang lain tidak bisa disunting/dihapus (ditolak server, aksi tidak tampil).
- [x] Komentar tersunting menampilkan penanda "disunting".
- [x] Hapus komentar menghilangkannya dari linimasa setelah konfirmasi.
- [x] Feature test hak sunting/hapus.

## Blocked by

- 01-komentar-di-detail-laporan.md
