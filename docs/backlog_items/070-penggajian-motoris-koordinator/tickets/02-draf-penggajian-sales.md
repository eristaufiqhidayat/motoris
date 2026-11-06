# Draf gaji sales

## What to build

Super Admin membuat penggajian gaji sales untuk satu depo dan periode. Sistem menghitung draf per motoris dari gaji dan
tunjangan karyawan serta absensi. Halaman Penggajian Sales menampilkan daftar penggajian baru, bukan tabel impor, dan
admin bisa melihat rincian tiap motoris.

## Acceptance criteria

- [x] Admin bisa mengisi tunjangan jabatan di form karyawan.
- [x] Halaman `/penggajian_sales` menampilkan penggajian baru dengan filter periode, depo, dan status.
- [x] Membuat penggajian menghasilkan satu detail per motoris aktif di depo itu.
- [x] Gaji pokok, tunjangan internet, dan tunjangan jabatan masuk penuh.
- [x] Tunjangan BBM dan makan serta sewa motor dihitung prorata dari (hari hadir + hari izin) ÷ hari kerja.
- [x] Absensi di luar periode tidak ikut dihitung.
- [x] Detail menampilkan hari kerja, hadir, dan terlambat.
- [x] Setiap komponen otomatis menyimpan asal angkanya (nilai bulanan dan jumlah hari).
- [x] Membuat penggajian gaji yang tumpang tindih untuk motoris yang sama ditolak dengan pesan yang menyebut penggajian
      lain.
- [x] Depo di luar cakupan akun ditolak.

## Implementation steps

- [x] Buat migration `penggajian`, `penggajian_detail`, `penggajian_komponen`, dan `mk_karyawan.tunjangan_jabatan`.
- [x] Tambah `tunjangan_jabatan` ke `KaryawanController::GAJI`, `FormKaryawan.vue`, dan `ReviewKaryawan.vue`.
- [x] Buat `App\Support\Penggajian\HitungGaji` untuk gaji sales.
- [x] Buat `App\Support\Penggajian\Penggajian::buat` dengan pemeriksaan tumpang tindih per kategori di dalam transaksi.
- [x] Buat `PenggajianController` dan route sebelum catch-all.
- [x] Buat halaman daftar, form Tambah, dan detail di `Pages/Penggajian/`.
- [x] Masukkan tabel baru ke `DaftarTabel::ENESIS`.
- [x] Tambah test `HitungGaji` dan feature test, lalu jalankan `php artisan test --filter=Penggajian` dan
      `vendor/bin/pint`. (suite penuh: 4 test SSR di AutoGridTest/GenerateKelurahanTest gagal karena dev server, tidak terkait tiket ini)

## Blocked by

- `01-periode-dan-cutoff.md`
