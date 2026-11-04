# Detail laporan dan Tandai Selesai

## Parent

Spec: [docs/backlog_items/064-laporan-kendala-lapangan/spec.md](../spec.md)

## What to build

Klik baris membuka detail (drawer/modal): isi lengkap, status, catatan penyelesaian. Untuk pelapor dan status Terbuka:
tombol **Ubah** (form dari tiket 02) dan **Tandai Selesai** yang meminta catatan penyelesaian wajib, lalu mengisi
`status = selesai`, `catatan_selesai`, `selesai_at`. Laporan Selesai tampil read-only.

## Acceptance criteria

- [x] Pelapor menutup laporan Terbuka dengan catatan; tanpa catatan ditolak.
- [x] Laporan Selesai tidak bisa diubah atau ditutup ulang; tombol tidak tampil.
- [x] Supervisor/Admin melihat detail tanpa tombol Ubah/Tandai Selesai.
- [x] Feature test selesai (pelapor, bukan pelapor, sudah selesai).

## Blocked by

- 02-buat-ubah-laporan.md
