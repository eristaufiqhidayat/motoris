# Pengecualian aturan per depo

## What to build

Admin melihat daftar depo di kota terpilih beserta jumlah pengecualiannya, lalu mengizinkan atau melarang produk untuk
satu depo, atau mengembalikan depo ke aturan kota. Depo mengalahkan kota. Depo tanpa City Group tampil sebagai
kelompok "Tanpa kota" dan hanya bisa diatur per depo.

## Acceptance criteria

- [ ] `ProdukDistribusi` memakai urutan depo lalu kota sesuai Keputusan di [spec](../spec.md).
- [ ] Depo bisa mengizinkan produk yang tidak diizinkan kotanya, dan melarang produk yang diizinkan kotanya.
- [ ] Perubahan aturan kota tetap diikuti depo, kecuali produk yang dikecualikan.
- [ ] "Kembalikan ke aturan kota" menambah baris depo dengan `diizinkan` null, bukan menghapus baris.
- [ ] Depo hanya punya aturan sendiri, tanpa aturan kota: produk yang tidak disebut tidak diizinkan.
- [ ] Daftar depo menampilkan lencana "N pengecualian" dan mengikuti cakupan akun.
- [ ] Mengubah depo di luar cakupan ditolak.
- [ ] Depo tanpa City Group tampil di kelompok "Tanpa kota" dengan keterangan aturan kota tidak berlaku.

## Implementation steps

- [ ] Perluas modul `ProdukDistribusi` dengan lingkup depo dan nilai null.
- [ ] Tambah endpoint simpan pengecualian dan kembalikan ke aturan kota.
- [ ] Tambah daftar depo, lencana, dan panel pengecualian di halaman Distribusi Produk.
- [ ] Tambah kelompok "Tanpa kota".
- [ ] Lengkapi unit test modul untuk semua kombinasi dan feature test endpoint, lalu jalankan
      `php artisan test --filter=Distribusi` dan `vendor/bin/pint`.

## Blocked by

- `01-aturan-kota-di-master-data.md`
