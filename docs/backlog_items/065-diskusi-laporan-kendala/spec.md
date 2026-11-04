# Diskusi Laporan Kendala

## Masalah

Laporan Kendala (spec 064) hanya satu arah: koordinator menulis, atasan membaca. Supervisor/Admin tidak bisa bertanya
("stok kapan datang?", "sudah koordinasi dengan gudang pusat?"), memberi arahan, atau menanggapi perkembangan. Semua
tanggapan akhirnya lewat chat/telepon lagi, sehingga jejak penanganan kendala hilang dan laporan hanya jadi tampilan.

## Solusi

Setiap laporan menjadi **utas diskusi** ala GitHub Issues. Di detail laporan ada **linimasa**: uraian laporan sebagai
posting pertama, lalu komentar dari siapa pun yang berhak melihat laporan itu, diselingi **peristiwa** otomatis
(ditandai Selesai, dibuka kembali). Pelapor bisa **membuka kembali** laporan Selesai bila kendala muncul lagi. Peserta
diskusi melihat lencana **Ada balasan baru** sampai membuka laporannya.

## User stories

1. Sebagai Supervisor/Admin, saya membalas laporan kendala koordinator (pertanyaan atau arahan) langsung di laporan.
2. Sebagai Koordinator, saya menjawab tanggapan atasan dan menambah perkembangan sebagai komentar tanpa mengubah isi
   laporan awal.
3. Sebagai peserta diskusi, saya tahu laporan mana yang ada balasan baru sejak terakhir saya buka.
4. Sebagai peserta diskusi, saya membaca urutan kejadian (dibuat, dikomentari, selesai, dibuka kembali) dalam satu
   linimasa.
5. Sebagai Koordinator, saya membuka kembali laporan Selesai bila kendalanya muncul lagi, tanpa membuat laporan baru.
6. Sebagai penulis komentar, saya menyunting atau menghapus komentar saya sendiri bila salah ketik.

## Data

Tabel baru `laporan_kendala_komentar`:

| Kolom | Isi |
| --- | --- |
| `id` | |
| `laporan_kendala_id` | |
| `user_id` | penulis (`users.id`) |
| `penulis_nama` | nama saat menulis (tetap terbaca walau akun berubah) |
| `jenis` | `komentar` \| `selesai` \| `dibuka_kembali` (dua terakhir = peristiwa otomatis) |
| `isi` | text; wajib untuk `komentar`; catatan penyelesaian untuk `selesai`; alasan opsional untuk `dibuka_kembali` |
| `disunting_at` | nullable, terisi bila komentar disunting |
| `timestamps` | |

Perubahan `laporan_kendala`: kolom `aktivitas_at` (timestamp) = waktu aktivitas terakhir (laporan dibuat/diubah,
komentar, selesai, dibuka kembali). Diisi `created_at` untuk laporan lama.

Perubahan `laporan_kendala_dibaca`: `dibaca_at` kini diperbarui setiap kali **akun mana pun** membuka laporan, termasuk
pelapor (sebelumnya pelapor tidak dicatat). Daftar "Sudah dibaca oleh" tetap mengecualikan pelapor.

## Aturan

### Hak akses

- **Berkomentar** = boleh melihat laporan itu (hak **Lihat** `laporan_kendala` + laporan masuk cakupan role lewat
  `Akses::batasiKaryawan`), seperti GitHub: siapa pun yang bisa membaca bisa berdiskusi. Tidak ada aksi hak baru di
  menu Role. Pelapor selalu boleh berkomentar di laporannya sendiri.
- **Sunting / hapus komentar** hanya oleh penulisnya sendiri. Peristiwa otomatis (`selesai`, `dibuka_kembali`) tidak
  bisa disunting/dihapus.
- **Membuka kembali** hanya oleh pelapor (`user_id`) dan butuh hak **Ubah**, sama seperti mengubah/menutup. Laporan
  kembali `terbuka`; `selesai_at` dan `catatan_selesai` dikosongkan, catatan lama tetap tampil di linimasa sebagai
  peristiwa `selesai`.
- Uraian laporan tetap hanya bisa diubah pelapor selama Terbuka (aturan spec 064 tidak berubah).
- Komentar **boleh** ditulis pada laporan Selesai (pertanyaan susulan), tanpa membuka kembali laporan otomatis.

### Linimasa

- Urutan menaik menurut waktu. Posting pertama = uraian laporan (dari `laporan_kendala`), bukan baris komentar.
- Tandai Selesai kini juga menyimpan baris `jenis = selesai` (isi = catatan penyelesaian); kolom `catatan_selesai`
  pada laporan tetap diisi supaya daftar dan Beranda tidak berubah.
- Isi komentar teks biasa (tanpa markdown/HTML), maksimum 2000 karakter, baris baru dipertahankan.

### Lencana "Ada balasan baru"

- Laporan **Ada balasan baru** bagi akun X bila `aktivitas_at` > `dibaca_at` akun X dan X bukan penulis aktivitas
  terakhir. Berlaku untuk pelapor maupun pembaca lain; menggantikan lencana **Diperbarui** spec 064.
- Lencana **Belum dibaca** (belum pernah dibuka) tetap.
- Angka di sidebar dan kartu Beranda = Belum dibaca + Ada balasan baru.

## Tampilan

- **Detail laporan** (Modal diperlebar): kepala (judul, status, kategori, pelapor, depo, tanggal kejadian), lalu
  linimasa: kartu uraian, komentar (inisial, nama, waktu, penanda "disunting"), garis peristiwa (ikon + teks, mis.
  "Budi menandai Selesai" dengan catatan di bawahnya), diakhiri **kotak komentar** (Textarea + tombol
  **Kirim Komentar**). Pelapor melihat **Ubah**, **Tandai Selesai** (Terbuka) atau **Buka Kembali** (Selesai).
- Komentar sendiri: aksi kecil **Sunting** / **Hapus** (dengan konfirmasi).
- **Daftar:** kolom Dibaca diganti **Diskusi** (jumlah komentar); badge **Ada balasan baru**; filter yang sama.
- Semua markup memakai komponen `Components/ui/`; bagian linimasa yang dipakai ≥2 tempat diekstrak; styling lewat
  class komponen di `app.css`.

## Keputusan

- Komentar dan peristiwa satu tabel (`jenis`) supaya linimasa cukup satu query terurut.
- Tanpa hak baru: "bisa lihat = bisa berdiskusi", agar Supervisor yang hanya punya Lihat langsung bisa membalas.
- Tanpa realtime: halaman dimuat ulang saat komentar terkirim; lencana diperbarui saat navigasi.
- Hapus komentar = hapus permanen; jejak penanganan tetap ada lewat peristiwa Selesai/Dibuka kembali.

## Pertanyaan terbuka

- Boleh Supervisor/Admin ikut menutup (Selesai) laporan, atau tetap hanya pelapor?
- Perlu @mention atau notifikasi di luar aplikasi (WhatsApp/email), atau cukup lencana di aplikasi?
- Perlu lampiran foto di komentar (mis. foto stok gudang)?

## Di luar cakupan

- Notifikasi push/email/WhatsApp dan @mention.
- Lampiran foto/berkas, reaksi emoji, markdown.
- Balasan bersarang; linimasa dibuat datar.
- Komentar dari aplikasi HP (`/api/mobile/v1`).
- Menghapus laporan; riwayat versi suntingan komentar.
