# Draf gaji sales

## What to build

Super Admin membuat penggajian gaji sales untuk satu depo dan periode. Sistem menghitung draf per motoris dari gaji dan
tunjangan karyawan serta absensi. Halaman Penggajian Sales menampilkan daftar penggajian baru, bukan tabel impor, dan
admin bisa melihat rincian tiap motoris.

## Acceptance criteria

- [ ] Admin bisa mengisi tunjangan jabatan di form karyawan.
- [ ] Halaman `/penggajian_sales` menampilkan penggajian baru dengan filter periode, depo, dan status.
- [ ] Membuat penggajian menghasilkan satu detail per motoris aktif di depo itu.
- [ ] Gaji pokok, tunjangan internet, dan tunjangan jabatan masuk penuh.
- [ ] Tunjangan BBM dan makan serta sewa motor dihitung prorata dari (hari hadir + hari izin) ÷ hari kerja.
- [ ] Absensi di luar periode tidak ikut dihitung.
- [ ] Detail menampilkan hari kerja, hadir, dan terlambat.
- [ ] Setiap komponen otomatis menyimpan asal angkanya (nilai bulanan dan jumlah hari).
- [ ] Membuat penggajian gaji yang tumpang tindih untuk motoris yang sama ditolak dengan pesan yang menyebut penggajian
      lain.
- [ ] Depo di luar cakupan akun ditolak.

## Implementation steps

- [ ] Buat migration `penggajian`, `penggajian_detail`, `penggajian_komponen`, dan `mk_karyawan.tunjangan_jabatan`.
- [ ] Tambah `tunjangan_jabatan` ke `KaryawanController::GAJI`, `FormKaryawan.vue`, dan `ReviewKaryawan.vue`.
- [ ] Buat `App\Support\Penggajian\HitungGaji` untuk gaji sales.
- [ ] Buat `App\Support\Penggajian\Penggajian::buat` dengan pemeriksaan tumpang tindih per kategori di dalam transaksi.
- [ ] Buat `PenggajianController` dan route sebelum catch-all.
- [ ] Buat halaman daftar, form Tambah, dan detail di `Pages/Penggajian/`.
- [ ] Masukkan tabel baru ke `DaftarTabel::ENESIS`.
- [ ] Tambah test `HitungGaji` dan feature test, lalu jalankan `php artisan test --filter=Penggajian` dan
      `vendor/bin/pint`.

## Blocked by

- `01-periode-dan-cutoff.md`
