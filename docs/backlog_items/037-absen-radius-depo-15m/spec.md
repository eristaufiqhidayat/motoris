# Absen Datang/Pulang: validasi radius Depo 15 meter (bukan area kerja)

## Problem Statement
Klarifikasi product owner 2026-10-30 (lihat `docs/BUSINESS_RULES.md` §3) membatalkan sebagian spec 035:
Absen Datang dan Absen Pulang terjadi **di lokasi Depo**, sehingga validasi GPS semestinya terhadap
lokasi/radius Depo, bukan terhadap area kerja (Master Area/polygon). Keputusan itu belum dikerjakan di
kode — implementasi masih memakai `App\Support\Mobile\WilayahKerja` (area kerja) dari spec 035. Permintaan
lanjutan 2026-11-06: radius absen terhadap Depo dibuat **ketat, maksimal 15 meter** (jauh lebih ketat dari
radius checkin/checkout depo untuk loading barang, yang tetap 100 m — lihat §4 BUSINESS_RULES.md, status
checkin depo sebagai event terpisah dari absen masih belum dikonfirmasi ulang).

## Solution
- Config baru `mobile.absen_radius_depo_m` (env `MOBILE_ABSEN_RADIUS_DEPO_M`, bawaan **15**), terpisah dari
  `mobile.radius_depo_m` (bawaan 100, tetap dipakai `/depot-visits/check-in|check-out`).
- `POST /attendances/check-in`: blok validasi `WilayahKerja::untukAkun()/titikDalam()` (area kerja) diganti
  dengan pencarian depo karyawan (`Pengaturan::depo()`) + `cekRadiusDepo($depo, lat, lng, 15)`. Error
  `no_work_area`/`outside_work_area` diganti `no_depot`/`outside_depot_radius` (sama seperti checkin depo,
  fields `distance_m`, `radius_m`). Jarak disimpan ke kolom baru `trx_absensi.masuk_jarak_m` dan
  dikembalikan sebagai `distance_to_depot_m` di respons.
- `POST /attendances/check-out`: lokasi yang sebelumnya opsional (`aturanLokasi(false)`) kini **wajib**
  (`aturanLokasi()`), dan setelah `tutupHari()` lulus, dicek radius Depo yang sama (15 m). Jarak disimpan
  ke kolom baru `trx_absensi.pulang_jarak_m`, dikembalikan sebagai `distance_to_depot_m`.
- `MobileController::cekRadiusDepo()` menerima parameter `$radius` opsional (null = pakai
  `mobile.radius_depo_m`, dipakai checkin/checkout depo tanpa perubahan perilaku) supaya dipakai ulang oleh
  `AbsensiController` dengan ambang 15 m tanpa duplikasi logika jarak.
- `GET /config` (`ProfilController::config`) menambah `depot.absen_radius_m`; `work_areas` tetap ada (masih
  relevan untuk hal lain di luar absen, lihat §2 BUSINESS_RULES.md) tapi tidak lagi dipakai untuk validasi
  absen.
- Migration `2026_11_06_000001_tambah_jarak_depo_trx_absensi.php`: kolom `masuk_jarak_m`/`pulang_jarak_m`
  (nullable, mengikuti pola `trx_checkin_depo.jarak_m`).
- `docs/api/mobile-v1.yaml` v1.4.0: deskripsi `/attendances/check-in|check-out`, field respons
  `distance_to_depot_m`, field `config.depot.absen_radius_m`, kode error diperbarui.

## Dampak ke enesis-mobile (perlu ditindaklanjuti terpisah)
Layar `AbsenScreen` saat ini (dibangun untuk model area kerja, lihat `claude/menu-absensi-master-area.md`)
menampilkan status "di dalam/luar area kerja" dan peta polygon. Perlu diganti kembali ke tampilan
jarak-ke-depo (mis. "Jarak: X m dari Depo, maksimal 15 m") dengan pesan error berbasis jarak, bukan area —
lihat analisa tampilan terlampir & mockup terbaru di project Enesis. `AbsenScreen` perlu mengirim lokasi
wajib saat Absen Pulang (sebelumnya opsional).

## Belum ditangani / perlu dikonfirmasi ulang
- Toleransi akurasi GPS: 15 m sangat ketat dibanding akurasi GPS HP biasa (`accuracy_m` bisa 5–20 m di
  lapangan terbuka, lebih buruk di dalam/dekat bangunan) — risiko penolakan absen sah akibat akurasi GPS,
  bukan posisi sebenarnya. Perlu dipantau setelah rilis; opsi mitigasi: radius efektif = 15 + accuracy_m,
  atau menaikkan ambang bila keluhan penolakan absen sah meningkat.
- Status checkin/checkout depo sebagai event terpisah dari absen (§4 BUSINESS_RULES.md) masih belum
  dikonfirmasi ulang — spec ini TIDAK mengubah `radius_depo_m`/`/depot-visits/*`.

## Testing
- `tests/Feature/Mobile/AbsenCheckinDepoTest.php`: ditulis ulang — absen diterima dalam radius 15 m (jarak
  tersimpan), ditolak di luar radius 15 m meski dalam radius depo lama 100 m, ditolak jauh dari depo,
  ditolak akun tanpa depo, absen pulang juga dicek radius & wajib lokasi, koordinator dicek radius sama
  (bukan gabungan area tim), checkin depo tetap radius lama dan independen dari absen.
- `tests/Feature/Mobile/HariKerjaSalesTest.php`, `AuthMobileTest.php`, `KoordinatorMobileTest.php`: titik
  lokasi di `diDepo()` (±15,6 m, sengaja di luar radius 15 m baru) diganti `diDepoDekat()` (±7,8 m) pada
  panggilan `attendances/check-in|check-out` yang mengharapkan sukses.
