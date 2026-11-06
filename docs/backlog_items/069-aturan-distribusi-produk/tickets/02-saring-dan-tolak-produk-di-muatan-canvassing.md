# Saring dan tolak produk di muatan canvassing

## What to build

Saat menyiapkan atau mengubah muatan canvassing, koordinator atau staf depo hanya melihat produk yang diizinkan untuk
depo dan tanggal muatan. Server menolak produk lain dengan pesan yang jelas. Produk yang sudah ada di muatan Disiapkan
tidak terganggu oleh perubahan aturan, dan depo yang belum diatur bekerja seperti sekarang.

## Acceptance criteria

- [ ] Opsi form muatan menerima tanggal dan hanya mengembalikan produk yang diizinkan pada tanggal itu.
- [ ] Mengganti depo atau tanggal di form memuat ulang daftar produk.
- [ ] Simpan muatan baru dengan produk terlarang ditolak, pesannya pada item yang bersangkutan.
- [ ] Ubah item memakai depo dan tanggal muatan, bukan hari ini.
- [ ] Ubah item menerima produk lama yang kini dilarang (ubah qty atau hapus), dan menolak produk baru yang dilarang.
- [ ] Depo yang belum diatur tetap bisa memilih semua produk.

## Implementation steps

- [ ] Saring opsi produk di `BarangCanvassingController` lewat `ProdukDistribusi`.
- [ ] Tambahkan pemeriksaan produk pada simpan muatan baru.
- [ ] Tambahkan pemeriksaan pada ubah item, hanya untuk produk yang belum ada di muatan.
- [ ] Sesuaikan form muatan supaya mengirim tanggal dan memuat ulang opsi.
- [ ] Sesuaikan form ubah item di drawer bila daftar produknya memakai opsi yang sama.
- [ ] Tambah feature test mengikuti `BarangCanvassingTambahTest` dan `BarangCanvassingUbahItemTest`, lalu jalankan
      `php artisan test --filter=BarangCanvassing` dan `vendor/bin/pint`.

## Blocked by

- `01-aturan-kota-di-master-data.md`
