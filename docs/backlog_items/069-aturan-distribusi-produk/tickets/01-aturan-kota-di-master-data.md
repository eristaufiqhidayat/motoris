# Aturan distribusi produk per kota di Master Data

## What to build

Admin membuka Master Data › Distribusi Produk, memilih kota (City Group) dan tanggal mulai berlaku, lalu mencentang
produk yang boleh didistribusikan. Aturan tersimpan per kota dan bisa dibaca lewat modul `ProdukDistribusi`, sehingga
semua depo di kota itu mengikutinya. Depo yang kotanya belum diatur tidak dibatasi.

Kunci kota adalah `mk_depo.city_group_id`. Lihat bagian Keputusan di [spec](../spec.md).

## Acceptance criteria

- [ ] Halaman `distribusi_produk` tampil di Master Data dan dibatasi hak `distribusi_produk`.
- [ ] Simpan aturan kota butuh hak `ubah`; tanggal berlaku sebelum hari ini ditolak.
- [ ] Aturan kota ditolak bila ada depo kota itu di luar cakupan akun.
- [ ] Mengubah aturan kota menambah baris bertanggal; baris lama tidak ditimpa, kecuali tanggal berlakunya sama.
- [ ] Hanya produk yang nilainya berubah yang menambah baris.
- [ ] `ProdukDistribusi::untukDepo()` mengembalikan produk kota pada tanggal itu, dan penanda "belum diatur" bila kota
      belum punya baris aturan yang berlaku.
- [ ] Aturan dengan tanggal berlaku di masa depan belum berpengaruh pada tanggal sebelumnya.
- [ ] Perubahan tercatat lewat `Akses::catat`.
- [ ] Kota dengan semua produk dilarang menampilkan peringatan sebelum simpan.
- [ ] `master_produk_distribusi` masuk daftar backup Enesis.

## Implementation steps

- [ ] Buat migration `master_produk_distribusi` sesuai Rincian di spec.
- [ ] Buat modul `App\Support\Distribusi\ProdukDistribusi` untuk baca dan simpan aturan lingkup kota.
- [ ] Daftarkan menu, halaman, dan hak `distribusi_produk` di `config/marketama.php`, mengikuti `kuota_motoris`.
- [ ] Buat `DistribusiProdukController` beserta route.
- [ ] Buat halaman Vue dari komponen `ui`: pilih kota, tanggal berlaku, daftar produk dengan pencarian dan centang.
- [ ] Tambahkan tabel ke `App\Support\Backup\DaftarTabel`.
- [ ] Buat unit test modul dan feature test halaman, lalu jalankan `php artisan test --filter=Distribusi` dan
      `vendor/bin/pint`.

## Blocked by

None (can start immediately).
