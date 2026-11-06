# Isian manual dan finalisasi

## What to build

Super Admin menyelesaikan draf: mengisi hari izin, menambah komponen manual seperti potongan absen, kasbon, dan BPJS,
menghitung ulang setelah data diperbaiki, lalu memfinalkan. Penggajian final terkunci, jadi perubahan data karyawan atau
rumus tidak mengubah angkanya. Draf yang salah bisa dibatalkan.

## Acceptance criteria

- [x] Admin bisa mengisi hari izin, dan tunjangan prorata ikut berubah.
- [x] Admin bisa menambah, mengubah, dan menghapus komponen manual di draf, dan total ikut berubah.
- [x] Jenis potongan dipilih dari Parameter Pemotong Gaji.
- [x] Hitung Ulang memperbarui komponen otomatis dan mempertahankan komponen manual serta hari izin.
- [x] Halaman detail menampilkan kekurangan data, seperti gaji pokok kosong.
- [x] Finalkan ditolak selama ada kekurangan data.
- [x] Finalkan menampilkan peringatan bila periode belum selesai.
- [x] Penggajian final tidak bisa diubah, dihitung ulang, atau dibatalkan.
- [x] Mengubah gaji karyawan atau rumus setelah final tidak mengubah rincian.
- [x] Draf yang dibatalkan tidak menghalangi penggajian baru untuk periode yang sama.

## Implementation steps

- [x] Tambah `hitungUlang`, `finalkan`, `batalkan`, pengisian hari izin, dan pengelolaan komponen manual di `Penggajian`.
- [x] Tambah daftar kekurangan data.
- [x] Tambah aksi di controller dan route.
- [x] Tambah form komponen manual, isian hari izin, dan tombol di halaman detail.
- [x] Catat finalisasi dan pembatalan lewat `Akses::catat`.
- [x] Tambah feature test, lalu jalankan `php artisan test --filter=Penggajian` dan `vendor/bin/pint`. (suite penuh: 4 test SSR di AutoGridTest/GenerateKelurahanTest gagal karena dev server, tidak terkait tiket ini)

## Blocked by

- `02-draf-penggajian-sales.md`
