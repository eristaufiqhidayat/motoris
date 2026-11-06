# Periode dan pengaturan tanggal penggajian

## What to build

Super Admin mengatur tanggal cut off gaji dan tanggal bayar insentif dari satu halaman. Keduanya punya tanggal mulai
berlaku dan riwayat. Sistem bisa menentukan periode gaji, periode insentif, dan hari kerja, yang menjadi dasar semua
perhitungan berikutnya.

## Acceptance criteria

- [ ] Awalnya cut off bernilai 20, sehingga 25 Oktober berada di periode gaji 20 Oktober–19 November, berlabel November.
- [ ] Awalnya tanggal bayar insentif bernilai 15, sehingga insentif November dibayar 15 Desember.
- [ ] Super Admin bisa mengubah keduanya dengan nilai 1–28 dan tanggal berlaku hari ini atau sesudahnya.
- [ ] Nilai di luar 1–28 dan tanggal berlaku lampau ditolak dengan pesan yang jelas.
- [ ] Pengaturan bertanggal masa depan belum memengaruhi periode berjalan.
- [ ] Hari kerja dihitung Senin–Sabtu dikurangi hari libur.
- [ ] Halaman menampilkan riwayat perubahan (nilai, tanggal berlaku, pengubah).
- [ ] Selain Super Admin tidak bisa membuka halaman ini.

## Implementation steps

- [ ] Buat migration `pengaturan_tanggal_penggajian` dengan baris awal 20 dan 15.
- [ ] Buat `App\Support\Penggajian\PeriodeGaji`.
- [ ] Tambah slug `pengaturan_penggajian` di `config/marketama.php` dan `Akses::MENU_ADMIN`.
- [ ] Buat controller, route sebelum catch-all, dan halaman di `Pages/Penggajian/`.
- [ ] Catat perubahan lewat `Akses::catat` dan masukkan tabel baru ke `DaftarTabel::ENESIS`.
- [ ] Tambah unit test `PeriodeGaji` dan feature test halaman, lalu jalankan `php artisan test --filter=Penggajian` dan
      `vendor/bin/pint`.

## Blocked by

Tidak ada.
