# Kualitas Data Outlet dan Kinerja Koordinator di Beranda Admin

## Masalah

Beranda admin (spec 066) baru mengukur kinerja **motoris** (penyebaran produk dan aktivitas). Dua hal belum terlihat
oleh manajemen:

1. **Kualitas data outlet.** NOO yang didaftarkan motoris dihitung sebagai keberhasilan walau belum diverifikasi, dan
   outlet yang terbukti fiktif tetap ikut di Outlet Terjangkau. Manajemen tidak bisa melihat apakah masalah outlet
   fiktif membaik atau memburuk.
2. **Kinerja koordinator.** Koordinator membina tim dan mengendalikan kualitas data, tapi tidak ada ukuran apakah peran
   itu dijalankan.

Selain itu, temuan sistem (tanda Perlu dicek) **hanya dibuat saat koordinator menekan Sinkronisasi Data** di Beranda
Koordinator. Koordinator yang tidak pernah menekannya tidak punya temuan, sehingga terlihat sempurna di metrik apa pun
yang berbasis temuan.

## Solusi

1. **Sinkronisasi temuan otomatis setiap malam** untuk semua tim, dengan aturan yang sama dengan tombol manual.
2. Bagian **Kualitas Data Outlet** di Beranda admin: outlet terbukti fiktif, rasio fiktif, NOO ditolak, NOO menunggu.
   NOO dan Outlet Terjangkau yang sudah ada dikoreksi.
3. Bagian **Kinerja Koordinator** di Beranda admin: perbandingan temuan sistem dengan tindak lanjut koordinator, hasil
   tim, dan pembinaan (join visit), plus tabel koordinator yang perlu perhatian.

## User stories

1. Sebagai Manajemen, saya melihat jumlah dan rasio outlet terbukti fiktif 30 hari terakhir, dan apakah membaik.
2. Sebagai Manajemen, saya melihat NOO yang ditolak dan yang masih menunggu verifikasi.
3. Sebagai Manajemen, saya tahu angka penyebaran tidak digelembungkan outlet fiktif atau NOO yang belum diverifikasi.
4. Sebagai Manajemen, saya melihat koordinator mana yang menindaklanjuti temuan sistem, seberapa cepat, dan siapa yang
   perlu diaudit.
5. Sebagai Manajemen, saya melihat hasil tim dan pembinaan (join visit) tiap koordinator.
6. Sebagai Koordinator, temuan tim saya tetap muncul walau saya belum menekan Sinkronisasi Data.

## 1. Sinkronisasi temuan otomatis

- Command baru `php artisan temuan:sinkron [--tanggal=Y-m-d]`, dijadwalkan `dailyAt('01:00')` di `routes/console.php`
  (`withoutOverlapping`). Bawaan: **kemarin** (zona `Pengaturan::zona`), supaya data harinya sudah lengkap.
- Per **tim koordinator aktif**: motoris aktif bawahan koordinator itu (`mk_karyawan.koordinator_id`), lalu
  `SinkronTemuan::jalankan($motoris, $tanggal, $tanggal, null)`. Motoris tanpa koordinator dijalankan sebagai satu
  kelompok tersendiri supaya tetap diperiksa.
- Aturan tidak berubah: hanya menambah, tanda yang sudah ada tidak dibuat ulang, motoris-hari yang sudah lengkap
  dilewati, antrean titik outlet tetap maks. `antrean_titik` (10) per tim.
- Tombol Sinkronisasi Data manual tetap ada (untuk hari ini dan rentang lain).
- Ringkasan hasil per tim ditulis ke log; satu tim gagal tidak menghentikan tim lain.

## 2. Kualitas Data Outlet

Bagian baru di Beranda admin, di bawah Penyebaran Produk. Periode dan pembanding sama dengan spec 066.

| Kartu | Nilai | Keterangan | Arah baik |
| --- | --- | --- | --- |
| Outlet Terbukti Fiktif | tanda outlet sistem yang ditutup **Terbukti** dengan `dicek_at` dalam periode | ▲/▼ % | turun |
| Rasio Fiktif | Terbukti ÷ (Terbukti + Wajar) yang diperiksa dalam periode | ▲/▼ poin | turun |
| NOO Ditolak | ditolak ÷ (disetujui + ditolak) dengan `diverifikasi_pada` dalam periode | ▲/▼ poin | turun |
| NOO Menunggu | `status = menunggu` saat ini | umur antrean tertua (hari) | — (Perlu Perhatian) |

Rasio Fiktif yang dibaca sebagai indikator utama, bukan jumlahnya: jumlah ikut naik bila koordinator makin rajin
memeriksa.

**Koreksi metrik spec 066:**

- **Outlet Baru (NOO)** = NOO berstatus **disetujui** yang didaftarkan dalam periode. Keterangan: jumlah yang masih
  menunggu.
- **Outlet Terjangkau** tidak menghitung outlet dengan tanda outlet **Terbukti** (status saat ini, bukan dicabut).
  Berlaku juga untuk Repeat Order dan Depo · Outlet per Motoris.

**Selisih terbalik:** partial `Selisih` mendapat prop `turunBaik`; bila aktif, turun = hijau dan naik = merah. Nada
`StatCard` mengikuti.

## 3. Kinerja Koordinator

### Metrik per koordinator (30 hari terakhir)

Tim = motoris aktif dengan `koordinator_id` = koordinator itu.

