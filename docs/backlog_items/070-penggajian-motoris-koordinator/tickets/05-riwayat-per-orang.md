# Riwayat gaji dan insentif per orang

## What to build

Super Admin mencari satu motoris dan melihat gaji, insentif, dan potongan yang diterimanya per periode, dari penggajian
yang sudah final.

## Acceptance criteria

- [x] Pencarian nama menampilkan semua periode orang itu, dengan kategori, total pendapatan, total potongan, dan total
      bersih.
- [x] Baris bisa dibuka untuk melihat rincian komponen.
- [x] Draf dan penggajian batal tidak ikut tampil.
- [x] Hanya depo dalam cakupan akun yang tampil.

## Implementation steps

- [x] Tambah query riwayat dari `penggajian_detail` yang join ke header.
- [x] Tambah tab atau tampilan riwayat di `Pages/Penggajian/`, memakai `DataTable`.
- [x] Pakai ulang drawer rincian dari halaman detail.
- [x] Tambah feature test, lalu jalankan `php artisan test --filter=Penggajian` dan `vendor/bin/pint`. (suite penuh: 4 test SSR di AutoGridTest/GenerateKelurahanTest gagal karena dev server, tidak terkait tiket ini)

## Blocked by

- `04-penggajian-insentif-sales.md`
