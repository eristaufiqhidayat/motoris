# Laporan Kendala Lapangan

GitHub issue: [#177](https://github.com/eristaufiqhidayat/enesis/issues/177)

## Masalah

Kendala lapangan tidak bisa dihindari. Contoh: stok barang canvassing di depo habis, sehingga motoris tidak bisa
di-loading dan koordinator tidak bisa menjalankan operasional tim. Kinerja depo turun, lalu Supervisor dan Admin
bertanya kenapa. Saat ini koordinator tidak punya cara mencatat kejadian itu di sistem, jadi penjelasannya hilang di
chat/telepon dan tidak bisa ditelusuri ketika membaca angka kinerja depo.

## Solusi

Menu baru **Laporan Kendala** (slug `laporan_kendala`). Koordinator membuat laporan kendala (kategori, tanggal kejadian,
judul, uraian). Supervisor dan Admin melihat dan membaca laporan dari koordinator dalam cakupannya, dan sistem mencatat
laporan mana yang sudah dibaca. Koordinator memperbarui laporan selama masih **Terbuka**, lalu menutupnya sebagai
**Selesai** dengan catatan penyelesaian saat masalahnya sudah teratasi.

## User stories

1. Sebagai Koordinator, saya membuat laporan kendala (mis. "Stok canvassing habis di depo") supaya atasan tahu
   penyebab operasional terhambat.
2. Sebagai Koordinator, saya mengubah isi laporan saya selama masih Terbuka (menambah keterangan perkembangan).
3. Sebagai Koordinator, saya menandai laporan Selesai dengan catatan penyelesaian ketika masalah sudah teratasi.
4. Sebagai Koordinator, saya melihat apakah laporan saya sudah dibaca Supervisor/Admin.
5. Sebagai Supervisor/Admin, saya melihat daftar laporan kendala dalam cakupan saya, menyaring per status, kategori,
   depo, dan tanggal, serta mencari teks.
6. Sebagai Supervisor/Admin, saya langsung melihat laporan Terbuka yang belum saya baca (lencana dan kartu di Beranda).

## Data

Tabel `laporan_kendala`:

| Kolom | Isi |
| --- | --- |
| `id` | |
| `user_id` | akun pelapor (`users.id`) |
| `karyawan_id` | karyawan pelapor (`mk_karyawan.id`), dasar pembatasan cakupan |
| `pelapor_nama` | nama saat dibuat (tetap terbaca walau karyawan diubah) |
| `depo_id` | depo pelapor saat dibuat (`mk_karyawan.depo_id`), untuk filter dan rekap per depo |
| `kategori` | lihat daftar di bawah |
| `tanggal_kejadian` | date, tidak boleh melewati hari ini |
| `judul` | string ≤ 120 |
| `uraian` | text, wajib |
| `status` | `terbuka` \| `selesai` |
| `catatan_selesai` | text, wajib saat Selesai |
| `selesai_at` | |
| `timestamps` | `updated_at` = terakhir diperbarui |

Tabel `laporan_kendala_dibaca`: `laporan_kendala_id`, `user_id`, `dibaca_at` (unik per pasangan). Hanya dicatat untuk
pembaca yang **bukan** pelapor.

Kategori (konstanta di kelas pendukung, bukan tabel master):

| Kode | Label |
| --- | --- |
| `stok_habis` | Stok barang canvassing habis / kurang |
| `kendaraan` | Kendaraan / armada |
| `akses_cuaca` | Akses jalan / cuaca |
| `outlet` | Outlet (tutup, menolak, pindah) |
| `aplikasi` | Aplikasi / HP / GPS |
| `sdm` | Motoris (sakit, izin, kekurangan orang) |
| `lainnya` | Lainnya |

## Aturan

### Hak akses dan cakupan

- Hak per aksi lewat menu Role pada slug `laporan_kendala` (pola `Akses`): **Lihat** membuka daftar, **Tambah** membuat
  laporan, **Ubah** mengubah dan menutup laporan.
- Migrasi memberi role bawaan `koordinator` hak Lihat + Tambah + Ubah. Super Admin otomatis penuh. Role Supervisor
  (atau role lain) diberi hak Lihat oleh admin dari menu Role. Hak Ekspor mengikuti pola menu lain.
- Daftar dibatasi `Akses::batasiKaryawan` terhadap `mk_karyawan` pelapor: cakupan `downline` = laporan sendiri dan
  laporan koordinator di bawahnya, `depo` = laporan pelapor di depo akun, `semua` = semua laporan.
- **Membuat** laporan butuh akun yang punya `karyawan_id` (ditolak dengan pesan bila tidak ada, mis. akun admin teknis).
- **Mengubah dan menutup** hanya boleh dilakukan pelapor sendiri (`user_id` = akun login) dan hanya selama status
  `terbuka`. Laporan Selesai tidak bisa diubah lagi. Supervisor/Admin hanya membaca.

### Dibaca

- Membuka detail laporan oleh akun selain pelapor mencatat/memperbarui `laporan_kendala_dibaca`.
- Bagi pembaca, laporan **Terbuka** yang belum pernah ia buka diberi lencana **Belum dibaca**. Laporan yang diubah
  pelapor setelah dibaca (`updated_at` > `dibaca_at`) diberi lencana **Diperbarui**.
- Bagi pelapor, detail menampilkan siapa saja yang sudah membaca dan kapan.

### Status

| Status | Arti | Bisa diubah | Masuk hitungan Beranda |
| --- | --- | --- | --- |
| `terbuka` | kendala masih berlangsung | ya (oleh pelapor) | ya |
| `selesai` | sudah teratasi, catatan penyelesaian wajib | tidak | tidak |

## Tampilan

- **Halaman Laporan Kendala** (`/laporan_kendala`, halaman Inertia khusus, route dideklarasikan sebelum catch-all):
  `DataTable` berkolom Tanggal kejadian, Kategori (badge), Judul, Pelapor, Depo, Status (badge), Dibaca, Diperbarui.
  Filter status (bawaan: Terbuka), kategori, depo (bila cakupan > downline), rentang tanggal; pencarian judul/uraian/
  pelapor. Tombol **Buat Laporan** bila punya hak Tambah.
- **Form Buat / Ubah** (Modal): kategori, tanggal kejadian (bawaan hari ini), judul, uraian.
- **Detail** (drawer/modal): isi lengkap, status, riwayat dibaca, catatan penyelesaian; tombol **Ubah** dan
  **Tandai Selesai** (form catatan penyelesaian) untuk pelapor bila Terbuka.
- **Beranda Koordinator:** kartu "Laporan Kendala" — jumlah laporan Terbuka tim, 5 terbaru, tombol Buat Laporan.
- **Beranda (cakupan semua/depo):** kartu "Kendala Lapangan" — jumlah Terbuka dan Belum dibaca, 5 terbaru belum dibaca.
- **Sidebar:** angka Belum dibaca pada entri Laporan Kendala untuk akun selain koordinator.

Semua markup memakai komponen `Components/ui/` (Card, DataTable, Modal, Badge, Button, …); varian badge status/kategori
lewat class komponen di `app.css`.

## Keputusan

- Web dulu: koordinator sudah memakai web (Beranda Koordinator). Endpoint aplikasi HP menyusul bila dibutuhkan.
- Laporan tidak dihapus (jejak untuk menjelaskan kinerja depo); salah input ditutup sebagai Selesai dengan catatan.
- `depo_id` dan `pelapor_nama` disimpan saat dibuat supaya laporan lama tetap benar walau koordinator pindah depo.

## Pertanyaan terbuka

- Apakah sudah ada role Supervisor di produksi, dan cakupannya `depo` atau `semua`? (Menentukan hak Lihat bawaan.)
- Apakah lampiran foto bukti (mis. foto rak gudang kosong) dibutuhkan di versi pertama?

## Di luar cakupan

- Tanggapan/komentar Supervisor/Admin pada laporan (alur diskusi).
- Lampiran foto/berkas.
- Notifikasi push/email/WhatsApp.
- Membuat laporan dari aplikasi HP (`/api/mobile/v1`).
- Membuka kembali laporan Selesai; menghapus laporan.
- Mengaitkan laporan otomatis ke angka kinerja depo (Summary All Depo).
