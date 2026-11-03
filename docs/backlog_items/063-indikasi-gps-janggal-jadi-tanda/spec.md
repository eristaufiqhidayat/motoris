# Indikasi GPS janggal (tanpa mock location) ikut jadi tanda Perlu dicek

> Backlog item: [068](../../BACKLOG.md)
> GitHub issue: [#182](https://github.com/eristaufiqhidayat/enesis/issues/182)
> Lanjutan dari: [062 Sinkronisasi Temuan Beranda Koordinator](../062-sinkronisasi-temuan-beranda-koordinator/spec.md)

## Problem Statement

Tombol **Sinkronisasi Data** di Beranda Koordinator (spec 062, `App\Support\Tracking\SinkronTemuan::lokasiPalsu`) hanya
membuat tanda motoris "Diduga lokasi / GPS palsu" bila `AnomaliGps` bertingkat `terdeteksi`, yaitu HP menandai
`mock_location` pada titik GPS atau kunjungan. Tingkat `indikasi` (lompatan posisi mustahil berulang yang kembali ke
asal, atau banyak titik menyimpang tunggal, tanpa penanda dari HP) sengaja tidak dijadikan tanda.

Akibatnya, koordinator tidak pernah mendapat antrean untuk motoris yang memakai fake GPS yang bisa menyembunyikan penanda
mock (HP di-root, aplikasi penyembunyi mock location), padahal lompatan posisinya jelas terlihat. Seluruh data lama
Marketama (`tracking_sales_lokasi`, tanpa kolom mock) juga tidak pernah bisa memicu tanda. Pola itu hanya terlihat bila
koordinator membuka modal Riwayat motoris satu per satu, hari per hari, yang justru ingin dihindari oleh spec 062.

Alasan indikasi dulu tidak dijadikan tanda tetap berlaku dan harus dijawab oleh solusi ini:

- Pola yang sama muncul di HP jujur yang GPS-nya buruk: sinyal lemah di dalam bangunan atau pasar, lokasi dari menara
  seluler/WiFi yang meleset beberapa km lalu kembali, keluar dari mode hemat daya. `TitikGps` sudah membaca `akurasi_m`,
  tetapi `AnomaliGps` tidak memakainya, jadi titik berakurasi buruk ikut dihitung sebagai lompatan.
- Tanda motoris tidak punya batas antrean (berbeda dengan titik outlet, maks. `antrean_titik` = 10 per tim). Satu
  sinkronisasi 30 hari bisa menghasilkan puluhan tanda per tim yang kebanyakan Wajar.
- Antrean yang penuh tanda salah membuat koordinator terbiasa mengabaikannya, termasuk tanda mock yang kuat.
- Hari yang sudah lewat dan sudah diperiksa dicatat `lengkap` di `pemeriksaan_motoris` dan tidak diperiksa ulang, jadi
  hasil indikasi hari-hari itu tidak tersedia lagi untuk sinkronisasi berikutnya.

## Solution

Indikasi menjadi tanda motoris dengan **kategori terpisah bertingkat lebih rendah**, "Pola GPS janggal", yang dibuat
oleh Sinkronisasi Data yang sama, tetapi dengan tiga penyaring supaya antrean tetap layak dicek:

1. **Saring titik berakurasi buruk** sebelum pola dihitung: titik dengan `akurasi_m` di atas ambang tidak ikut dinilai
   sebagai lompatan atau titik menyimpang. Titik tanpa nilai akurasi (data lama Marketama) tetap dinilai.
2. **Hanya pola yang berulang**: motoris baru ditandai bila indikasinya muncul pada minimal N hari dalam rentang
   sinkronisasi. GPS buruk cenderung acak, sedangkan fake GPS dipakai berulang.
3. **Antrean terbatas per tim, urut skor**: seperti titik outlet, antrean "Pola GPS janggal" Perlu dicek dibatasi per
   tim dan diisi dari skor tertinggi (banyaknya lompatan, lompatan kembali ke asal, titik menyimpang, dan hari indikasi).

Tanda "Diduga lokasi / GPS palsu" dari mock location tidak berubah dan tetap didahulukan: hari yang `terdeteksi` tidak
juga dijadikan "Pola GPS janggal". Di Beranda, baris Pola GPS janggal tampil dengan label tingkat waspada (bukan
bahaya), dan alasannya menjelaskan bahwa ini pola dari data, bukan penanda dari HP.

## User Stories

1. Sebagai koordinator, saya ingin Sinkronisasi Data juga menandai motoris yang pola GPS-nya janggal walaupun HP tidak menandai mock location, supaya fake GPS yang menyembunyikan penanda mock tetap tertangkap.
2. Sebagai koordinator, saya ingin tanda pola GPS janggal punya kategori sendiri ("Pola GPS janggal"), supaya saya bisa membedakannya dari tanda mock location yang buktinya lebih kuat.
3. Sebagai koordinator, saya ingin tanda pola GPS janggal tampil dengan label tingkat yang lebih rendah (waspada) di antrean Temuan Sistem, supaya saya mendahulukan tanda mock location.
4. Sebagai koordinator, saya ingin titik GPS yang akurasinya buruk tidak dihitung sebagai lompatan, supaya motoris yang hanya kehilangan sinyal di dalam bangunan tidak ditandai.
5. Sebagai koordinator, saya ingin motoris baru ditandai bila polanya muncul di beberapa hari dalam rentang sinkronisasi, supaya gangguan GPS sesekali tidak masuk antrean.
6. Sebagai koordinator, saya ingin antrean pola GPS janggal dibatasi per tim, supaya satu sinkronisasi tidak membanjiri antrean.
7. Sebagai koordinator, saya ingin antrean itu diisi dari motoris berskor tertinggi lebih dulu, supaya yang paling mencurigakan saya cek lebih dulu.
8. Sebagai koordinator, saya ingin sinkronisasi berikutnya mengisi slot antrean yang kosong setelah saya menutup tanda sebagai Terbukti / Wajar, sama seperti antrean titik outlet.
9. Sebagai koordinator, saya ingin hari yang sudah terdeteksi mock location tidak sekaligus dijadikan tanda pola GPS janggal, supaya tidak ada dua tanda untuk hal yang sama.
10. Sebagai koordinator, saya ingin alasan tanda menyebut jumlah lompatan, berapa yang kembali ke asal, titik menyimpang, jarak terjauh, dan hari-hari tempat pola muncul, supaya saya tahu apa yang harus dicek di modal Riwayat.
11. Sebagai koordinator, saya ingin membuka Riwayat motoris langsung dari baris tanda pola GPS janggal pada tanggal yang dimaksud, supaya saya bisa melihat garis lompatannya di peta.
12. Sebagai koordinator, saya ingin menutup tanda pola GPS janggal dengan Terbukti / Wajar beserta catatan, seperti tanda lain.
13. Sebagai koordinator, saya ingin tanda pola GPS janggal yang sudah saya nilai Wajar tidak dibuat ulang oleh sinkronisasi berikutnya.
14. Sebagai koordinator, saya ingin hari yang sudah lewat dan sudah diperiksa tetap ikut dihitung untuk syarat "berulang" tanpa harus dianalisis ulang, supaya sinkronisasi tetap cepat.
15. Sebagai koordinator, saya ingin data lama Marketama (tanpa akurasi dan tanpa mock) tetap bisa memicu tanda pola GPS janggal, supaya riwayat lama juga terperiksa.
16. Sebagai koordinator, saya ingin tanda pola GPS janggal hanya untuk motoris tim saya, sama seperti tanda lokasi palsu.
17. Sebagai koordinator, saya ingin pesan hasil sinkronisasi menyebut berapa tanda pola GPS janggal yang baru, terpisah dari tanda lokasi palsu.
18. Sebagai koordinator, saya ingin kartu Motoris Ditandai dan rekap Motoris Ditandai ikut menghitung tanda pola GPS janggal yang aktif.
19. Sebagai admin, saya ingin ambang akurasi, jumlah hari minimum, dan batas antrean diatur di `config/marketama.php`, supaya bisa disetel tanpa mengubah kode.
20. Sebagai koordinator yang melihat modal Riwayat, saya ingin banner indikasi di sana memakai saringan akurasi yang sama dengan sinkronisasi, supaya yang saya lihat konsisten dengan tanda yang dibuat.
21. Sebagai admin, saya ingin hari-hari yang sudah diperiksa sebelum fitur ini ada ikut diperiksa ulang satu kali, supaya pola lama tidak terlewat hanya karena sudah tercatat `lengkap`.

## Implementation Decisions

**Config (`config/marketama.php`)**

- `tanda_motoris` mendapat kategori baru `pola_gps_janggal` => `'Pola GPS janggal'`.
- `anomali_gps.maks_akurasi_m` (usulan bawaan 100): titik dengan `akurasi_m` lebih besar dibuang sebelum lompatan dan
  titik menyimpang dihitung. Titik tanpa akurasi (null) tidak dibuang.
- Blok baru `tanda_motoris_sistem` (sejajar dengan `tanda_outlet_sistem`): `min_hari_indikasi` (usulan 2) dan
  `antrean_indikasi` (usulan 10 per tim).

**`App\Support\Tracking\AnomaliGps`**

- `analisis()` membuang titik berakurasi buruk sebelum menghitung lompatan dan titik menyimpang. Jumlah titik yang
  dibuang ikut dikembalikan (mis. `dibuang_akurasi`) supaya modal Riwayat bisa menyebutnya.
- Hasilnya juga memuat `skor` pola: kombinasi jumlah lompatan, lompatan kembali ke asal, dan titik menyimpang. Bobot
  pastinya diputuskan saat implementasi dan didokumentasikan di komentar kelas.
- Penyaringan berlaku di semua pemakai (`SinkronTemuan`, endpoint locations modal Riwayat), jadi banner dan tanda konsisten.

**`App\Support\Tracking\SinkronTemuan`**

- `lokasiPalsu()` tetap membuat tanda `lokasi_palsu` untuk hari `terdeteksi`, tanpa perubahan.
- Untuk hari `indikasi`, skor hari itu disimpan di `pemeriksaan_motoris` (lihat skema). Setelah semua hari diperiksa,
  langkah baru `polaGpsJanggal()` mengumpulkan per motoris hari-hari indikasi pada rentang (dari kolom yang tersimpan,
  termasuk hari yang dilewati karena `lengkap`). Motoris dengan hari indikasi >= `min_hari_indikasi` menjadi kandidat.
- Antrean: slot = `antrean_indikasi` dikurangi tanda `pola_gps_janggal` Perlu dicek milik tim. Kandidat diurutkan menurut
  skor (jumlah skor hari indikasi), lalu slot diisi dari yang tertinggi. Satu kandidat = satu tanda per motoris-hari
  indikasi yang belum punya tanda kategori itu (status apa pun), sama dengan pola "hanya menambah" spec 062. Hari yang
  sudah punya tanda `lokasi_palsu` tidak ditandai pola GPS janggal.
- Catatan tanda berisi ringkasan pola hari itu (jumlah lompatan, kembali ke asal, titik menyimpang, terjauh) dan daftar
  tanggal indikasi lain dalam rentang.
- `jalankan()` mengembalikan hitungan baru `tanda_pola_gps`. `HomeController::sinkronTemuan` menyebutnya di pesan sukses.

**Skema (migrasi baru)**

- `pemeriksaan_motoris.skor_indikasi` (unsigned int, nullable): null = hari itu bukan indikasi atau belum dianalisis
  dengan aturan ini.
- `tanda_motoris.skor` (unsigned int, default 0): urutan antrean, sejajar `tanda_outlet.skor`.
- Pemeriksaan ulang satu kali: migrasi mengubah `pemeriksaan_motoris.lengkap` menjadi false untuk semua baris lama,
  supaya sinkronisasi berikutnya menganalisis ulang hari-hari itu dan mengisi `skor_indikasi`. Tanda yang sudah ada tidak
  disentuh, dan tanda `lokasi_palsu` tidak dibuat ulang karena aturan "hanya menambah".

**Beranda (`HomeController::temuan`, `Pages/Home/partials/TemuanSistemTim.vue`)**

- Tab Motoris menampilkan tanda `pola_gps_janggal` bersama `lokasi_palsu`. Label kategori memakai class `.temuan-tingkat-waspada`
  untuk pola GPS janggal dan `.temuan-tingkat-bahaya` untuk lokasi palsu. Props baris mendapat `kategori` (kode) supaya
  view bisa memilih class.
- Urutan baris: lokasi palsu lebih dulu, lalu pola GPS janggal urut skor.
- Keterangan di bawah judul menyebut batas antrean pola GPS janggal.

**Tidak berubah**: alur Periksa (Terbukti / Wajar), modal Riwayat sebagai tempat memeriksa, cakupan tim
(`HomeController::motorisCakupan`), dan aturan tanda outlet fiktif.

## Testing Decisions

- Seam utama: HTTP, yaitu `POST /home/sinkronisasi-temuan` lalu membaca hasilnya lewat `GET /home` (`assertInertia`, prop
  `temuan.motoris`) dan pesan flash. Ikuti `tests/Feature/Admin/SinkronTemuanTest.php`. Tes
  `test_indikasi_gps_tanpa_mock_location_tidak_dijadikan_tanda` diganti dengan tes perilaku baru.
- Kasus yang perlu dites:
  - Indikasi di >= `min_hari_indikasi` hari menjadi tanda `pola_gps_janggal` Perlu dicek. Indikasi di satu hari saja tidak.
  - Titik berakurasi di atas ambang tidak memicu indikasi. Titik tanpa akurasi tetap memicu.
  - Hari `terdeteksi` hanya mendapat `lokasi_palsu`, tidak keduanya.
  - Antrean dibatasi `antrean_indikasi`, diisi dari skor tertinggi. Setelah tanda ditutup, sinkronisasi berikutnya mengisi slot kosong.
  - Tanda yang dinilai Wajar tidak dibuat ulang.
  - Hari `lengkap` yang dilewati tetap ikut dihitung untuk syarat berulang (lewat `skor_indikasi` yang tersimpan).
  - Hanya motoris tim yang ditandai.
- `AnomaliGps` boleh dites lewat endpoint locations modal Riwayat yang sudah ada (banner indikasi menyebut titik yang
  dibuang karena akurasi), bukan dengan memanggil kelasnya langsung.
- Vitest: `TemuanSistemTim.spec.js` (baru bila belum ada) memastikan label waspada untuk `pola_gps_janggal` dan bahaya untuk `lokasi_palsu`.

## Out of Scope

- Deteksi fake GPS dengan sumber data baru (sensor, sinyal seluler, root detection di aplikasi HP).
- Mengubah ambang lompatan dan titik menyimpang yang sudah ada di `anomali_gps`.
- Menandai motoris pendaftar outlet fiktif (dibahas terpisah).
- Indikasi jarak dekat antar kunjungan dan durasi kunjungan, yang tetap tidak dijadikan tanda (lihat spec 062).

## Further Notes

- Data titik GPS dan kunjungan adalah data pribadi yang dimuat lewat `marketama:import`, bukan bagian repo. Tes memakai
  data buatan sendiri.
- Nilai bawaan ambang (100 m, 2 hari, 10 antrean) adalah usulan. Sebaiknya disetel setelah melihat sebaran `akurasi_m`
  dan jumlah kandidat pada data asli satu atau dua tim.
- Pemeriksaan ulang satu kali (reset `lengkap`) membuat sinkronisasi pertama setelah deploy lebih lambat, karena semua hari dalam rentang dianalisis ulang.
