# Beranda Admin: Penyebaran Produk, 30 Hari vs 30 Hari Sebelumnya

> Riwayat revisi:
> 1. Versi awal: tabel kinerja per depo. Diganti karena yang dibutuhkan adalah angka nasional dengan pembanding.
> 2. Kartu nasional total Call, total EC, IPT 30 hari vs 30 hari sebelumnya.
> 3. Filter tanggal header dihapus; seluruh Beranda admin memakai 30 hari terakhir.
> 4. (Versi ini) Tata letak disusun ulang mengikuti STYLE_GUIDE dan Beranda Koordinator, dan metrik dievaluasi ulang:
>    tujuan tim motoris adalah **penyebaran produk ke sebanyak mungkin outlet**, bukan nilai penjualan.

## Masalah

Beranda admin menampilkan kartu yang tidak memicu keputusan (jumlah depo/SK aktif), angka total tanpa normalisasi,
dan ranking depo teratas, padahal admin perlu tahu (1) apakah produk makin tersebar, (2) apakah aktivitas motoris
cukup, dan (3) di mana masalahnya. Nilai penjualan (Rp) bukan target utama tim motoris.

## Prinsip

- **Penyebaran** adalah hasil yang diukur: outlet yang membeli, outlet baru, outlet yang membeli lagi, kedalaman SKU.
- **Aktivitas** (call, EC, hari kerja) adalah cara mencapainya; ditampilkan sebagai rata-rata per hari kerja vs target,
  dengan total dan perubahan sebagai keterangan (gabungan total + AVG dalam satu kartu).
- **Perlu Perhatian** hanya berisi hal yang bisa langsung ditindaklanjuti, dan bertaut ke menu rinciannya.
- Nilai penjualan (Rp) tidak jadi kartu; tetap terlihat sebagai garis di Tren Harian.

## Periode

- 30 hari terakhir: hari ini − 29 hari s.d. hari ini (zona `Pengaturan::zona`); pembanding: hari ini − 59 s.d. hari
  ini − 30. Tetap, tanpa filter tanggal; `date_from`/`date_to` di URL diabaikan. Rentang lain: Summary All Depo.
- Subjudul PageHeader: periode, pembanding, dan sumber data (aturan PageHeader di STYLE_GUIDE).

## Tata letak

Mengikuti Beranda Koordinator: kelompok `StatCard` dengan judul `h6.judul-bagian`, lalu grafik dalam grid
`lg:grid-cols-12` 7/5; header kartu `<h6 class="mb-0 font-semibold">`.

### Penyebaran Produk (4 kartu)

| Kartu | Nilai | Keterangan / progres | Nada |
| --- | --- | --- | --- |
| Outlet Terjangkau | outlet unik ber-EC (tidak dibatalkan) | ▲/▼ % · % dari outlet terdaftar (batang = % jangkauan) | arah perubahan |
| Outlet Baru (NOO) | outlet didaftarkan lewat aplikasi | ▲/▼ % · nilai sebelumnya | arah perubahan |
| Repeat Order | % outlet terjangkau yang ber-EC di ≥2 tanggal | ▲/▼ poin · jumlah outlet (batang = %) | arah perubahan |
| IPT | SKU ÷ EC | ▲/▼ % · % dari target (batang = capaian) | capaian target |

### Aktivitas Motoris (4 kartu)

| Kartu | Nilai | Keterangan / progres |
| --- | --- | --- |
| Call | AVG per hari kerja | total ▲/▼ · target (batang = capaian) |
| Effective Call | AVG per hari kerja | total ▲/▼ · strike rate EC ÷ Call (batang = capaian) |
| Motoris Aktif | jumlah motoris | depo aktif · SK aktif |
| Hari Kerja | hari kerja per motoris | total hari kerja ▲/▼ |

### Perlu Perhatian (4 kartu, bertaut bila akun punya hak menu tujuan)

| Kartu | Nilai | Tautan |
| --- | --- | --- |
| Laporan Kendala | Terbuka (+ ada balasan baru) | `/laporan_kendala` |
| Outlet Stok Kosong | OSS dari funnel outlet | `/outlet_oss` |
| Depo Tertinggal | depo aktif dengan EC Achievement < 75% | `/summary_all_depo` |
| Motoris Ditandai | motoris dengan tanda Perlu dicek | `/motoris_ditandai` |

### Grafik

