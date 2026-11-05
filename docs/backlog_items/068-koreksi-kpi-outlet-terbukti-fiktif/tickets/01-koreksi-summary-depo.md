# Koreksi Summary Depo

## Parent

Spec: [docs/backlog_items/068-koreksi-kpi-outlet-terbukti-fiktif/spec.md](../spec.md)

## What to build

`SummaryDepo::sales()` mengecualikan kunjungan dan penjualan di outlet terbukti fiktif (`Dashboard::outletTerbuktiFiktif()`),
hari kerja tetap dari absen ∪ kunjungan penuh. Tambahkan `ec_dikoreksi` per depo pada `kartu()` dan `detail()`;
`HomeController::kpi()` menjumlahkannya sebagai total nasional.

## Acceptance criteria

- [ ] Call, EC, SKU, nilai, poin, dan IPT tidak menghitung transaksi di outlet Terbukti; outlet tanpa `outlet_id` tetap dihitung.
- [ ] Hari kerja tidak berubah oleh pengecualian.
- [ ] Tanda yang dicabut (`dicabut_at`) tidak mengeluarkan transaksi.
- [ ] `ec_dikoreksi` tersedia per depo dan nasional.
- [ ] Feature test `SummaryDepoTest` dan `HomeTest` (titik 25 outlet Terbukti mengurangi EC depo, dicabut memulihkan).

## Blocked by

- Tidak ada.
