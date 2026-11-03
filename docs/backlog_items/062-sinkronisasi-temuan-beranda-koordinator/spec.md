# Tanda otomatis untuk dicek koordinator (Sinkronisasi Data di Beranda Koordinator)

## Masalah

Penandaan kecurangan sepenuhnya manual: koordinator harus membuka modal Riwayat tiap motoris tiap hari, menilai sendiri
pola GPS dan kunjungan, lalu memberi Tandai motoris / Tandai outlet. Deteksi otomatis (`AnomaliGps`,
`AnomaliJarakKunjungan`) hanya tampil sebagai info di modal itu dan tidak tersimpan. Koordinator tidak punya daftar
"mana yang harus saya cek dulu".

## Solusi

Penandaan manual **dihapus dan digantikan sistem**. Tombol **Sinkronisasi Data** di Beranda Koordinator menjalankan
deteksi untuk tim koordinator dan menambahkan tanda **"Perlu dicek"**. Tanda adalah saran untuk dicek di lapangan
(atau lewat Google Street View), bukan vonis. Koordinator menutup tiap tanda dengan hasil **Terbukti** atau **Wajar**
beserta catatan wajib.

## Aturan deteksi

### Diduga lokasi / GPS palsu (tanda motoris, per motoris per tanggal)

`AnomaliGps` tingkat `terdeteksi`: HP menandai mock location pada titik GPS atau kunjungan. Ini satu-satunya bukti
langsung dari perangkat. Pola lompatan GPS tanpa penanda HP (`indikasi`) tidak dijadikan tanda.

### Diduga outlet fiktif (tanda outlet, per titik lokasi)

Satu **titik** = outlet-outlet yang didaftarkan orang yang sama (`mk_outlet.dibuat_oleh`) dengan koordinat master
identik (dibulatkan ke 6 desimal, ~0,1 m). Unit pengecekan adalah titik, bukan outlet: satu kunjungan lapangan atau
satu Street View cukup untuk menilai semua outlet di titik itu.

- **Syarat masuk:** titik berisi ≥ 3 outlet (`marketama.tanda_outlet_sistem.min_outlet`), minimal satu outlet di titik
  itu bertransaksi, dan minimal satu outlet di titik itu dikunjungi motoris tim (rute tim).
- **Skor prioritas:** 3 × outlet bertransaksi + 2 × outlet yang transaksinya hanya sekali dan kecil (rata-rata
  < Rp20.000 atau ≤ 1 SKU) + jumlah outlet di titik. Outlet yang bertransaksi diutamakan karena ikut menaikkan EC —
  target EC adalah dorongan terbesar untuk mencatat transaksi di outlet yang tidak ada.
- **Batas antrean:** paling banyak 10 titik berstatus Perlu dicek per tim (`antrean_titik`). Tiap sinkronisasi mengisi
  antrean sampai penuh dengan titik berskor tertinggi berikutnya.
- Setiap outlet di titik mendapat tanda dengan `kelompok` yang sama (hash pendaftar + koordinat); memeriksa satu titik
  menutup semua tanda Perlu dicek di kelompok itu.

Dasar dari data lokal (tim Hendra, 9 motoris): 909 outlet kandidat ternyata hanya 127 titik; kelompok terbesar 43
outlet di satu titik. Outlet berkoordinat identik sama seringnya bertransaksi dengan outlet lain, jadi koordinat
identik sendiri menandakan lokasi master tidak akurat (sales mengisi aplikasi sebagai laporan setelah meninggalkan
toko), bukan otomatis fiktif — karena itu dijadikan saran untuk dicek, dengan transaksi sebagai pengutama.

### Tidak dijadikan tanda

- **Kunjungan berturutan berjarak dekat:** 41% kunjungan tim Hendra masuk runtun berdekatan dengan tingkat transaksi
  sama (69%) — pola kerja normal di daerah padat. Tetap tampil sebagai info di panel Temuan modal Riwayat.
