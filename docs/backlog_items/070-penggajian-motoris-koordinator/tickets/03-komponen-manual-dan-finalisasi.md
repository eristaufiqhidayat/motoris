# Isian manual dan finalisasi

## What to build

Super Admin menyelesaikan draf: mengisi hari izin, menambah komponen manual seperti potongan absen, kasbon, dan BPJS,
menghitung ulang setelah data diperbaiki, lalu memfinalkan. Penggajian final terkunci, jadi perubahan data karyawan atau
rumus tidak mengubah angkanya. Draf yang salah bisa dibatalkan.

## Acceptance criteria

- [ ] Admin bisa mengisi hari izin, dan tunjangan prorata ikut berubah.
- [ ] Admin bisa menambah, mengubah, dan menghapus komponen manual di draf, dan total ikut berubah.
- [ ] Jenis potongan dipilih dari Parameter Pemotong Gaji.
- [ ] Hitung Ulang memperbarui komponen otomatis dan mempertahankan komponen manual serta hari izin.
- [ ] Halaman detail menampilkan kekurangan data, seperti gaji pokok kosong.
- [ ] Finalkan ditolak selama ada kekurangan data.
- [ ] Finalkan menampilkan peringatan bila periode belum selesai.
- [ ] Penggajian final tidak bisa diubah, dihitung ulang, atau dibatalkan.
- [ ] Mengubah gaji karyawan atau rumus setelah final tidak mengubah rincian.
- [ ] Draf yang dibatalkan tidak menghalangi penggajian baru untuk periode yang sama.

## Implementation steps

- [ ] Tambah `hitungUlang`, `finalkan`, `batalkan`, pengisian hari izin, dan pengelolaan komponen manual di `Penggajian`.
- [ ] Tambah daftar kekurangan data.
- [ ] Tambah aksi di controller dan route.
- [ ] Tambah form komponen manual, isian hari izin, dan tombol di halaman detail.
- [ ] Catat finalisasi dan pembatalan lewat `Akses::catat`.
- [ ] Tambah feature test, lalu jalankan `php artisan test --filter=Penggajian` dan `vendor/bin/pint`.

## Blocked by

- `02-draf-penggajian-sales.md`
