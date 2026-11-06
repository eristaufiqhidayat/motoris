# Riwayat gaji dan insentif per orang

## What to build

Super Admin mencari satu motoris dan melihat gaji, insentif, dan potongan yang diterimanya per periode, dari penggajian
yang sudah final.

## Acceptance criteria

- [ ] Pencarian nama menampilkan semua periode orang itu, dengan kategori, total pendapatan, total potongan, dan total
      bersih.
- [ ] Baris bisa dibuka untuk melihat rincian komponen.
- [ ] Draf dan penggajian batal tidak ikut tampil.
- [ ] Hanya depo dalam cakupan akun yang tampil.

## Implementation steps

- [ ] Tambah query riwayat dari `penggajian_detail` yang join ke header.
- [ ] Tambah tab atau tampilan riwayat di `Pages/Penggajian/`, memakai `DataTable`.
- [ ] Pakai ulang drawer rincian dari halaman detail.
- [ ] Tambah feature test, lalu jalankan `php artisan test --filter=Penggajian` dan `vendor/bin/pint`.

## Blocked by

- `04-penggajian-insentif-sales.md`
