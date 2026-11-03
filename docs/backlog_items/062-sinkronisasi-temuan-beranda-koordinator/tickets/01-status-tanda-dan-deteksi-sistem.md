# Status tanda dan deteksi sistem

## Parent

Spec: [docs/backlog_items/062-sinkronisasi-temuan-beranda-koordinator/spec.md](../spec.md)

## What to build

Migrasi status/hasil cek di `tanda_motoris` & `tanda_outlet` (+ `kelompok`, `skor`) dan `pemeriksaan_motoris`;
`App\Support\Tracking\SinkronTemuan` (lokasi palsu per motoris-hari, titik outlet berkoordinat identik dengan skor dan
batas antrean); `POST /home/sinkronisasi-temuan`.

## Acceptance criteria

- [x] Mock location → tanda motoris Perlu dicek; indikasi tanpa mock tidak ditandai.
- [x] Titik ≥ 3 outlet identik dari pendaftar sama, ada transaksi, dikunjungi tim → tanda outlet per outlet, satu kelompok.
- [x] Antrean maks. 10 titik Perlu dicek per tim, urut skor.
- [x] Tidak membuat ulang tanda yang sudah ada (status apa pun); hari lengkap dilewati.
- [x] Feature test.

## Blocked by

None
