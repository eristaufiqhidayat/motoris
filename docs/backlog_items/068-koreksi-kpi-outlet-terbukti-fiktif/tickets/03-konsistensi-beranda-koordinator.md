# Konsistensi Beranda Koordinator

## Parent

Spec: [docs/backlog_items/068-koreksi-kpi-outlet-terbukti-fiktif/spec.md](../spec.md)

## What to build

`HomeController@detailTim` (Beranda Koordinator, lewat `SummaryDepo::detail`) memakai pengecualian yang sama, dan cache
Beranda terkait dilupakan saat `TandaOutletController@periksa` agar angka langsung menyesuaikan.

## Acceptance criteria

- [ ] Angka tim di Beranda Koordinator sama dengan baris tim yang sama di Summary All Depo.
- [ ] Setelah memeriksa tanda Terbukti, angka tim langsung berubah (cache dilupakan).
- [ ] Feature test `HomeTest`.

## Blocked by

- 01-koreksi-summary-depo.md