- **Durasi kunjungan:** median 0,7 menit untuk semua kunjungan; sales mengisi aplikasi setelah selesai berinteraksi,
  jadi durasi tidak bermakna.

## Status tanda

| Status | Arti | Ikut kartu Ditandai | Lencana "Ditandai" |
| --- | --- | --- | --- |
| `perlu_dicek` | dibuat sistem | ya | ya |
| `terbukti` | koordinator memastikan ada kecurangan | tidak | ya |
| `wajar` | setelah dicek tidak ada masalah | tidak | tidak (`dicabut_at` diisi) |

Tanda yang sudah ada (status apa pun) tidak dibuat ulang, jadi tanda Wajar tidak muncul lagi. Hasil cek dicatat di
`hasil_catatan`, `dicek_at`, `dicek_oleh`, `dicek_nama`, dan riwayat admin. Hak memeriksa: Tambah pada menu
`tracking_sales` (tanda motoris) / `outlet_all` (tanda outlet), sama dengan hak memberi tanda sebelumnya.

## Sinkronisasi

- `POST /home/sinkronisasi-temuan` (hak `home`, hanya cakupan `downline`), rentang maks. 7 hari, tidak melewati hari ini.
- Tanda motoris: per motoris-hari; motoris-hari yang diperiksa setelah harinya lewat dicatat `lengkap` di
  `pemeriksaan_motoris` dan dilewati berikutnya. Hari ini selalu diperiksa ulang.
- Tanda outlet: dihitung ulang setiap sinkronisasi (tidak bergantung tanggal), mengisi antrean.

## Tampilan

- **Beranda Koordinator, kartu Temuan Sistem:** antrean Perlu dicek, tab Motoris (tanggal, motoris, catatan; buka
  Riwayat, Periksa) dan Titik outlet (pendaftar, jumlah outlet, bertransaksi, skor, catatan; Street View, Peta, Periksa).
- **Modal Riwayat & All Outlet:** form Tandai motoris / Tandai outlet dihapus; daftar tanda menampilkan status dan
  hasil cek, dengan tombol Periksa.
- Kartu Motoris/Outlet Ditandai hanya menghitung Perlu dicek. Rekap Motoris/Outlet Ditandai menampilkan status.

## Presisi koordinat data lama

Uji pertama di data lokal menandai 88 outlet dalam seminggu untuk satu tim. Penyebabnya bukan data Marketama: CSV
Marketama menyimpan koordinat sampai ~14 desimal, tetapi kolom koordinat tabel `lap_*` dibuat `decimal(20, 4)`, jadi
impor memotongnya ke grid ~11 m dan `enesis:migrasi-transaksi` menyalin angka terpotong itu ke `trx_kunjungan` /
`trx_checkin_depo`. Jarak antar kunjungan lama hanya bisa 0, 11, 16, 22 m, ..., sehingga ambang "< 11 m" sama dengan
"satu kotak grid". Perbaikan (commit tersendiri):

- Migrasi `presisi_koordinat_tabel_marketama`: kolom koordinat `lap_*` jadi `decimal(20, 7)`.
- Impor ulang `lap_checkin_outlet` dan `lap_checkin_checkout`, lalu `php artisan enesis:perbarui-koordinat-lama`
  menyalin ulang lat/lng (dan `jarak_m` kunjungan) ke baris `sumber = lama` lewat `sumber_id` — migrasi transaksi
  tidak menimpa baris yang sudah ada.

## Di luar cakupan

- Sinkronisasi otomatis terjadwal.
- Pola NOO dari HP (beberapa NOO didaftarkan tanpa berpindah tempat) — diuji setelah data `trx_outlet_baru` terkumpul.
- Transaksi kecil saat EC harian motoris masih di bawah target.
- Foto bukti hasil cek; memperbarui koordinat outlet dari hasil cek lapangan.
