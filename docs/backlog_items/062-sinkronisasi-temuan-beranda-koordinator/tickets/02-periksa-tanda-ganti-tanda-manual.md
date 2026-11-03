# Periksa tanda, hapus tanda manual

## Parent

Spec: [docs/backlog_items/062-sinkronisasi-temuan-beranda-koordinator/spec.md](../spec.md)

## What to build

Ganti endpoint tambah/ubah/cabut tanda dengan `PATCH .../tanda/{tanda}/periksa` (Terbukti/Wajar + catatan wajib;
tanda outlet sekelompok). Komponen `PeriksaTanda.vue`; form Tandai dihapus dari `TandaMotoris.vue` / `TandaOutlet.vue`.
Kartu Ditandai menghitung Perlu dicek; rekap menampilkan status.

## Acceptance criteria

- [x] Tidak ada lagi jalan membuat tanda manual.
- [x] Periksa menyimpan hasil, Wajar mengisi `dicabut_at` (lencana hilang), Terbukti tetap berlencana.
- [x] Feature test & Vitest.

## Blocked by

- 01-status-tanda-dan-deteksi-sistem.md