- Tren Harian (7/12) dan Funnel Outlet (5/12).
- Depo · Outlet Terjangkau per Motoris, 10 terendah (bar chart): outlet unik ber-EC ÷ motoris yang menjual, per depo
  motoris — supaya depo besar dan kecil sebanding.

## Sumber data

| Data | Sumber |
| --- | --- |
| Call, EC, IPT, hari kerja, achievement, motoris/depo/SK aktif | `SummaryDepo::kartu()` + `HomeController::kpi()` |
| Depo Tertinggal | `ringkasanDepo.depoTertinggal` dari kartu yang sama |
| Outlet terjangkau, repeat order, NOO, outlet terdaftar | `Dashboard::penyebaran()` (baru) |
| Outlet per depo | `Dashboard::outletPerDepo()` (baru) |
| OSS | `Dashboard::funnelOutlet()` |
| Motoris ditandai | `HomeController::tandaMotoris()` (sama dengan Beranda Koordinator) |

Outlet terjangkau/RO dihitung dari `trx_penjualan` EC tidak dibatalkan pada rentang, dibatasi cakupan lewat depo motoris
penjual. Berbeda dengan EC/RO di funnel yang seumur hidup outlet.

## Beban data

- Prop tertunda dalam dua grup: `ringkasan` (kpi, funnel, tren, outletDepo, tandaMotoris) dan `perbandingan`.
- Periode sekarang di `perbandingan` memakai ulang cache `ringkasanDepo` dan `penyebaran`; query tambahan hanya
  30 hari sebelumnya (`beranda:perbandingan:{dari}:{sampai}:{cakupan}`).
- Semua di-cache 5 menit per rentang & cakupan.

## Di luar cakupan

- Kartu nilai penjualan (Rp).
- % EC yang memenuhi Minimum SKU depo (`mk_depo.minimum_sku`): kandidat berikutnya bila kolom itu sudah terisi di
  semua depo.
- Filter tanggal di Beranda admin.

## Pengujian

- Feature test `HomeTest`: periode tetap 30 hari, `depoTertinggal`, `tandaMotoris`, `tautan`, outlet terjangkau / RO
  per periode (EC dibatalkan dan non-EC tidak dihitung), outlet per depo, perbandingan dua periode, cakupan akun depo.
- Vitest: `Selisih` (naik/turun/tetap, mode poin, tanpa pembanding) dan smoke test `Dashboard` (render lengkap, sebelum
  prop tertunda tiba, tautan sesuai hak).

## Revisi 5: tab di Beranda admin

Beranda admin terlalu padat (±20 kartu), jadi isinya dibagi dalam tiga tab (komponen `Tabs`) dengan **Ringkasan** sebagai tab utama (Outlet dan Motoris digabung, begitu juga Koordinator dan Depo, atas keputusan pemilik produk):

| Tab | Isi |
| --- | --- |
| Ringkasan | 8 kartu kunci (Outlet Terjangkau, Repeat Order, Rasio Fiktif, Tindak Lanjut Temuan, Call, Effective Call, Laporan Kendala, Depo Tertinggal) + Tren Harian; kartu menuju tab rincian |
| Outlet & Motoris | Hasil dan aktivitas lapangan. Outlet: Outlet Terjangkau, Outlet Baru, Repeat Order, Outlet Stok Kosong; Kualitas Data Outlet (Terbukti Fiktif, Rasio Fiktif, NOO Ditolak, NOO Menunggu); Funnel Outlet. Motoris: Call, Effective Call, IPT, Motoris Aktif, Hari Kerja, Motoris Ditandai |
| Koordinator & Depo | Depo Tertinggal dan Depo · Outlet per Motoris (10 terendah); Kinerja Koordinator (spec 067): kartu nasional dan tabel Koordinator Perlu Perhatian |

- `?tab=` menentukan tab saat halaman dimuat (bawaan `ringkasan`, nilai tak dikenal diabaikan); pindah tab mengganti URL tanpa
  permintaan baru. Semua data tetap dimuat sekali (tertunda, di-cache 5 menit); tab hanya mengatur tampilan.
- Lencana merah pada nama tab = jumlah kartu bermasalah di tab itu (Outlet & Motoris: ada NOO menunggu verifikasi, motoris ditandai;
  Koordinator & Depo: depo tertinggal, koordinator perlu audit, temuan menunggu lama, NOO menunggu lama).
- Kode: `Dashboard.vue` (kerangka + tab), `partials/Tab*.vue` (TabRingkasan, TabOutlet, TabKoordinator), `partials/metrikBeranda.js` (angka turunan dan nada bersama).
