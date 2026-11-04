# Prop Kinerja per Depo (backend)

## Parent

Spec: [docs/backlog_items/066-kinerja-per-depo-beranda-admin/spec.md](../spec.md)

## What to build

`HomeController::index` (Beranda admin) mengirim prop tertunda `kinerja` (grup defer `kinerja`, terpisah dari
`ringkasan`) berisi semua baris `SummaryDepo::kartu()` dalam cakupan akun untuk rentang `kinerja_dari`/`kinerja_sampai`
(bawaan 30 hari terakhir, maks. 62 hari), plus prop `kinerjaFilter` (dari, sampai) dan hak tautan Summary All Depo.
Hasil di-cache 5 menit dengan kunci `beranda:kinerja:{dari}:{sampai}:{cakupan}` sebagai array murni.
`rentangFilter` mendapat parameter panjang bawaan (hari) agar pemakai lain tetap 7 hari.

## Acceptance criteria

- [ ] Tanpa query, rentang = hari ini − 29 hari s.d. hari ini; rentang > 62 hari dipotong.
- [ ] Semua depo dalam cakupan dikirim (tidak dibatasi 8), angka sama dengan `SummaryDepo::kartu()` rentang yang sama.
- [ ] Akun cakupan `depo` hanya menerima depo-nya; Beranda koordinator tidak berubah.
- [ ] Rentang KPI/funnel/tren tetap bawaan 7 hari.
- [ ] Feature test di `HomeTest`.

## Blocked by

- Tidak ada.
