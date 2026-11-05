# Prop Perbandingan Kinerja Nasional (backend)

## Parent

Spec: [docs/backlog_items/066-kinerja-per-depo-beranda-admin/spec.md](../spec.md)

## What to build

`HomeController::index` (Beranda admin) mengirim prop tertunda `perbandingan` (grup defer `perbandingan`) berisi
`sekarang` dan `sebelumnya`, masing-masing `{ dari, sampai, call, ec, ipt }`: 30 hari terakhir dan 30 hari sebelumnya,
dihitung dari `SummaryDepo::kartu()` + `kpi()`. Di-cache 5 menit per tanggal & cakupan.

## Acceptance criteria

- [x] Rentang: hari ini − 29 s.d. hari ini, dan hari ini − 59 s.d. hari ini − 30.
- [x] Call & EC = total periode; IPT = SKU ÷ EC periode itu.
- [x] Akun cakupan `depo` hanya menghitung depo-nya; Beranda koordinator tidak berubah.
- [x] Feature test di `HomeTest`.

## Blocked by

- Tidak ada.
