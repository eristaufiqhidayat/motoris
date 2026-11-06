# Periode dan pengaturan tanggal penggajian

## What to build

Super Admin mengatur tanggal cut off gaji, tanggal bayar gaji, dan tanggal bayar insentif dari satu halaman (cut off
insentif tetap akhir bulan). Ketiganya punya tanggal mulai
berlaku dan riwayat. Sistem bisa menentukan periode gaji, periode insentif, dan hari kerja, yang menjadi dasar semua
perhitungan berikutnya.

## Acceptance criteria

- [x] Awalnya cut off bernilai 20, sehingga 25 Oktober berada di periode gaji 20 Oktober–19 November, berlabel November.
- [x] Awalnya tanggal bayar gaji bernilai 25 pada bulan label periode, sehingga periode November dibayar 25 November.
- [x] Awalnya tanggal bayar insentif bernilai 15, sehingga insentif November dibayar 15 Desember.
- [x] Super Admin bisa mengubah ketiganya dengan nilai 1–28 dan tanggal berlaku hari ini atau sesudahnya.
- [x] Nilai di luar 1–28 dan tanggal berlaku lampau ditolak dengan pesan yang jelas.
- [x] Pengaturan bertanggal masa depan belum memengaruhi periode berjalan.
- [x] Tanggal bayar gaji tidak boleh lebih awal dari tanggal cut off gaji (dicek juga terhadap pengaturan yang sudah terjadwal), dan cut off tanggal 1 ditolak.
- [x] Hari kerja dihitung Senin–Sabtu dikurangi hari libur.
- [x] Halaman menampilkan riwayat perubahan (nilai, tanggal berlaku, pengubah).
- [x] Selain Super Admin tidak bisa membuka halaman ini.

## Implementation steps

- [x] Buat migration `pengaturan_tanggal_penggajian` dengan baris awal 20, 25, dan 15.
- [x] Buat `App\Support\Penggajian\PeriodeGaji`.
- [x] Tambah slug `pengaturan_penggajian` di `config/marketama.php` dan `Akses::MENU_ADMIN`.
- [x] Buat controller, route sebelum catch-all, dan halaman di `Pages/Penggajian/`.
- [x] Catat perubahan lewat `Akses::catat` dan masukkan tabel baru ke `DaftarTabel::ENESIS`.
- [x] Tambah unit test `PeriodeGaji` dan feature test halaman, lalu jalankan `php artisan test --filter=Penggajian` dan
      `vendor/bin/pint`.

## Blocked by

Tidak ada.