| Metrik | Definisi | Sumber |
| --- | --- | --- |
| Tingkat tindak lanjut | % temuan sistem tim yang dibuat dalam periode dan sudah diperiksa (Terbukti/Wajar) | `tanda_motoris`, `tanda_outlet` milik tim (`SinkronTemuan::tandaOutletTim`) |
| Waktu tindak lanjut | median hari dari `created_at` ke `dicek_at`; jumlah Perlu dicek > 7 hari | idem |
| Rasio Terbukti | Terbukti ÷ diperiksa, dibandingkan rasio nasional | idem |
| Outlet per motoris | outlet terjangkau tim ÷ motoris tim yang menjual (definisi spec 066, tanpa outlet terbukti fiktif) | `trx_penjualan` |
| Cakupan join visit | % motoris tim yang di-join visit koordinator minimal sekali dalam periode | `trx_join_visit` |
| Join visit sah | % join visit tanpa `mock_location`, dengan `selesai_at` terisi, dan `jarak_m` ≤ 200 m | `trx_join_visit` |

**Rasio Terbukti adalah penanda audit, bukan nilai.** Rendah bisa berarti tim bersih atau koordinator menutup semuanya
sebagai Wajar. Koordinator dengan minimal 5 temuan diperiksa dan rasio Terbukti < separuh rasio nasional ditandai
**Perlu audit**.

### Tampilan

Bagian **Kinerja Koordinator** di Beranda admin, setelah Kualitas Data Outlet:

- 4 `StatCard` nasional: Tindak Lanjut Temuan (% + median hari), Temuan Menunggu > 7 Hari, Cakupan Join Visit,
  Koordinator Perlu Audit (jumlah). Dengan ▲/▼ vs 30 hari sebelumnya bila ada pembanding.
- `DataTable` **Koordinator Perlu Perhatian** (10 baris, tanpa pagination): koordinator, depo, motoris, outlet per
  motoris, tindak lanjut %, median hari, Perlu dicek > 7 hari, rasio Terbukti (lencana Perlu audit), cakupan join
  visit. Urut: tindak lanjut terendah, lalu cakupan join visit terendah. Kolom bisa diurutkan di klien.

## Beban data

- Command malam: satu hari × semua motoris; berjalan di luar jam kerja.
- Beranda: metrik koordinator dihitung dengan agregasi per `koordinator_id` (beberapa query GROUP BY, bukan per
  koordinator), prop tertunda grup sendiri (`koordinator`), cache 5 menit seperti bagian lain.
- Kualitas data outlet: query kecil atas `tanda_outlet` dan `trx_outlet_baru`, ikut grup `perbandingan`.

## Aturan

- Hanya Beranda admin (cakupan `semua`/`depo`); akun cakupan `depo` hanya melihat koordinator dan outlet di depo-nya.
- Beranda Koordinator tidak berubah, kecuali temuan timnya kini juga terisi oleh sinkronisasi malam.
- NOO di funnel/tren Beranda admin hanya yang disetujui; Beranda Koordinator tetap menghitung semua pendaftaran.

## 4. Verifikasi NOO oleh koordinator (keputusan: koordinator, bukan admin)

NOO yang didaftarkan motoris diverifikasi (disetujui/ditolak) oleh **koordinator motoris itu**; admin hanya memantau.

- Role bawaan Koordinator mendapat hak **Ubah** pada `outlet_all` (migrasi untuk role yang sudah ada + `RoleBawaan` untuk
  instalasi baru). Cakupan tim dijaga `OutletBaruController` (`Akses::batasiKaryawan`): koordinator hanya bisa
  memverifikasi pendaftaran motoris timnya.
- **Super Admin tidak memverifikasi**: setujui/tolak ditolak 403 dan tombol Setujui/Tolak tidak tampil di tab Outlet Baru
  dari HP (seperti pembatasan membuat Laporan Kendala).
- **Metrik kecepatan verifikasi** per koordinator: median hari dari pendaftaran sampai disetujui/ditolak (verifikasi
  dalam periode) dan jumlah pendaftaran yang menunggu lebih dari `noo_menunggu_hari` (3) hari. Kartu **Verifikasi NOO**
  dan kolom di tabel Koordinator Perlu Perhatian.

## Pertanyaan terbuka

- **Ambang** Perlu audit (5 temuan, separuh rasio nasional), join visit sah (200 m), dan NOO menunggu lama (3 hari) perlu
  dikonfirmasi; disimpan di `config/marketama.php` (`kinerja_koordinator`) agar mudah diubah.

## Di luar cakupan

- Pemeriksaan lapangan (mencocokkan tindak lanjut dengan join visit/tracking koordinator di outlet yang diperiksa).
- Kehadiran koordinator (`absensi_koordinator` masih data impor Marketama).
- Laporan kendala per koordinator.

## Pengujian

- Command: menjalankan sinkronisasi per tim untuk kemarin, motoris tanpa koordinator ikut diperiksa, tanda tidak
  dibuat ganda bila dijalankan dua kali, terdaftar di jadwal.
- `HomeTest`: NOO hanya disetujui, outlet terbukti fiktif tidak dihitung di Outlet Terjangkau, kartu kualitas data
  outlet per periode, metrik koordinator (tindak lanjut, median, rasio + Perlu audit, cakupan & keabsahan join visit),
  cakupan akun depo.
- Vitest: `Selisih` dengan `turunBaik`, tabel Koordinator Perlu Perhatian.
