# Peristiwa Selesai dan Buka Kembali

## Parent

Spec: [docs/backlog_items/065-diskusi-laporan-kendala/spec.md](../spec.md)

## What to build

Tandai Selesai (spec 064) juga menulis baris `jenis = selesai` ke linimasa. Tambah aksi **Buka Kembali** untuk pelapor
pada laporan Selesai (`PATCH /laporan_kendala/{id}/buka-kembali`, hak Ubah): status kembali `terbuka`, `selesai_at`
dan `catatan_selesai` dikosongkan, baris `dibuka_kembali` (alasan opsional) masuk linimasa. Linimasa menampilkan
peristiwa sebagai garis ikon + teks, berbeda dari kartu komentar.

## Acceptance criteria

- [x] Selesai memunculkan peristiwa dengan catatan penyelesaian di linimasa; daftar dan Beranda tetap memakai `catatan_selesai`.
- [x] Buka Kembali hanya untuk pelapor, hanya saat Selesai; laporan masuk lagi ke hitungan Terbuka di Beranda.
- [x] Catatan penyelesaian lama tetap terlihat di linimasa setelah dibuka kembali.
- [x] Feature test Selesai → Buka Kembali → Selesai.

## Blocked by

- 01-komentar-di-detail-laporan.md
