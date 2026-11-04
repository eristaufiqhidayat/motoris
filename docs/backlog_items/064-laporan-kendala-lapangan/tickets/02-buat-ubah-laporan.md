# Buat dan ubah laporan kendala

## Parent

Spec: [docs/backlog_items/064-laporan-kendala-lapangan/spec.md](../spec.md)

## What to build

Tombol **Buat Laporan** (hak Tambah) membuka Modal form: kategori, tanggal kejadian (bawaan hari ini, tidak boleh
melewati hari ini), judul, uraian. `store` menyimpan `user_id`, `karyawan_id`, `pelapor_nama`, `depo_id` dari akun
login; ditolak bila akun tidak punya `karyawan_id`. `update` (hak Ubah) hanya untuk pelapor sendiri dan hanya selama
status Terbuka.

## Acceptance criteria

- [x] Koordinator membuat laporan dan laporan muncul di daftar dengan status Terbuka.
- [x] Validasi: kategori dari daftar, tanggal ≤ hari ini, judul ≤ 120, uraian wajib; pesan error per field.
- [x] Akun tanpa `karyawan_id` ditolak dengan pesan yang jelas.
- [x] Mengubah laporan orang lain atau laporan Selesai ditolak (403/422).
- [x] Feature test store/update termasuk kasus penolakan.

## Blocked by

- 01-tabel-menu-daftar-laporan.md
